#!/usr/bin/env python3
"""
sell_default_apply.py — Gated apply van de gepoolde sell-default (Epic N, Feature 3).
DRY-RUN is default; alleen met --apply muteert het.

Leest het laatste sell_default_*.json rapport (van sell_default_sweep.py) en past de
GLOBAAL_SAFE-winnaars toe op de gedeelde default-laag (strategies.sl_settings). Per winnaar:

  GATE 1  Optimistic lock: de huidige default-waarde moet gelijk zijn aan het rapport's "from".
  GATE 2  Whitelist: alleen bekende instelknop-namen mogen worden geschreven.
  GATE 3  Toeval-toets: het rapport moet perm_p + perm_p_corr bevatten (Feature 2 al doorstaan).

Na het schrijven van alle winnaars → één volledige refire over ALLE munten (de default raakt
iedereen zonder override).

  GATE 4  Post-refire portfolio-gate: Σprofit (regime-gefilterd) niet lager dan vóór → anders
          automatische rollback. coin_strategies moeten byte-identiek blijven.

Audit: elke wijziging logt een regel in strategies_changelog (zelfde stijl als coin_fires_changelog).

Usage: sell_default_apply.py [--apply]      (default: dry-run, toont wat het ZOU doen)
"""
import glob
import json
import os
import subprocess
import sys

from db import brain
from sell_lock import parse_sl
from coins import active_coin_ids
import regime

HERE = os.path.dirname(os.path.abspath(__file__))
PY = sys.executable
APPLY = "--apply" in sys.argv
ALLOWED_KNOBS = {"hp_setting6", "hp_setting7", "min_sl1", "minimal_profit"}


def latest_report():
    files = sorted(glob.glob(os.path.join(HERE, "out/opt/sell_default_*.json")))
    if not files:
        raise SystemExit("Geen sell_default-rapport gevonden — draai eerst sell_default_sweep.py.")
    with open(files[-1]) as f:
        return json.load(f), os.path.basename(files[-1])


def read_strategies(conn):
    """Huidige strategies: {rule_number: sl_settings JSON string}."""
    with conn.cursor() as c:
        c.execute("SELECT rule_number, sl_settings FROM strategies")
        return {int(r["rule_number"]): r["sl_settings"] for r in c.fetchall()}


def read_coin_strategies_snapshot(conn):
    """Snapshot van alle coin_strategies-rijen (voor byte-identiek-controle)."""
    with conn.cursor() as c:
        c.execute("SELECT trading_symbol_id, rule_number, sl_settings "
                  "FROM coin_strategies ORDER BY trading_symbol_id, rule_number")
        return [(r["trading_symbol_id"], r["rule_number"], r["sl_settings"])
                for r in c.fetchall()]


def portfolio_totals(conn):
    """(Σprofit, #verliezers) over alle executed trades, regime-gefilterd."""
    clause = regime.active_sql_clause()
    with conn.cursor() as c:
        c.execute("SELECT COALESCE(SUM(profit_loss),0) sig, "
                  "SUM(profit_loss<0) verlies "
                  "FROM coin_fires WHERE is_executed=1 AND profit_loss IS NOT NULL "
                  f"AND {clause}")
        r = c.fetchone()
    return float(r["sig"]), int(r["verlies"])


def write_strategy_knob(conn, rule, json_knob, value):
    """Lees de HUIDIGE strategies.sl_settings, merge {json_knob: value} erin en update.
    Leest altijd live uit de DB (niet een snapshot) zodat meerdere knop-wijzigingen op
    dezelfde rule correct stapelen."""
    with conn.cursor() as c:
        c.execute("SELECT sl_settings FROM strategies WHERE rule_number=%s", (rule,))
        row = c.fetchone()
    cur_json = row["sl_settings"] if row else None
    j = json.loads(cur_json) if cur_json else {}
    j[json_knob] = str(value)
    new_json = json.dumps(j)
    with conn.cursor() as c:
        c.execute("UPDATE strategies SET sl_settings=%s, updated_at=NOW() "
                  "WHERE rule_number=%s", (new_json, rule))
    conn.commit()
    return new_json


def restore_strategy(conn, rule, old_json):
    """Zet de strategy exact terug naar de oude JSON."""
    with conn.cursor() as c:
        c.execute("UPDATE strategies SET sl_settings=%s, updated_at=NOW() "
                  "WHERE rule_number=%s", (old_json, rule))
    conn.commit()


def log_change(conn, rule, json_knob, old_val, new_val, reason):
    """Eenvoudige changelog-rij voor strategies-wijzigingen (coin_fires_changelog-stijl)."""
    with conn.cursor() as c:
        c.execute("INSERT INTO coin_fires_changelog "
                  "(trading_symbol_id, symbol, datetime, field, old_value, new_value, "
                  "reason, created_at, updated_at) "
                  "VALUES (%s,%s,NOW(),%s,%s,%s,%s,NOW(),NOW())",
                  (0, f"strategies-r{rule}", json_knob,
                   str(old_val), str(new_val), reason[:80]))
    conn.commit()


def refire_all(coins, reason):
    """Volledige refire over alle munten (serieel — de default raakt iedereen)."""
    env = dict(os.environ, CHANGELOG_REASON=reason[:80])
    for sym in coins:
        r = subprocess.run([PY, os.path.join(HERE, "persist_to_brain.py"), str(sym)],
                           cwd=HERE, capture_output=True, text=True, env=env)
        if r.returncode != 0:
            raise SystemExit(f"persist {sym} faalde:\n{r.stderr[-1500:]}")


def run():
    report, fname = latest_report()
    winners = report.get("winners", {})
    if not winners:
        print("Geen winnaars in het rapport — niets toe te passen.")
        return

    mode = "APPLY (muteert)" if APPLY else "DRY-RUN (muteert niets — gebruik --apply)"
    print("=" * 80)
    print(f"SELL-DEFAULT APPLY — {mode}")
    print(f"Rapport: {fname}")
    print("=" * 80)

    conn = brain()
    coins = active_coin_ids()
    strat_before = read_strategies(conn)
    cs_before = read_coin_strategies_snapshot(conn)

    accepted, rejected = [], []

    for bk in sorted(winners):
        w = winners[bk]
        rule, jk = w["rule"], w["knob"]
        from_val, to_val = w["from"], w["to"]
        head = f"rule {rule} {jk} {from_val}→{to_val} (breedte holdout={w['holdout']['score']:+d})"

        # GATE 2: whitelist
        if jk not in ALLOWED_KNOBS:
            print(f"  {head} → GEWEIGERD: onbekende knop {jk!r}")
            rejected.append(w)
            continue

        # GATE 3: toeval-toets moet al doorstaan zijn
        if "perm_p_corr" not in w:
            print(f"  {head} → GEWEIGERD: geen bevestigde toeval-toets in rapport")
            rejected.append(w)
            continue

        # GATE 1: optimistic lock — huidige default moet gelijk zijn aan rapport's "from"
        cur_json = strat_before.get(rule)
        if cur_json is None:
            print(f"  {head} → GEWEIGERD: rule {rule} ontbreekt in strategies")
            rejected.append(w)
            continue
        cur_parsed = parse_sl(cur_json)
        # JSON_KEY reverse: hp_setting6 → hp6
        rev = {"hp_setting6": "hp6", "hp_setting7": "hp7",
               "min_sl1": "min_sl1", "minimal_profit": "minimal_profit"}
        short = rev.get(jk, jk)
        cur_val = cur_parsed.get(short)
        if cur_val is None or abs(cur_val - float(from_val)) > 1e-9:
            print(f"  {head} → OVERGESLAGEN: live-waarde {cur_val} ≠ rapport-from {from_val}")
            rejected.append(w)
            continue

        if not APPLY:
            perm = f" p_corr={w['perm_p_corr']}" if "perm_p_corr" in w else ""
            print(f"  {head}{perm} — VOORSTEL (dry-run)")
            continue

        # Schrijf
        write_strategy_knob(conn, rule, jk, to_val)
        log_change(conn, rule, jk, from_val, to_val,
                   f"sell-default-sweep-{jk}")
        accepted.append(w)
        print(f"  {head} → GESCHREVEN")

    if not APPLY:
        print(f"\n{len(winners)} voorstellen (dry-run). Gebruik --apply om door te voeren.")
        conn.close()
        return

    if not accepted:
        print("\nNiets geschreven — klaar.")
        conn.close()
        return

    # GATE 4: refire + portfolio-gate
    print(f"\nRefire over {len(coins)} munten...")
    sig_before, verl_before = portfolio_totals(conn)
    print(f"  Portfolio vóór: Σ{sig_before:+.1f}%, {verl_before} verliezers")

    try:
        refire_all(coins, "sell-default-apply")
    except SystemExit as e:
        print(f"\n  REFIRE FAALDE: {e}")
        print("  Terugdraaien naar oude defaults...")
        for w in accepted:
            old_json = strat_before[w["rule"]]
            restore_strategy(conn, w["rule"], old_json)
        print("  Defaults hersteld. Herrefire...")
        try:
            refire_all(coins, "sell-default-apply-revert")
        except SystemExit:
            pass
        conn.close()
        raise

    sig_after, verl_after = portfolio_totals(conn)
    print(f"  Portfolio ná:   Σ{sig_after:+.1f}%, {verl_after} verliezers")

    # coin_strategies byte-identiek?
    cs_after = read_coin_strategies_snapshot(conn)
    if cs_after != cs_before:
        print("  FATAAL: coin_strategies gewijzigd door de refire — terugdraaien!")
        for w in accepted:
            restore_strategy(conn, w["rule"], strat_before[w["rule"]])
        refire_all(coins, "sell-default-apply-revert")
        conn.close()
        raise SystemExit("coin_strategies-integriteit geschonden")

    # Portfolio-gate: Σprofit mag niet dalen; verliezers mogen max +1% stijgen
    # (een strakkere stop-bodem sluit marginale trades als klein verlies — dat is acceptabel
    # zolang de winst omhoog gaat en de stijging beperkt is)
    VERL_MARGE = 1.01
    sig_daalt = sig_after < sig_before - 1e-6
    verl_stijgt_teveel = verl_after > verl_before * VERL_MARGE
    if sig_daalt or verl_stijgt_teveel:
        why = ("Σprofit daalt" if sig_daalt
               else f"verliezers stijgen >{(VERL_MARGE-1)*100:.0f}%")
        print(f"  AFGEWEZEN op portfolio-gate ({why}): "
              f"Σ{sig_before:+.1f}→{sig_after:+.1f}%, "
              f"verliezers {verl_before}→{verl_after}. Terugdraaien...")
        for w in accepted:
            restore_strategy(conn, w["rule"], strat_before[w["rule"]])
        refire_all(coins, "sell-default-apply-revert")
        print("  Teruggedraaid.")
        conn.close()
        return

    print(f"\n{'='*80}")
    print(f"KLAAR — {len(accepted)} default-wijzigingen toegepast, "
          f"{len(rejected)} afgewezen.")
    print(f"Portfolio: Σ{sig_before:+.1f}%→{sig_after:+.1f}%, "
          f"verliezers {verl_before}→{verl_after}")
    conn.close()


if __name__ == "__main__":
    run()
