#!/usr/bin/env python3
"""
Buy-tuning APPLY — voert een veilig futureprice-voorstel ECHT door, achter de gate-keten.
DRY-RUN is default; alleen met --apply muteert het.

Verschil met sell_apply: buy-drempels (futureprice b_min) zitten op RULE-niveau (brain.rules), niet
op coin-niveau (coin_strategies). Een b_min-wijziging raakt ALLE coins tegelijk. Daarom:
  - het beste voorstel per RULE (niet per coin) — de waarde moet SAFE of NEUTRAL zijn voor elke coin
  - refire ALLE coins na een wijziging
  - gate 3 checkt het GECOMBINEERDE resultaat (alle coins samen)
Elke wijziging logt in rules_history (source='buy-tuning-routine').

Usage: buy_apply.py [--apply]      (default: dry-run, toont wat het ZOU doen)
"""
import glob
import json
import os
import subprocess
import sys
from collections import defaultdict

from db import brain
import rules_history

HERE = os.path.dirname(os.path.abspath(__file__))
PY = sys.executable
COINS = [2525, 244]
APPLY = "--apply" in sys.argv


def latest_report():
    files = sorted(glob.glob(os.path.join(HERE, "out/opt/buy_tuning_*.json")))
    if not files:
        raise SystemExit("Geen buy_tuning-rapport gevonden — draai eerst buy_tuning.py.")
    with open(files[-1]) as f:
        return json.load(f), os.path.basename(files[-1])


def manual_count(conn, sym):
    with conn.cursor() as c:
        c.execute("SELECT COUNT(*) n FROM coin_moment_labels WHERE trading_symbol_id=%s "
                  "AND source='manual' AND manual_set_at IS NOT NULL", (sym,))
        return c.fetchone()["n"]


def coin_totals(conn, sym):
    with conn.cursor() as c:
        c.execute("SELECT SUM(profit_loss) sig, SUM(profit_loss<0) verlies, COUNT(*) n "
                  "FROM coin_fires WHERE trading_symbol_id=%s AND is_executed=1 AND profit_loss IS NOT NULL",
                  (sym,))
        r = c.fetchone()
    return float(r["sig"] or 0), int(r["verlies"] or 0), int(r["n"] or 0)


def combined_totals(conn):
    sig = ver = n = 0
    for sym in COINS:
        s, v, c = coin_totals(conn, sym)
        sig += s
        ver += v
        n += c
    return round(sig, 2), ver, n


def read_bmin(conn, rule):
    with conn.cursor() as c:
        c.execute("SELECT b_min FROM rules WHERE rule_number=%s AND subrulename='futureprice' AND active=1",
                  (rule,))
        r = c.fetchone()
    return float(r["b_min"]) if r else None


def write_bmin(conn, rule, value):
    with conn.cursor() as c:
        c.execute("UPDATE rules SET b_min=%s, updated_at=NOW() "
                  "WHERE rule_number=%s AND subrulename='futureprice' AND active=1",
                  (value, rule))
    conn.commit()


def refire_all(reason):
    for sym in COINS:
        env = dict(os.environ, CHANGELOG_REASON=reason[:80])
        r = subprocess.run([PY, os.path.join(HERE, "persist_to_brain.py"), str(sym)],
                           cwd=HERE, capture_output=True, text=True, env=env)
        if r.returncode != 0:
            raise SystemExit(f"persist {sym} faalde:\n{r.stderr[-1500:]}")


def best_safe_per_rule(report):
    """Per rule: de b_min-waarde die SAFE is voor minstens één coin en nergens WORSE. Bij gelijk
    delta_sigma kiest tie-break de zachtste aanpassing (dichtst bij de huidige waarde)."""
    by_rule_val = defaultdict(list)
    for p in report["proposals"]:
        by_rule_val[(p["rule"], p["to"])].append(p)

    best = {}
    for (rule, val), props in by_rule_val.items():
        if any(p["verdict"] == "WORSE" for p in props):
            continue
        if not any(p["verdict"] == "SAFE" for p in props):
            continue
        total_delta = sum(p["delta_sigma"] for p in props)
        total_verlies = sum(p["delta_verliezers"] for p in props)
        cur = props[0]["from"]
        candidate = {"rule": rule, "to": val, "from": cur,
                     "delta_sigma": round(total_delta, 1), "delta_verliezers": total_verlies,
                     "coins_safe": [p["coin"] for p in props if p["verdict"] == "SAFE"],
                     "coins_neutral": [p["coin"] for p in props if p["verdict"] == "NEUTRAL"]}
        prev = best.get(rule)
        if prev is None or total_delta > prev["delta_sigma"] \
                or (total_delta == prev["delta_sigma"] and abs(val - cur) < abs(prev["to"] - cur)):
            best[rule] = candidate
    return best


def apply_safe(emit, apply=False, report=None, conn=None):
    if report is None:
        report, _ = latest_report()
    best = best_safe_per_rule(report)
    if not best:
        emit("Geen veilige koop-drempels om aan te passen.", "info", None, None)
        return "niets toe te passen"

    own = conn is None
    conn = conn or brain()
    man0 = {sym: manual_count(conn, sym) for sym in COINS}
    applied, rejected = [], []

    for rule, p in sorted(best.items()):
        reason = f"buy-tuning-r{rule}-bmin"
        head = (f"rule {rule}: futureprice b_min {p['from']}→{p['to']} "
                f"(gecombineerd ΔΣ {p['delta_sigma']:+.1f}%, Δverlies {p['delta_verliezers']:+d}, "
                f"SAFE voor coin(s) {p['coins_safe']})")
        if not apply:
            emit(f"{head} — VOORSTEL, niet toegepast.", "finding", rule, {"proposal": p})
            continue

        base_sig, base_ver, _ = combined_totals(conn)
        prev_bmin = read_bmin(conn, rule)
        write_bmin(conn, rule, p["to"])
        refire_all(reason)

        for sym in COINS:
            if manual_count(conn, sym) != man0[sym]:
                raise SystemExit("FATALE FOUT: aantal handmatige overrides veranderde door de refire — afgebroken.")

        new_sig, new_ver, _ = combined_totals(conn)

        if new_sig >= base_sig - 1e-6 and new_ver <= base_ver:
            rules_history.record(
                {rule: f"buy-tuning: futureprice b_min {prev_bmin}→{p['to']} (ΔΣ {p['delta_sigma']:+.1f}%)"},
                source="buy-tuning-routine", author="routine")
            emit(f"{head} → TOEGEPAST. Σprofit {base_sig:+.1f}%→{new_sig:+.1f}%, "
                 f"verliezers {base_ver}→{new_ver}.", "change", rule,
                 {"proposal": p, "sigma": [base_sig, new_sig], "verliezers": [base_ver, new_ver]})
            applied.append((rule, p, base_sig, new_sig, base_ver, new_ver))
        else:
            write_bmin(conn, rule, prev_bmin)
            refire_all(f"{reason}-revert")
            why = "Σprofit zou dalen" if new_sig < base_sig - 1e-6 else "verliezers zouden stijgen"
            emit(f"{head} → AFGEWEZEN op echte herreken-poort ({why}): Σprofit {base_sig:+.1f}%→{new_sig:+.1f}%, "
                 f"verliezers {base_ver}→{new_ver}. Teruggedraaid.", "info", rule, {"proposal": p})
            rejected.append((rule, p))

    if own:
        conn.close()
    if not apply:
        return f"{len(best)} voorstellen (propose-only)"
    return f"{len(applied)} toegepast, {len(rejected)} teruggedraaid"


def run():
    mode = "APPLY (muteert)" if APPLY else "DRY-RUN (muteert niets — gebruik --apply)"
    print("=" * 80)
    print(f"BUY-APPLY — {mode}")
    print("=" * 80)
    summary = apply_safe(lambda m, level="change", rule=None, data=None: print(f"[{level}] {m}"), apply=APPLY)
    print("\n" + "-" * 80)
    print(f"KLAAR — {summary}")


if __name__ == "__main__":
    run()
