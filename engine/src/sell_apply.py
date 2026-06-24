#!/usr/bin/env python3
"""
Sell-tuning APPLY (FASE 5) — voert een veilig instelknop-voorstel ECHT door, achter de gate-keten.
DRY-RUN is default; alleen met --apply muteert het.

Spiegelt auto_apply.py, maar voor sell-instellingen (coin_strategies) i.p.v. buy-subrules (rules):
voor elk SAFE-voorstel (beste per coin/rule uit het laatste sell_tuning-rapport):

  GATE 0  handmatige overrides — telling vóór==na elke refire; wijkt af → FATALE ABORT (mag nooit).
  GATE 1+2 (al bewezen in sell_tuning, in-memory): holdout-bevestigd én netto Σprofit ≥ 0 → SAFE.
  GATE 3  ECHTE herreken-poort: schrijf de override naar coin_strategies → persist_to_brain herrekent
          die munt → meet de GEREALISEERDE Σprofit + verliezers per munt (incl. het single-position
          dedup-effect dat de in-memory meting niet ziet) → HOUDEN iff Σprofit niet omlaag ÉN
          verliezers niet omhoog (Daan's "gebalanceerd") → anders TERUGDRAAIEN (override weg + refire).

De oracle-poort (validate_sell) zit hier bewust NIET: die meet trouw aan het oude systeem, terwijl
tunen juist beter-dan-legacy wil — "blijf trouw aan legacy" zou elke verbetering blokkeren. De holdout
+ de echte herreken-poort zijn de juiste vangnetten.

Handmatige overrides (coin_moment_labels, manual_set_at) worden nooit aangeraakt: deze routine muteert
coin_strategies + coin_fires; de labels overleven elke refire by construction (persist_to_brain leest
ze, schrijft ze niet). Elke wijziging logt in coin_fires_changelog (reason='tuning-routine-<rule>-<knob>').

Usage: sell_apply.py [--apply]      (default: dry-run, toont wat het ZOU doen)
"""
import glob
import json
import os
import subprocess
import sys

from db import brain
from sell_engine import merge_sl
import opt_lib as ol

HERE = os.path.dirname(os.path.abspath(__file__))
PY = sys.executable
from coins import active_coin_ids
COINS = active_coin_ids()                     # alle coins met indicator-data, automatisch (zie coins.py)
APPLY = "--apply" in sys.argv
PERM_ALPHA = 0.05         # familiebrede drempel die de toeval-toets (Šidák-gecorrigeerd) moet halen

# JSON-sleutel (in het rapport) → parsed knob-naam (zoals merge_sl/parse_sl ze teruggeeft).
REV_KNOB = {"hp_setting6": "hp6", "hp_setting7": "hp7", "min_sl1": "min_sl1", "minimal_profit": "minimal_profit"}

# Toegestane instelknop-namen (= JSON_KEY-waarden uit sell_tuning). De knob komt uit het JSON-rapport
# en wordt als sl_settings-sleutel weggeschreven — whitelist hem zodat een onverwacht/extern rapport
# nooit een andere sleutel kan injecteren.
ALLOWED_KNOBS = {"hp_setting6", "hp_setting7", "min_sl1", "minimal_profit"}


def latest_report():
    files = sorted(glob.glob(os.path.join(HERE, "out/opt/sell_tuning_*.json")))
    if not files:
        raise SystemExit("Geen sell_tuning-rapport gevonden — draai eerst sell_tuning.py.")
    with open(files[-1]) as f:
        return json.load(f), os.path.basename(files[-1])


def manual_count(conn, sym):
    with conn.cursor() as c:
        c.execute("SELECT COUNT(*) n FROM coin_moment_labels WHERE trading_symbol_id=%s "
                  "AND source='manual' AND manual_set_at IS NOT NULL", (sym,))
        return c.fetchone()["n"]


def coin_totals(conn, sym):
    """(Σprofit, #verliezers, {rule:(Σ,verlies,n)}) over de EXECUTED trades — gerealiseerd, incl. dedup."""
    with conn.cursor() as c:
        c.execute("SELECT rule, SUM(profit_loss) sig, SUM(profit_loss<0) verlies, COUNT(*) n "
                  "FROM coin_fires WHERE trading_symbol_id=%s AND is_executed=1 AND profit_loss IS NOT NULL "
                  "GROUP BY rule", (sym,))
        per = {r["rule"]: (float(r["sig"] or 0), int(r["verlies"]), int(r["n"])) for r in c.fetchall()}
    return sum(s for s, _, _ in per.values()), sum(v for _, v, _ in per.values()), per


def effective_knob(conn, sym, rule, json_knob):
    """De huidige EFFECTIEVE knob-waarde (globale strategy + per-coin override gemerged), zoals
    sell_tuning hem als 'from' opschreef. Voor de optimistic-lock: rapport toepassen op een gewijzigde
    live-waarde = stil fout. Geeft None als de knob/rule ontbreekt."""
    with conn.cursor() as c:
        c.execute("SELECT rule_number, sl_settings FROM strategies")
        raw = {r["rule_number"]: r["sl_settings"] for r in c.fetchall()}
        c.execute("SELECT rule_number, sl_settings FROM coin_strategies WHERE trading_symbol_id=%s", (sym,))
        ovr = {r["rule_number"]: r["sl_settings"] for r in c.fetchall()}
    merged = merge_sl(raw, ovr)
    return merged.get(int(rule), {}).get(REV_KNOB.get(json_knob))


def read_override(conn, sym, rule):
    with conn.cursor() as c:
        c.execute("SELECT sl_settings FROM coin_strategies WHERE trading_symbol_id=%s AND rule_number=%s",
                  (sym, rule))
        r = c.fetchone()
    return r["sl_settings"] if r else None


def write_override(conn, sym, rule, knob, value):
    """Merge {knob: value} in de bestaande coin-override (of {}) en upsert. Waarden als string —
    zelfde shape als strategies.sl_settings; parse_sl cast naar float."""
    cur = read_override(conn, sym, rule)
    j = json.loads(cur) if cur else {}
    j[knob] = str(value)
    with conn.cursor() as c:
        c.execute("INSERT INTO coin_strategies (trading_symbol_id, rule_number, sl_settings, created_at, updated_at) "
                  "VALUES (%s,%s,%s,NOW(),NOW()) ON DUPLICATE KEY UPDATE sl_settings=VALUES(sl_settings), updated_at=NOW()",
                  (sym, rule, json.dumps(j)))
    conn.commit()


def restore_override(conn, sym, rule, prev):
    """Zet de override exact terug (UPDATE), of verwijder de rij als er vóór de apply geen was (DELETE)."""
    with conn.cursor() as c:
        if prev is None:
            c.execute("DELETE FROM coin_strategies WHERE trading_symbol_id=%s AND rule_number=%s", (sym, rule))
        else:
            c.execute("UPDATE coin_strategies SET sl_settings=%s, updated_at=NOW() "
                      "WHERE trading_symbol_id=%s AND rule_number=%s", (prev, sym, rule))
    conn.commit()


def refire(sym, reason):
    env = dict(os.environ, CHANGELOG_REASON=reason[:80])
    r = subprocess.run([PY, os.path.join(HERE, "persist_to_brain.py"), str(sym)],
                       cwd=HERE, capture_output=True, text=True, env=env)
    if r.returncode != 0:
        raise SystemExit(f"persist {sym} faalde:\n{r.stderr[-1500:]}")


def best_safe_per_coin_rule(report):
    best = {}
    for p in report["proposals"]:
        if p["verdict"] != "SAFE":
            continue
        k = (p["coin"], p["rule"])
        if k not in best or p["netto_totaal"] > best[k]["netto_totaal"]:
            best[k] = p
    return best


def _toeval_filter(best, n_hyp, emit):
    """Bug 4 — houd alleen de SAFE-voorstellen waarvan de netto-winst de sign-flip toeval-toets doorstaat,
    na een Šidák-correctie over de familie (n_hyp = aantal SAFE-voorstellen in het rapport; conservatieve
    ONDER-grens van de echte zoekruimte, dus een PASS is sterk bewijs). De per-voorstel p, het aantal
    geraakte trades (perm_n) en de floor zijn al in sell_tuning berekend. floor > vereiste-p = te weinig
    geraakte trades om te KUNNEN certificeren → niet toepassen (eerlijk: meer data/munten nodig, geen
    filter om te relaxen). Mirror van auto_apply._toeval_filter, maar op de sell-meetlat (netto Σprofit).
    emit(message, level, rule, data). Geeft de overlevers (zelfde dict-vorm als best)."""
    if not best:
        return {}
    p_req = ol.required_raw_p(n_hyp, PERM_ALPHA)
    kept = {}
    for (sym, rule), p in sorted(best.items()):
        label = f"{p['coin_name']} r{rule}: {p['knob']} {p['from']}→{p['to']}"
        perm_p, perm_floor, perm_n = p.get("perm_p"), p.get("perm_floor"), p.get("perm_n")
        if perm_p is None or perm_floor is None:
            emit(f"{label} → OVERGESLAGEN: geen toeval-toets in het rapport (verouderd rapport — "
                 "draai sell_tuning opnieuw).", "info", rule, {"proposal": p})
            continue
        if perm_floor > p_req:
            emit(f"{label} → KAN NIET CERTIFICEREN: maar {perm_n} geraakte trades; de toeval-toets haalt "
                 f"p≥{perm_floor:.4f}, maar {n_hyp} SAFE-voorstellen vragen p<{p_req:.4f}. Niet toegepast "
                 f"(meer data/munten nodig, geen ruis live).", "info", rule, {"proposal": p})
            continue
        p_corr = ol.sidak(perm_p, n_hyp)
        if p_corr < PERM_ALPHA:
            emit(f"{label} → toeval-toets DOORSTAAN: p={perm_p:.4f} (Šidák×{n_hyp}={p_corr:.3f}<0.05, "
                 f"{perm_n} trades).", "info", rule, {"proposal": p, "perm_p": perm_p, "perm_p_corr": p_corr})
            kept[(sym, rule)] = p
        else:
            emit(f"{label} → AFGEWEZEN door toeval-toets: p={perm_p:.4f} (Šidák×{n_hyp}={p_corr:.3f}≥0.05) — "
                 f"netto-winst niet te onderscheiden van toeval. Niet toegepast.", "info", rule,
                 {"proposal": p, "perm_p": perm_p, "perm_p_corr": p_corr})
    return kept


def apply_safe(emit, apply=False, report=None, conn=None):
    """Pas de beste SAFE-voorstellen toe achter de gate-keten. emit(message, level, rule, data) —
    zelfde signatuur als auto_apply. apply=False = propose-only (journalt de voorstellen, muteert niets).
    Wordt door de sell-tuning-routine aangeroepen én door de CLI. Geeft een one-line samenvatting terug."""
    if report is None:
        report, _ = latest_report()
    best_all = best_safe_per_coin_rule(report)
    if not best_all:
        emit("Geen veilige sell-instellingen om toe te passen.", "info", None, None)
        return "niets toe te passen"

    # TOEVAL-TOETS (bug 4) vóór de dure refire: certificeer dat de netto-winst geen toeval is. n_hyp =
    # aantal SAFE-voorstellen = familiegrootte voor de Šidák-correctie.
    n_hyp = sum(1 for pr in report["proposals"] if pr["verdict"] == "SAFE")
    best = _toeval_filter(best_all, n_hyp, emit)
    n_toeval_rejected = len(best_all) - len(best)
    if not best:
        emit("Geen voorstel doorstaat de toeval-toets — niets toegepast.", "info", None, None)
        return f"0 toegepast ({n_toeval_rejected} door de toeval-toets afgewezen)"

    own = conn is None
    conn = conn or brain()
    man0 = {sym: manual_count(conn, sym) for sym in COINS}
    applied, rejected = [], []

    for (sym, rule), p in sorted(best.items()):
        name, reason = p["coin_name"], f"tuning-routine-{rule}-{p['knob']}"
        head = (f"{name} r{rule}: {p['knob']} {p['from']}→{p['to']} "
                f"(in-memory netto {p['netto_totaal']:+.1f}%, holdout {p['netto_holdout']:+.1f}%)")
        if not apply:
            emit(f"{head} — VOORSTEL, niet toegepast.", "finding", rule, {"proposal": p})
            continue

        if p["knob"] not in ALLOWED_KNOBS:
            raise SystemExit(f"FATALE FOUT: onbekende instelknop {p['knob']!r} in rapport — geweigerd.")

        # Optimistic lock: pas alleen toe als de live-waarde nog gelijk is aan wat het rapport als 'from'
        # zag. Anders is het rapport verouderd (andere routine/handmatige edit ertussen) → overslaan.
        eff = effective_knob(conn, sym, rule, p["knob"])
        if eff is None or abs(eff - float(p["from"])) > 1e-9:
            emit(f"{head} → OVERGESLAGEN: live-waarde {eff} ≠ rapport-from {p['from']} (rapport verouderd).",
                 "info", rule, {"proposal": p})
            rejected.append((sym, rule, p))
            continue

        base_sig, base_verlies, base_per = coin_totals(conn, sym)
        prev = read_override(conn, sym, rule)
        write_override(conn, sym, rule, p["knob"], p["to"])
        try:
            refire(sym, reason)
        except SystemExit:
            # Refire faalde halverwege: de override is al gecommit maar coin_fires is teruggerold naar
            # de OUDE stand → inconsistent. Zet de override terug vóór we afbreken (best-effort revert-refire).
            restore_override(conn, sym, rule, prev)
            try:
                refire(sym, f"{reason}-revert")
            except SystemExit:
                pass
            raise
        if manual_count(conn, sym) != man0[sym]:
            raise SystemExit("FATALE FOUT: aantal handmatige overrides veranderde door de refire — afgebroken.")
        new_sig, new_verlies, new_per = coin_totals(conn, sym)

        # GATE 3 (gebalanceerd): Σprofit niet omlaag EN verliezers niet omhoog — op de MUNT (portfolio-
        # totaal, Daans hoofdmaat) ÉN op de afgestelde regel zelf. Alleen de munt-poort mist een knop die
        # zijn eigen regel schaadt maar de schade via single-position-dedup naar een andere regel verschuift
        # (munt blijft dan vlak). De per-regel split komt al uit coin_totals (was eerder weggegooid).
        bs, bv, _ = base_per.get(rule, (0.0, 0, 0))
        ns, nv, _ = new_per.get(rule, (0.0, 0, 0))
        coin_ok = new_sig >= base_sig - 1e-6 and new_verlies <= base_verlies
        rule_ok = ns >= bs - 1e-6 and nv <= bv
        if coin_ok and rule_ok:
            emit(f"{head} → TOEGEPAST. Σprofit {base_sig:+.1f}%→{new_sig:+.1f}%, "
                 f"verliezers {base_verlies}→{new_verlies} (regel {rule}: Σ{bs:+.1f}→{ns:+.1f}%, "
                 f"verlies {bv}→{nv}).", "change", rule,
                 {"proposal": p, "sigma": [base_sig, new_sig], "verliezers": [base_verlies, new_verlies],
                  "rule_sigma": [bs, ns], "rule_verliezers": [bv, nv]})
            applied.append((sym, rule, p, base_sig, new_sig, base_verlies, new_verlies))
        else:
            restore_override(conn, sym, rule, prev)
            refire(sym, f"{reason}-revert")
            if not coin_ok:
                why = "Σprofit munt zou dalen" if new_sig < base_sig - 1e-6 else "verliezers munt zouden stijgen"
            else:
                why = (f"regel {rule} zelf zou verslechteren (Σ{bs:+.1f}→{ns:+.1f}%, verlies {bv}→{nv}) "
                       f"ondanks vlak munt-totaal — dedup verschuift de schade naar een andere regel")
            emit(f"{head} → AFGEWEZEN op echte herreken-poort ({why}): Σprofit {base_sig:+.1f}%→{new_sig:+.1f}%, "
                 f"verliezers {base_verlies}→{new_verlies}. Teruggedraaid.", "info", rule, {"proposal": p})
            rejected.append((sym, rule, p))

    if own:
        conn.close()
    if not apply:
        return f"{len(best)} voorstellen na toeval-toets (propose-only), {n_toeval_rejected} afgewezen"
    return (f"{len(applied)} toegepast, {len(rejected)} teruggedraaid, "
            f"{n_toeval_rejected} door toeval-toets afgewezen")


def run():
    mode = "APPLY (muteert)" if APPLY else "DRY-RUN (muteert niets — gebruik --apply)"
    print("=" * 80)
    print(f"SELL-APPLY — {mode}")
    print("=" * 80)
    summary = apply_safe(lambda m, level="change", rule=None, data=None: print(f"[{level}] {m}"), apply=APPLY)
    print("\n" + "-" * 80)
    print(f"KLAAR — {summary}")


if __name__ == "__main__":
    run()
