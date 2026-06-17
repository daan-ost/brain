#!/usr/bin/env python3
"""
Sell-discovery APPLY — voert veilige rule-101 parameter-wijzigingen door.

Leest het sell_discovery-rapport, past het beste SAFE voorstel toe door de rules-tabel te updaten,
refired alle coins, en checkt de gecombineerde herreken-poort (Σprofit niet omlaag, verliezers niet
omhoog). Terugdraaien als het mislukt. Logt in rules_history.

Verschil met sell_apply (die coin_strategies muteert voor SL-knoppen): dit muteert brain.rules
(de rule-101 subrule-parameters) en refired ALLE coins tegelijk.

Usage: sell_discovery_apply.py [--apply]
"""
import glob
import json
import os
import subprocess
import sys

from db import brain
import rules_history

HERE = os.path.dirname(os.path.abspath(__file__))
PY = sys.executable
COINS = [2525, 244]
APPLY = "--apply" in sys.argv

# Toegestane rule-101 kolomnamen. De knob komt uit het JSON-rapport en wordt rauw als kolomnaam in de
# UPDATE gezet — whitelist hem zodat een onverwacht/extern rapport geen willekeurige SQL kan injecteren.
ALLOWED_KNOBS = {"b_min", "value_condition"}


def current_rule_knob(conn, knob, sr_name, lb):
    """Huidige live-waarde van de rule-101 knob (voor de optimistic-lock). Whitelist de kolomnaam,
    geeft None als de subrule niet (meer) bestaat. Vergelijkbaar met _update_rule maar read-only."""
    if knob not in ALLOWED_KNOBS:
        raise SystemExit("FATALE FOUT: onbekende knob %r in rapport — geweigerd." % knob)
    with conn.cursor() as c:
        if sr_name == "previous_value" and lb is not None:
            c.execute("SELECT %s v FROM rules WHERE rule_number=101 AND subrulename=%%s "
                      "AND def1_value=%%s AND active=1" % knob, (sr_name, lb))
        elif sr_name == "sell_negative_volume":
            c.execute("SELECT %s v FROM rules WHERE rule_number=101 AND subrulename=%%s AND active=1" % knob,
                      (sr_name,))
        else:
            return None
        r = c.fetchone()
    return r["v"] if r else None


def _update_rule(conn, knob, value, sr_name, lb):
    """Muteer precies één actieve rule-101 subrule-knob en commit. Vangt drie risico's:
    - kolomnaam (knob) komt uit het rapport → whitelist tegen injectie;
    - geen passend UPDATE-pad → abort i.p.v. stille no-op die later als 'toegepast' geboekt wordt;
    - 0 rijen geraakt (subrule live gewijzigd/gedeactiveerd) → abort i.p.v. doen alsof het lukte."""
    if knob not in ALLOWED_KNOBS:
        raise SystemExit("FATALE FOUT: onbekende knob %r in rapport — geweigerd." % knob)
    with conn.cursor() as c:
        if sr_name == "previous_value" and lb is not None:
            c.execute("UPDATE rules SET %s=%%s, updated_at=NOW() WHERE rule_number=101 "
                      "AND subrulename=%%s AND def1_value=%%s AND active=1" % knob,
                      (str(value), sr_name, lb))
        elif sr_name == "sell_negative_volume":
            c.execute("UPDATE rules SET %s=%%s, updated_at=NOW() WHERE rule_number=101 "
                      "AND subrulename=%%s AND active=1" % knob,
                      (str(value), sr_name))
        else:
            raise SystemExit("FATALE FOUT: geen UPDATE-pad voor %s/lb=%s — geweigerd." % (sr_name, lb))
        if c.rowcount == 0:
            raise SystemExit("FATALE FOUT: UPDATE raakte 0 rijen — subrule %s niet (meer) actief." % sr_name)
    conn.commit()


def latest_report():
    files = sorted(glob.glob(os.path.join(HERE, "out/opt/sell_discovery_*.json")))
    if not files:
        raise SystemExit("Geen sell_discovery-rapport gevonden — draai eerst sell_discovery.py.")
    with open(files[-1]) as f:
        return json.load(f), os.path.basename(files[-1])


def manual_count(conn, sym):
    with conn.cursor() as c:
        c.execute("SELECT COUNT(*) n FROM coin_moment_labels WHERE trading_symbol_id=%s "
                  "AND source='manual' AND manual_set_at IS NOT NULL", (sym,))
        return c.fetchone()["n"]


def combined_totals(conn):
    sig = ver = 0
    with conn.cursor() as c:
        for sym in COINS:
            c.execute("SELECT SUM(profit_loss) s, SUM(profit_loss<0) v FROM coin_fires "
                      "WHERE trading_symbol_id=%s AND is_executed=1 AND profit_loss IS NOT NULL", (sym,))
            r = c.fetchone()
            sig += float(r["s"] or 0)
            ver += int(r["v"] or 0)
    return round(sig, 2), ver


def refire_all(reason):
    for sym in COINS:
        env = dict(os.environ, CHANGELOG_REASON=reason[:80])
        r = subprocess.run([PY, os.path.join(HERE, "persist_to_brain.py"), str(sym)],
                           cwd=HERE, capture_output=True, text=True, env=env)
        if r.returncode != 0:
            raise SystemExit("persist %d faalde:\n%s" % (sym, r.stderr[-1500:]))


def best_safe(report):
    safe = [p for p in report["proposals"] if p["combined_verdict"] == "SAFE"
            and p.get("type") == "param_variation"]
    if not safe:
        return None
    safe.sort(key=lambda x: (-x["combined_delta_sigma"], x["combined_delta_verliezers"]))
    return safe[0]


def apply_safe(emit, apply=False, report=None, conn=None):
    if report is None:
        report, _ = latest_report()
    best = best_safe(report)
    if not best:
        emit("Geen veilige rule-101 wijzigingen om toe te passen.", "info", None, None)
        return "niets toe te passen"

    own = conn is None
    conn = conn or brain()
    man0 = {sym: manual_count(conn, sym) for sym in COINS}

    sr_name = best["subrulename"]
    knob = best["knob"]
    old_val = best["from"]
    new_val = best["to"]
    lb = best.get("lookback")
    reason = "sell-discovery-%s-%s" % (sr_name, knob)

    head = ("%s: %s %s→%s (ΔΣ %+.1f%%, Δverlies %+d)" % (
        best["label"], knob, old_val, new_val,
        best["combined_delta_sigma"], best["combined_delta_verliezers"]))

    if not apply:
        emit("%s — VOORSTEL, niet toegepast." % head, "finding", 101, {"proposal": best})
        if own:
            conn.close()
        return "1 voorstel (propose-only)"

    # Optimistic lock: alleen toepassen als de live-waarde nog gelijk is aan de 'from' uit het rapport.
    # Anders is de subrule sinds de meting gewijzigd (andere routine/handmatige edit) → rapport verouderd.
    if old_val is not None:
        cur_val = current_rule_knob(conn, knob, sr_name, lb)
        if cur_val is None or abs(float(cur_val) - float(old_val)) > 1e-9:
            emit("%s → OVERGESLAGEN: live-waarde %s ≠ rapport-from %s (rapport verouderd)." % (head, cur_val, old_val),
                 "info", 101, {"proposal": best})
            if own:
                conn.close()
            return "overgeslagen (verouderd rapport)"

    base_sig, base_ver = combined_totals(conn)

    _update_rule(conn, knob, new_val, sr_name, lb)

    try:
        refire_all(reason)
    except SystemExit:
        # Refire faalde halverwege: de rule-wijziging is al gecommit maar coin_fires is teruggerold naar
        # de OUDE stand → inconsistent. Zet de subrule terug vóór we afbreken (best-effort revert-refire).
        _update_rule(conn, knob, old_val, sr_name, lb)
        try:
            refire_all("%s-revert" % reason)
        except SystemExit:
            pass
        raise

    for sym in COINS:
        if manual_count(conn, sym) != man0[sym]:
            raise SystemExit("FATALE FOUT: handmatige overrides veranderd door refire — afgebroken.")

    new_sig, new_ver = combined_totals(conn)

    if new_sig >= base_sig - 1e-6 and new_ver <= base_ver:
        rules_history.record(
            {101: "sell-discovery: %s %s %s→%s (ΔΣ %+.1f%%)" % (sr_name, knob, old_val, new_val, best["combined_delta_sigma"])},
            source="sell-discovery-routine", author="routine")
        emit("%s → TOEGEPAST. Σprofit %+.1f%%→%+.1f%%, verliezers %d→%d." % (
            head, base_sig, new_sig, base_ver, new_ver), "change", 101,
             {"proposal": best, "sigma": [base_sig, new_sig], "verliezers": [base_ver, new_ver]})
        result = "1 toegepast"
    else:
        _update_rule(conn, knob, old_val, sr_name, lb)
        refire_all("%s-revert" % reason)
        why = "Σprofit daalt" if new_sig < base_sig - 1e-6 else "verliezers stijgen"
        emit("%s → AFGEWEZEN (%s). Teruggedraaid." % (head, why), "info", 101, {"proposal": best})
        result = "1 teruggedraaid"

    if own:
        conn.close()
    return result


def run():
    mode = "APPLY (muteert)" if APPLY else "DRY-RUN (muteert niets — gebruik --apply)"
    print("=" * 80)
    print("SELL-DISCOVERY-APPLY — %s" % mode)
    print("=" * 80)
    summary = apply_safe(lambda m, level="change", rule=None, data=None: print("[%s] %s" % (level, m)), apply=APPLY)
    print("\n" + "-" * 80)
    print("KLAAR — %s" % summary)


if __name__ == "__main__":
    run()
