#!/usr/bin/env python3
"""
sell_default_sweep.py — Gepoolde sell-default sweep (Epic N, Feature 1+2). READ-ONLY.

Zoekt de robuuste gedeelde sell-default (strategies.sl_settings) die over ALLE munten samen het
beste is — i.p.v. de huidige ongeoptimaliseerde legacy-default. Per (rule, knop, waarde) wordt
per munt in-memory gemeten (sell_tuning.metrics), dan geaggregeerd tot de breedte-maat
(#verbeterd − #geschaad op holdout, tiebreak: mediaan-per-munt-netto).

Een munt met per-munt override (coin_strategies) op de geteste knop is IMMUUN: de default-shift
raakt haar niet (faithful aan merge_sl). Licht schaden mag — geschade munten worden gelogd als
override-kandidaat, niet als blocker.

Toeval-toets (Feature 2): signflip_pvalue op de gepoolde per-trade deltas + Šidák over alle
GLOBAAL_SAFE-kandidaten. Geen default-wijziging zonder significantie.

In-memory meting: geen refire, geen DB-mutatie. Output = JSON + leesbaar rapport.

Usage: sell_default_sweep.py      (schrijft out/opt/sell_default_<date>.json)
"""
import json
import os
import datetime
from collections import defaultdict
from statistics import median

import sell_engine
from sell_lock import parse_sl
import opt_lib as ol
from sell_tuning import load_trades, metrics, GRID, JSON_KEY, MIN_SPLIT
from db import brain
from coins import active_coin_ids

RULES = [20, 21, 22, 23, 30, 31]
PERM_N = 4000
PERM_SEED = 42
PERM_ALPHA = 0.05

# Uitgebreid grid voor rules die al op 0.99 zitten of waar het optimum hoger kan liggen
GRID_EXTENDED = {
    "hp6": [3.0, 4.0, 5.0, 6.0, 8.0],
    "hp7": [10.0, 15.0, 20.0, 25.0],
    "min_sl1": [0.985, 0.988, 0.99, 0.992, 0.994, 0.996],
    "minimal_profit": [0.5, 0.8, 1.0],
}


def _override_knobs(conn, sym):
    """Per-rule set van JSON-knopnamen die deze munt override't in coin_strategies."""
    with conn.cursor() as c:
        c.execute("SELECT rule_number, sl_settings FROM coin_strategies "
                  "WHERE trading_symbol_id=%s", (sym,))
        out = {}
        for r in c.fetchall():
            if r["sl_settings"]:
                out[int(r["rule_number"])] = set(json.loads(r["sl_settings"]).keys())
        return out


def _breedte(coin_data, phase):
    """#verbeterd − #geschaad over sufficient coins; tiebreak = mediaan-per-munt-netto."""
    k = f"{phase}_netto"
    suf = {n: d for n, d in coin_data.items() if d["sufficient"]}
    if not suf:
        return {"score": 0, "improved": [], "harmed": [], "neutral": [],
                "median_netto": 0.0, "pooled_netto": 0.0, "n_sufficient": 0}
    improved = sorted(n for n, d in suf.items() if d[k] > 1e-9)
    harmed = sorted(n for n, d in suf.items() if d[k] < -1e-9)
    neutral = sorted(n for n, d in suf.items() if abs(d[k]) <= 1e-9)
    nettos = [d[k] for d in suf.values()]
    return {"score": len(improved) - len(harmed), "improved": improved, "harmed": harmed,
            "neutral": neutral, "median_netto": round(median(nettos), 3),
            "pooled_netto": round(sum(nettos), 2), "n_sufficient": len(suf)}


def _verdict(train_b, holdout_b, coin_data):
    """GLOBAAL_SAFE / OVERFIT / ZWAK / INERT / UNSAFE."""
    if not coin_data:
        return "INERT"
    any_aff = any(d["train"]["affected"] > 0 or d["holdout"]["affected"] > 0
                  for d in coin_data.values())
    if not any_aff:
        return "INERT"
    if not any(d["holdout"]["affected"] > 0 for d in coin_data.values()):
        return "ZWAK"
    if train_b["score"] > 0 and holdout_b["score"] < 0:
        return "OVERFIT"
    if train_b["score"] > 0 and holdout_b["score"] >= 0:
        return "GLOBAAL_SAFE"
    return "UNSAFE"


def sweep(verbose=True, grid=None, rules=None):
    conn = brain()
    coins = active_coin_ids()
    p = (lambda *a: print(*a)) if verbose else (lambda *a: None)
    grid = grid or GRID_EXTENDED
    rules = rules or RULES

    # Globale defaults (de ZUIVERE default-laag, vóór merge met coin_strategies)
    with conn.cursor() as c:
        c.execute("SELECT rule_number, sl_settings FROM strategies")
        global_sl = {int(r["rule_number"]): parse_sl(r["sl_settings"])
                     for r in c.fetchall()}

    # Alle munten laden + baseline berekenen
    engines, trades_all, base_pl_all = {}, {}, {}
    ovr_all, names, by_rule_all = {}, {}, {}
    for sym in coins:
        eng = sell_engine.SellEngine(sym, conn=conn)
        trades, name, _ = load_trades(conn, sym)
        bp = []
        for t in trades:
            r = eng.sell(t["dt"], t["buy"], t["rule"])
            bp.append(r["profit_loss"] if r else 0.0)
        br = defaultdict(list)
        for i, t in enumerate(trades):
            br[t["rule"]].append(i)
        engines[sym] = eng
        trades_all[sym] = trades
        base_pl_all[sym] = bp
        ovr_all[sym] = _override_knobs(conn, sym)
        names[sym] = name
        by_rule_all[sym] = dict(br)
        p(f"  geladen: {name} ({sym}) — {len(trades)} trades")

    total_trades = sum(len(trades_all[s]) for s in coins)
    p("\n" + "=" * 78)
    p("GEPOOLDE SELL-DEFAULT — sweep (read-only)")
    p(f"Breedte-maat: #verbeterd − #geschaad. {len(coins)} munten, {total_trades} trades, "
      f"rules {rules}")
    p("=" * 78)

    report = {
        "generated_at": datetime.datetime.now().isoformat(timespec="seconds"),
        "coins": {str(sym): names[sym] for sym in coins},
        "global_defaults": {
            str(r): {JSON_KEY[k]: global_sl[r][k] for k in grid}
            for r in rules if r in global_sl
        },
        "candidates": [],
        "winners": {},
    }

    # ── Feature 1: in-memory meten ──────────────────────────────────────────────
    all_candidates = []

    for rule in rules:
        if rule not in global_sl:
            continue
        gl = global_sl[rule]

        for knob, values in grid.items():
            jk = JSON_KEY[knob]
            gfrom = gl[knob]

            for val in values:
                if abs(gfrom - val) < 1e-12:
                    continue

                coin_data = {}
                all_deltas = []
                immune = []

                for sym in coins:
                    if jk in ovr_all[sym].get(rule, set()):
                        immune.append(names[sym])
                        continue
                    idxs = by_rule_all[sym].get(rule, [])
                    if not idxs:
                        continue
                    eng = engines[sym]
                    cur = eng.sl_by_rule.get(rule)
                    if cur is None:
                        continue

                    cand_sl = dict(cur)
                    cand_sl[knob] = val
                    eng.sl_by_rule[rule] = cand_sl
                    tuned = {}
                    for i in idxs:
                        t = trades_all[sym][i]
                        r = eng.sell(t["dt"], t["buy"], rule)
                        tuned[i] = r["profit_loss"] if r else 0.0
                    eng.sl_by_rule[rule] = cur

                    bp = base_pl_all[sym]
                    spl = {"train": [], "holdout": []}
                    for i in idxs:
                        spl[trades_all[sym][i]["split"]].append((bp[i], tuned[i]))
                    mt, mh = metrics(spl["train"]), metrics(spl["holdout"])
                    suf = mt["n"] >= MIN_SPLIT and mh["n"] >= MIN_SPLIT
                    deltas = [t - b for b, t in spl["train"] + spl["holdout"]]
                    all_deltas.extend(deltas)
                    coin_data[names[sym]] = {
                        "sym": sym,
                        "train_netto": mt["netto"],
                        "holdout_netto": mh["netto"],
                        "sufficient": suf,
                        "train": mt,
                        "holdout": mh,
                    }

                tb = _breedte(coin_data, "train")
                hb = _breedte(coin_data, "holdout")
                vd = _verdict(tb, hb, coin_data)

                per_coin = {
                    n: {"sym": d["sym"],
                        "train_netto": d["train_netto"],
                        "holdout_netto": d["holdout_netto"],
                        "n_train": d["train"]["n"],
                        "n_holdout": d["holdout"]["n"],
                        "sufficient": d["sufficient"]}
                    for n, d in coin_data.items()
                }

                ce = {
                    "rule": rule, "knob": jk, "from": gfrom, "to": val,
                    "verdict": vd,
                    "coins_measured": len(coin_data),
                    "coins_immune": len(immune),
                    "immune_names": immune,
                    "train": tb, "holdout": hb,
                    "per_coin": per_coin,
                }
                all_candidates.append((ce, all_deltas))

    p(f"\n{len(all_candidates)} kandidaten gemeten.")

    # ── Feature 2: toeval-toets + Šidák ─────────────────────────────────────────
    safe_cands = [(ce, d) for ce, d in all_candidates if ce["verdict"] == "GLOBAAL_SAFE"]
    n_hyp = len(safe_cands)
    p_req = ol.required_raw_p(n_hyp, PERM_ALPHA) if n_hyp > 0 else None

    if n_hyp:
        p(f"Toeval-toets: {n_hyp} GLOBAAL_SAFE kandidaten, "
          f"Šidák over {n_hyp} (p_req={p_req:.5f})")
    else:
        p("Geen GLOBAAL_SAFE kandidaten — toeval-toets overgeslagen.")

    for ce, deltas in safe_cands:
        sf = ol.signflip_pvalue(deltas, n_perm=PERM_N, seed=PERM_SEED)
        if sf is None:
            ce["verdict"] = "ZWAK"
            continue
        ce["perm_p"] = sf["p"]
        ce["perm_n"] = sf["n"]
        ce["perm_floor"] = sf["floor"]
        if sf["floor"] > p_req:
            ce["verdict"] = "KAN_NIET_CERTIFICEREN"
        elif ol.sidak(sf["p"], n_hyp) >= PERM_ALPHA:
            ce["verdict"] = "AFGEWEZEN_TOEVAL"
        else:
            ce["perm_p_corr"] = round(ol.sidak(sf["p"], n_hyp), 4)

    report["toeval_toets"] = {
        "n_hyp": n_hyp,
        "p_req": round(p_req, 6) if p_req else None,
        "alpha": PERM_ALPHA,
        "n_perm": PERM_N,
    }

    for ce, _ in all_candidates:
        report["candidates"].append(ce)

    # Winnaars: beste GLOBAAL_SAFE per (rule, knop) op train-breedte
    by_rk = defaultdict(list)
    for ce in report["candidates"]:
        if ce["verdict"] == "GLOBAAL_SAFE":
            by_rk[(ce["rule"], ce["knob"])].append(ce)
    for (rule, jk), cands in sorted(by_rk.items()):
        cands.sort(key=lambda c: (-c["train"]["score"], -c["train"]["median_netto"]))
        report["winners"][f"{rule}-{jk}"] = cands[0]

    # ── Leesbaar rapport ────────────────────────────────────────────────────────
    for rule in rules:
        if rule not in global_sl:
            continue
        gl = global_sl[rule]
        p(f"\n### RULE {rule} — default: " +
          " ".join(f"{JSON_KEY[k]}={gl[k]}" for k in grid))

        rule_cands = [c for c in report["candidates"] if c["rule"] == rule]
        for knob in grid:
            jk = JSON_KEY[knob]
            kc = sorted([c for c in rule_cands if c["knob"] == jk],
                        key=lambda c: -c["train"]["score"])
            if not kc:
                continue
            bk = f"{rule}-{jk}"
            winner = report["winners"].get(bk)
            p(f"\n  {jk} (nu {gl[knob]}):")
            for c in kc:
                tag = " *** BESTE" if winner and c["to"] == winner["to"] else ""
                pm = f" p={c['perm_p']:.4f}" if "perm_p" in c else ""
                p(f"    → {c['to']}: breedte train={c['train']['score']:+d} "
                  f"holdout={c['holdout']['score']:+d} "
                  f"({c['coins_measured']} munt, {c['coins_immune']} imm) "
                  f"[{c['verdict']}]{pm}{tag}")
                if c["holdout"]["harmed"]:
                    parts = [f"{n} ({c['per_coin'][n]['holdout_netto']:+.1f}%)"
                             for n in c["holdout"]["harmed"]]
                    p(f"      geschaad: {', '.join(parts)}")

    # Samenvatting
    winners = report["winners"]
    final_safe = sum(1 for ce in report["candidates"]
                     if ce["verdict"] == "GLOBAAL_SAFE")
    p("\n" + "=" * 78)
    p(f"SAMENVATTING: {len(winners)} winnaars (van {final_safe} GLOBAAL_SAFE, "
      f"{n_hyp} vóór toeval-toets, {len(report['candidates'])} totaal)")
    for bk in sorted(winners):
        w = winners[bk]
        pc = (f" p={w['perm_p']:.4f} (Šidák={w.get('perm_p_corr', '?')})"
              if "perm_p" in w else "")
        p(f"  rule {w['rule']} {w['knob']} {w['from']}→{w['to']}: "
          f"breedte holdout={w['holdout']['score']:+d} "
          f"mediaan {w['holdout']['median_netto']:+.1f}%{pc}")
        if w["holdout"]["harmed"]:
            parts = [f"{n} ({w['per_coin'][n]['holdout_netto']:+.1f}%)"
                     for n in w["holdout"]["harmed"]]
            p(f"    geschaad: {', '.join(parts)} → override-kandidaat")
    if not winners:
        p("  Geen gedeelde default verslaat legacy op breedte + holdout + toeval-toets.")

    # Opruimen
    for eng in engines.values():
        eng.close()
    conn.close()

    os.makedirs("out/opt", exist_ok=True)
    path = f"out/opt/sell_default_{datetime.date.today().isoformat()}.json"
    with open(path, "w") as f:
        json.dump(report, f, indent=2, default=str)
    report["report_path"] = path
    p(f"\nrapport → engine/src/{path}")
    return report


if __name__ == "__main__":
    sweep()
