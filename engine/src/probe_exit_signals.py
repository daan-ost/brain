#!/usr/bin/env python3
"""
probe_exit_signals.py — READ-ONLY onderzoek (muteert NIETS): is een AMPLITUDE-berekening
(range_percentage / gini_coefficient / iqr_normalized op de laatste N ticks) een bruikbaar
VERKOOP-signaal? D.w.z.: verbetert "verkoop zodra de amplitude inzakt" de gerealiseerde
profit_loss van de executed trades?

Cruciaal onderscheid (CLAUDE.md / feature-quality-database memory): de feature_quality-DB meet
KOOP-moment-kwaliteit ("gaat deze trade omhoog?"). Dat is NIET hetzelfde als EXIT-timing ("sta ik
nu op het beste uitstapmoment?"). Koop-scheidingskracht is GEEN bewijs voor een verkoop-signaal.
Daarom meten we hier expliciet de EXIT-meetlat: de gerealiseerde profit_loss van de trade.

Aanpak (per munt 2525 DOGEAI, 244 NOS):
  1. Baseline = SellEngine.sell(buy_dt, buy, rule) per executed trade → huidige exit + profit_loss.
     buy = coin_fires.buy_price (de echte instap waarmee de live profit_loss is berekend). Baseline
     en alternatief draaien BEIDE via dezelfde engine op dezelfde buy → appels-met-appels.
  2. Kandidaat = (berekening × lookback N × richting {below/above} × drempel-grid). Alternatieve
     exit = loop de hold mee zoals SellEngine.sell; op de eerste tick i waar de berekening over de
     laatste N engine-ticks (LEAK-VRIJ, alleen ticks <= i) de drempel kruist → verkoop op die tick
     (prijs × SELL_MULT). Anders de NORMALE engine-exit. Het amplitude-signaal kan dus alleen
     EERDER (of gelijk) verkopen dan baseline — precies "verkoop zodra amplitude inzakt".
  3. Netto ΣΔ (alt − base) op een tijds-holdout per munt (mediaan-split op koop-datum), met de
     gepaarde sign-flip toeval-toets (opt_lib.signflip_pvalue) + Šidák over het aantal kandidaten.
  4. Edge-eis: netto ΣΔ > 0 ÉN verliezers niet omhoog, holdout-bevestigd, toeval-toets doorstaan.

STRIKT read-only: geen bestaand bestand aangeraakt, geen DB-mutatie. Vensters strikt t/m tick i.

Gebruik:  ../.venv/bin/python -u probe_exit_signals.py [--quick]
"""
import bisect
import sys

import numpy as np

from db import brain
from sell_engine import SellEngine
from calc import window_metrics
from extra_calcs import gini_coefficient, iqr_normalized
from opt_lib import signflip_pvalue, sidak, required_raw_p, GOOD_PL, BAD_PL

COINS = {2525: "DOGEAI", 244: "NOS"}

# ---------------------------------------------------------------------------
# Kandidaat-berekeningen. Alle drie schaal-vrij (ratio/genormaliseerd) → leak-vrij over de laatste N
# ticks, EEN scalar. We geven elk de juiste serie:
#   range_percentage  -> op de PRIJS-window (PX) — amplitude van de koers
#   iqr_normalized    -> op de PRIJS-window (PX) — robuuste relatieve spreiding van de koers
#   gini_coefficient  -> op de VOLUME-window (VV); gini hoort op niet-negatieve grootheden
# Elke functie krijgt een NEWEST-FIRST lijst (vals[0] = tick i) — zoals calc/extra_calcs verwachten.
# ---------------------------------------------------------------------------
def _range_percentage(newest_first):
    return window_metrics(list(newest_first)).get("range_percentage")


CANDIDATES = {
    # naam -> (serie-keuze 'PX'|'VV', functie(newest_first)->scalar of None)
    "range_percentage@PX": ("PX", _range_percentage),
    "iqr_normalized@PX":   ("PX", iqr_normalized),
    "gini_coefficient@VV": ("VV", gini_coefficient),
}

LOOKBACKS = [5, 10, 20]
FRACTIONS = [0.10, 0.20, 0.30, 0.40, 0.50, 0.60, 0.70, 0.80]  # drempel = percentiel van de window-waarden over de hold-ticks
DIRECTIONS = ["below", "above"]   # "below" = verkoop zodra amplitude INZAKT; "above" = test ook andersom


def _cls(pl):
    return "goed" if pl >= GOOD_PL else ("slecht" if pl < BAD_PL else "middel")


# ---------------------------------------------------------------------------
# Per-trade: vooraf de baseline-exit (engine) + per tick-in-de-hold de window-waarde van elke
# kandidaat. Dat doen we EEN keer per trade; daarna toetsen we elk (kandidaat,lb,richting,drempel)
# zonder de engine opnieuw te draaien. Leak-vrij: window = engine-ticks [i-N+1 .. i], newest-first.
# ---------------------------------------------------------------------------
def build_trade_records(eng, trades):
    """trades = [(buy_dt, buy_price, rule, pl_live)] op chrono volgorde. Retourneert per trade een
    dict met de baseline-exit (engine) + per hold-tick de window-waarden van elke kandidaat×lb.
    De hold-tickreeks is identiek aan SellEngine.sell: ticks met (DT[i]-buy).total<=60min.
    until_dt = de eerstvolgende koop (next buy): een rally daarna hoort bij de volgende trade, dus
    de alternatieve exit mag niet voorbij until_dt kijken (consistent met best_sell_in_window)."""
    DT, PX, VV = eng.DT, eng.PX, eng.VV
    n_series = len(DT)
    buy_dts = [t[0] for t in trades]
    recs = []
    for k, (buy_dt, buy, rule, pl_live) in enumerate(trades):
        base = eng.sell(buy_dt, float(buy), int(rule))
        if base is None:
            continue
        until_dt = buy_dts[k + 1] if k + 1 < len(buy_dts) else None
        i0 = bisect.bisect_right(DT, buy_dt)
        # verzamel de hold-ticks (zelfde grens als SellEngine.sell) + per kandidaat de leak-vrije
        # window-waarde op die tick. We bewaren (tick_dt, price, {cand_lb: value}).
        hold = []
        i = i0
        while i < n_series and (DT[i] - buy_dt).total_seconds() <= 60 * 60:
            vals_by_key = {}
            for cname, (serie, fn) in CANDIDATES.items():
                src = PX if serie == "PX" else VV
                for lb in LOOKBACKS:
                    lo = max(0, i - lb + 1)
                    window_nf = src[lo:i + 1][::-1]      # newest-first, STRIKT t/m i (geen lookahead)
                    if len(window_nf) < 2:
                        v = None
                    else:
                        try:
                            v = fn(window_nf)
                        except Exception:
                            v = None
                    vals_by_key[(cname, lb)] = (None if v is None else float(v))
            hold.append((DT[i], PX[i], vals_by_key))
            i += 1
        recs.append({
            "buy_dt": buy_dt, "buy": float(buy), "rule": int(rule),
            "base_pl": float(base["profit_loss"]), "base_exit_dt": base["selling_date"],
            "until_dt": until_dt, "hold": hold,
        })
    return recs


def alt_pl_for_candidate(rec, cname, lb, direction, threshold, sell_mult, rounding):
    """Gerealiseerde profit_loss van de ALTERNATIEVE exit voor dit (kandidaat,lb,richting,drempel).
    Verkoop op de eerste hold-tick waar de window-waarde de drempel kruist (en die <= until_dt ligt);
    anders de baseline-exit. Het signaal kan alleen vervroegen → alt_pl == base_pl als 't nooit vuurt
    of pas na de baseline-exit zou vuren."""
    buy = rec["buy"]
    base_exit_dt = rec["base_exit_dt"]
    until_dt = rec["until_dt"]
    for (tick_dt, price, vals_by_key) in rec["hold"]:
        if tick_dt > base_exit_dt:
            break                       # voorbij waar de engine zelf al verkocht → signaal voegt niets toe
        if until_dt is not None and tick_dt >= until_dt:
            break                       # next buy bereikt: rest hoort bij de volgende trade
        v = vals_by_key.get((cname, lb))
        if v is None:
            continue
        hit = (v < threshold) if direction == "below" else (v > threshold)
        if hit:
            selling_price = round(price * sell_mult, rounding)
            return round((selling_price - buy) / buy * 100, 3)
    return rec["base_pl"]               # nooit gevuurd vóór de engine-exit → baseline


def thresholds_for(recs, cname, lb, split_mask, fractions):
    """Drempel-grid = percentielen van ALLE window-waarden van deze (kandidaat,lb) over de
    TRAIN-trades (split_mask[k]==False → train). Drempels uit train → leak-vrij t.o.v. de holdout."""
    vals = []
    for k, rec in enumerate(recs):
        if split_mask[k]:
            continue                    # alleen train voor drempel-afleiding
        for (_dt, _px, vbk) in rec["hold"]:
            v = vbk.get((cname, lb))
            if v is not None:
                vals.append(v)
    if len(vals) < 20:
        return []
    arr = np.asarray(vals, dtype=float)
    return sorted({round(float(np.percentile(arr, f * 100)), 6) for f in fractions})


def evaluate_coin(sym, quick=False):
    name = COINS[sym]
    conn = brain()
    with conn.cursor() as c:
        c.execute("SELECT datetime, buy_price, rule, profit_loss FROM coin_fires "
                  "WHERE trading_symbol_id=%s AND is_executed=1 AND profit_loss IS NOT NULL "
                  "AND buy_price IS NOT NULL ORDER BY datetime", (sym,))
        rows = c.fetchall()
    trades = [(r["datetime"], r["buy_price"], r["rule"], float(r["profit_loss"])) for r in rows]
    conn.close()

    eng = SellEngine(sym)
    recs = build_trade_records(eng, trades)
    sell_mult, rounding = eng.SELL_MULT, eng.ROUNDING
    eng.close()

    n = len(recs)
    # mediaan-split op koop-datum: late helft = holdout (test). recs is al chronologisch.
    cut = n // 2
    split_mask = [k >= cut for k in range(n)]     # True = test/holdout
    base_pls = np.array([r["base_pl"] for r in recs])
    base_sigma = float(base_pls.sum())
    base_sigma_test = float(base_pls[cut:].sum())
    base_losers_test = int((base_pls[cut:] < 0).sum())

    print(f"\n=== {name} ({sym}) — {n} executed trades | baseline Σprofit={base_sigma:.1f}% "
          f"(test-helft Σ={base_sigma_test:.1f}%, verliezers={base_losers_test}) ===", flush=True)

    lookbacks = LOOKBACKS if not quick else [10]
    fractions = FRACTIONS if not quick else [0.2, 0.4, 0.6]

    # 1) Tel eerst het AANTAL toetsbare kandidaten (voor Šidák).
    candidate_keys = []
    for cname in CANDIDATES:
        for lb in lookbacks:
            for direction in DIRECTIONS:
                thrs = thresholds_for(recs, cname, lb, split_mask, fractions)
                for thr in thrs:
                    candidate_keys.append((cname, lb, direction, thr))
    n_hyp = max(1, len(candidate_keys))
    req_p = required_raw_p(n_hyp)
    print(f"  kandidaten getoetst: {n_hyp} | Šidák-vereiste rauwe p < {req_p:.2e}", flush=True)

    results = []
    for (cname, lb, direction, thr) in candidate_keys:
        # per-trade alt_pl; deltas op de HOLDOUT (test-helft) — dat is de meetlat.
        alt_pls = np.array([alt_pl_for_candidate(rec, cname, lb, direction, thr, sell_mult, rounding)
                            for rec in recs])
        d_all = alt_pls - base_pls
        d_test = d_all[cut:]
        n_moved_test = int((np.abs(d_test) > 1e-9).sum())
        if n_moved_test == 0:
            continue
        alt_sigma_test = float(alt_pls[cut:].sum())
        net_test = float(d_test.sum())
        alt_losers_test = int((alt_pls[cut:] < 0).sum())
        # ook in-sample (train) voor context
        net_train = float(d_all[:cut].sum())
        # toeval-toets op de holdout-deltas (alleen geraakte trades, in signflip zelf gefilterd)
        sf = signflip_pvalue(d_test.tolist())
        p_raw = None if sf is None else sf["p"]
        p_sidak = None if p_raw is None else sidak(p_raw, n_hyp)
        floor = None if sf is None else sf["floor"]
        results.append({
            "cand": cname, "lb": lb, "dir": direction, "thr": thr,
            "n_moved_test": n_moved_test,
            "net_train": round(net_train, 1), "net_test": round(net_test, 1),
            "alt_sigma_test": round(alt_sigma_test, 1),
            "d_losers_test": alt_losers_test - base_losers_test,
            "alt_losers_test": alt_losers_test,
            "p_raw": p_raw, "p_sidak": p_sidak, "floor": floor,
        })

    return {
        "sym": sym, "name": name, "n": n, "cut": cut,
        "base_sigma": base_sigma, "base_sigma_test": base_sigma_test,
        "base_losers_test": base_losers_test, "n_hyp": n_hyp, "req_p": req_p,
        "results": results,
    }


def report_coin(res):
    name = res["name"]
    rows = res["results"]
    if not rows:
        print(f"  {name}: geen enkele kandidaat verplaatste een holdout-trade.")
        return
    # sorteer op netto holdout-effect (hoog = goed)
    rows_sorted = sorted(rows, key=lambda r: r["net_test"], reverse=True)
    print(f"\n  -- {name}: TOP-10 kandidaten op netto holdout-ΔΣprofit --")
    print(f"  {'cand':22s} {'lb':>3s} {'dir':>5s} {'thr':>10s} {'Δtest':>7s} {'Δtrain':>7s} "
          f"{'Δverl':>6s} {'#mv':>4s} {'p_raw':>8s} {'p_šidák':>8s}")
    for r in rows_sorted[:10]:
        pr = "  -  " if r["p_raw"] is None else f"{r['p_raw']:.4f}"
        ps = "  -  " if r["p_sidak"] is None else f"{r['p_sidak']:.4f}"
        print(f"  {r['cand']:22s} {r['lb']:>3d} {r['dir']:>5s} {r['thr']:>10.4f} "
              f"{r['net_test']:>7.1f} {r['net_train']:>7.1f} {r['d_losers_test']:>6d} "
              f"{r['n_moved_test']:>4d} {pr:>8s} {ps:>8s}")

    # EDGE-gate: netto holdout-Σ > 0 ÉN verliezers niet omhoog ÉN Šidák-p < 0.05.
    req_p = res["req_p"]
    edges = [r for r in rows
             if r["net_test"] > 1e-9 and r["d_losers_test"] <= 0
             and r["p_raw"] is not None and r["p_raw"] < req_p
             and r["p_sidak"] is not None and r["p_sidak"] < 0.05]
    # ook positief-maar-niet-gecertificeerd, ter context
    pos = [r for r in rows if r["net_test"] > 1e-9 and r["d_losers_test"] <= 0]
    print(f"\n  {name}: {len(pos)} kandidaten met netto holdout-winst zonder extra verliezers; "
          f"daarvan {len(edges)} ook door de toeval-toets (Šidák p<0.05).")
    if edges:
        for r in sorted(edges, key=lambda x: x["net_test"], reverse=True):
            print(f"    EDGE: {r['cand']} lb{r['lb']} {r['dir']} <{r['thr']:.4f}> "
                  f"Δtest={r['net_test']:.1f}% Δverl={r['d_losers_test']} "
                  f"p_šidák={r['p_sidak']:.4f}")


def main():
    quick = "--quick" in sys.argv
    print("probe_exit_signals — is amplitude een VERKOOP-signaal? (read-only, niets gemuteerd)")
    print("meetlat = gerealiseerde profit_loss (EXIT-timing), NIET koop-kwaliteit.")
    if quick:
        print("[--quick: lb=10, drempels 0.2/0.4/0.6]")
    all_res = []
    for sym in COINS:
        res = evaluate_coin(sym, quick=quick)
        report_coin(res)
        all_res.append(res)

    # ---- gepoolde eindconclusie ----
    print("\n" + "=" * 78)
    print("EINDOORDEEL")
    print("=" * 78)
    any_edge = False
    for res in all_res:
        edges = [r for r in res["results"]
                 if r["net_test"] > 1e-9 and r["d_losers_test"] <= 0
                 and r["p_raw"] is not None and r["p_raw"] < res["req_p"]
                 and r["p_sidak"] is not None and r["p_sidak"] < 0.05]
        best_pos = max((r for r in res["results"] if r["net_test"] > 1e-9 and r["d_losers_test"] <= 0),
                       key=lambda x: x["net_test"], default=None)
        if best_pos is None:
            bp = "geen positieve"
        else:
            ps = "-" if best_pos["p_sidak"] is None else f"{best_pos['p_sidak']:.3f}"
            bp = (f"beste positieve: {best_pos['cand']} lb{best_pos['lb']} {best_pos['dir']} "
                  f"Δtest={best_pos['net_test']:.1f}% (p_šidák={ps})")
        print(f"  {res['name']}: {len(edges)} gecertificeerde edge(s). {bp}")
        if edges:
            any_edge = True
    print("\n  Samengevat: " + (
        "AMPLITUDE IS een verkoop-signaal — minstens één kandidaat haalt de volledige gate "
        "(netto holdout-winst, verliezers niet omhoog, toeval-toets doorstaan)."
        if any_edge else
        "GEEN amplitude-kandidaat haalt de volledige EXIT-gate. Koop-scheidingskracht (feature_quality) "
        "vertaalt zich NIET naar exit-timing. Geen rule-101 subrule-type bouwen op deze basis."))


if __name__ == "__main__":
    main()
