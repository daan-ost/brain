#!/usr/bin/env python3
"""
pooled.py — COIN-AGNOSTISCHE ontdekking: ÉÉN rule met GEDEELDE banden voor ALLE munten (Epic RD).

Niet "een rule per munt" (dat schendt het uitgangspunt), maar precies hoe 20-23 werken: één rule_number,
**globale banden**, die op DOGEAI, NOS én elke toekomstige munt geldt. Dat vereist twee dingen:

  1. **Alleen schaal-invariante features** — relatieve/% en vorm-metrics (skewness, range_percentage,
     volatility, sum_average_positive_percentage, diff_previous_value, consecutive, reversal_count,
     sideways) op alle indicatoren. Ruwe-magnitude-metrics (standard_deviation, diff_lowest_value_period)
     vallen eruit: hun absolute schaal verschilt per munt → onbruikbaar als gedeelde band.
  2. **Gedeelde drempels** — gepoold over alle munten (percentiel over de samengevoegde promising-ticks).
     Dezelfde band geldt voor elke munt; bij een nieuwe munt verandert er niets.

De funnel neemt een (feature, side, gedeelde drempel) alleen op als hij op ÁLLE munten tegelijk indikt én
de trefkans buiten de trainingsdata overeind houdt. Verlies-reductie idem (gedeelde drempel uit gepoolde
winnende trades). Validatie per munt met de VASTE gedeelde rule (refit=False).

Draaien (vanuit engine/src):  python -m discovery.pooled
"""
import bisect
import json
import os
import sys

import numpy as np

from discovery.data import build_matrix
from discovery.validate import validate
from discovery.report import print_report, compact_line
from parent_eval import faithful_trades

# schaal-invariant = relatief/% of vorm of begrensd → één gedeelde band geldt voor elke munt
SCALE_INVARIANT = {"skewness", "range_percentage", "volatility", "consecutive_increases",
                   "consecutive_decreases", "reversal_count", "diff_previous_value",
                   "sum_average_positive_percentage", "sideways_upper", "sideways_lower"}

# indicatoren die AL per munt genormaliseerd zijn (relvol = volumeud / min_volume) → ook hun
# GROOTTE-metrics (standard_deviation, diff_lowest_value_period) mogen als gedeelde band mee.
# Dit brengt het enige bewezen signaal (volume-grootte) coin-agnostisch terug.
SCALE_INVARIANT_INDS = {"relvol"}


def scale_invariant_cols(features):
    out = []
    for c in features:
        parts = c.split("|")
        ind, m = parts[0], parts[2]
        if ind in SCALE_INVARIANT_INDS or m in SCALE_INVARIANT:
            out.append(c)
    return out


def _pct(vals, side):
    return float(np.percentile(vals, 10 if side == "ge" else 90))


def structure_to_subrules(structure):
    """[(col, side, thr)] → [(col, side, lo, hi)] (gedeelde banden, voor elke munt gelijk)."""
    out = []
    for (col, side, thr) in structure:
        out.append((col, "ge", thr, None) if side == "ge" else (col, "le", None, thr))
    return out


class CoinState:
    def __init__(self, dd, n_blocks, keep_groups=None):
        self.dd = dd
        keep = set(range(len(dd.groups))) if keep_groups is None else set(keep_groups)
        self.keep_groups = keep
        blocks = dd.blocks(n_blocks)
        d2b = {d: b for b, ds in enumerate(blocks) for d in ds}
        gb = np.array([d2b.get(g[0].date(), -1) for g in dd.groups])
        bg = {b: [gi for gi in range(len(dd.groups)) if gb[gi] == b and gi in keep] for b in range(len(blocks))}
        self.block_groups = {b: gs for b, gs in bg.items() if gs}
        prom = dd.df[(dd.df["is_promising"]) & (dd.df["group_id"] >= 0) & (dd.df["group_id"].isin(keep))]
        self.pvals = {c: prom[c].to_numpy(dtype=float) for c in dd.features}
        self.finite = {c: np.isfinite(dd.col_arrays[c]) for c in dd.features}
        self.cur_mask = np.ones(dd.tot, dtype=bool)
        self.cur_oos = self._oos(self.cur_mask)
        self.cur_sel = 1.0
        self.cur_rec = 1.0

    def _oos(self, mask):
        return float(np.mean([self.dd.recall_mask(mask, gs) for gs in self.block_groups.values()]))

    def cand_eval(self, col, side, thr, recall_floor, abs_floor):
        """met de GEDEELDE drempel thr: geef (nieuw_mask, sel, oos) als deze munt geldig indikt."""
        vc = self.dd.col_arrays[col]
        fin = self.finite[col]
        nm = self.cur_mask & fin & (vc >= thr if side == "ge" else vc <= thr)
        oos = float(np.mean([self.dd.recall_mask(nm, gs) for gs in self.block_groups.values()]))
        if oos < recall_floor * self.cur_oos or oos < abs_floor:
            return None
        sel = float(nm.mean())
        if sel >= self.cur_sel:
            return None
        return nm, sel, oos

    def apply(self, nm, sel, oos):
        self.cur_mask, self.cur_sel, self.cur_oos = nm, sel, oos
        self.cur_rec = self.dd.recall_mask(nm)


def refine_quality(states, base_subrules, n_blocks=5, max_add=15, keep_floor=0.30, verbose=True):
    """Verlies-reductie met GEDEELDE drempels (gepoolde winnende trades). Geef [(col,side,thr)]."""
    names = list(states)
    cols = scale_invariant_cols(states[names[0]].dd.features)
    TS = {}
    for nm in names:
        st = states[nm]
        trades, _ = faithful_trades(st.dd.eng, st.dd.A, st.dd.survivors(base_subrules))
        if not trades:
            return []
        idx = np.clip([bisect.bisect_left(st.dd.vdt, t["buy_dt"]) for t in trades], 0, st.dd.tot - 1)
        pls = np.array([t["pl"] for t in trades])
        days = [t["buy_dt"].date() for t in trades]
        udays = sorted(set(days))
        edges = np.linspace(0, len(udays), n_blocks + 1).astype(int)
        d2b = {d: b for b in range(n_blocks) for d in udays[edges[b]:edges[b + 1]]}
        TS[nm] = dict(st=st, idx=np.array(idx), pls=pls, blk=np.array([d2b[d] for d in days]),
                      n0=len(pls))

    def oos_mean(pls, blk, mask):
        ms = [pls[(blk == b) & mask].mean() for b in range(n_blocks) if ((blk == b) & mask).sum() > 0]
        return float(np.mean(ms)) if ms else -1e9

    cur = {nm: np.ones(TS[nm]["n0"], bool) for nm in names}
    cur_oos = {nm: oos_mean(TS[nm]["pls"], TS[nm]["blk"], cur[nm]) for nm in names}
    added, used = [], {c for (c, _s, _t) in []}
    if verbose:
        print("\n  -- verlies-reductie (gedeelde drempels, beide munten) --")
    for step in range(max_add):
        best = None
        for col in cols:
            if col in used:
                continue
            for side in ("ge", "le"):
                # gedeelde drempel uit de GEPOOLDE huidige winnende trades
                pooled_win = []
                for nm in names:
                    T = TS[nm]
                    vals = T["st"].dd.col_arrays[col][T["idx"]].astype(float)
                    w = cur[nm] & np.isfinite(vals) & (T["pls"] >= 0)
                    pooled_win.append(vals[w])
                pooled_win = np.concatenate(pooled_win) if pooled_win else np.array([])
                if len(pooled_win) < 16:
                    continue
                thr = _pct(pooled_win, side)
                ok, per = True, {}
                for nm in names:
                    T = TS[nm]
                    vals = T["st"].dd.col_arrays[col][T["idx"]].astype(float)
                    fin = np.isfinite(vals)
                    oos = []
                    for b in range(n_blocks):
                        ts = (T["blk"] == b) & cur[nm] & fin & (vals >= thr if side == "ge" else vals <= thr)
                        if ts.sum() > 0:
                            oos.append(T["pls"][ts].mean())
                    oos_m = float(np.mean(oos)) if oos else -1e9
                    nmask = cur[nm] & fin & (vals >= thr if side == "ge" else vals <= thr)
                    if oos_m <= cur_oos[nm] or nmask.sum() / T["n0"] < keep_floor:
                        ok = False
                        break
                    per[nm] = (oos_m, nmask)
                if not ok:
                    continue
                score = sum(per[nm][0] - cur_oos[nm] for nm in names)
                if best is None or score > best[0]:
                    best = (score, col, side, thr, per)
        if best is None:
            if verbose:
                print("     -> geen gedeelde subregel verlaagt verliezers OOS op beide munten (STOP).")
            break
        _s, col, side, thr, per = best
        for nm in names:
            cur[nm], cur_oos[nm] = per[nm][1], per[nm][0]
        added.append((col, side, thr))
        used.add(col)
        if verbose:
            print(f"     +{col} {'≥' if side == 'ge' else '≤'} {thr:.3g} | "
                  + " | ".join(f"{nm}: {int(cur[nm].sum())}/{TS[nm]['n0']} trades, OOS-gem {cur_oos[nm]:+.3f}%"
                               for nm in names))
    return added


def run_pooled(coins, n_blocks=5, recall_floor=0.7, abs_floor=0.10, target_sel=0.001,
               max_sub=45, n_perm=2000, refine=True, prev_subrules=None, rule_label="30",
               out_name="pooled_rule.json", vol_bases=None, whitespace_rules=None):
    """vol_bases: optionele {name: vol_base} om de relvol-basislijn per munt te overschrijven
    (gevoeligheids-check; None = laagste min_volume uit coin_rule_settings).
    whitespace_rules: optionele tuple live rule-nummers (bv. (20,21,22,23,30)); zo ja, zoek de nieuwe
    rule op de WITTE promising-groepen = groepen waar nog GEEN live executed trade van die rules op zit
    (Daans vaste werkwijze: prioriteer de grootste witte vlek; zie docs/methodology/rule-discovery.md)."""
    vol_bases = vol_bases or {}
    syms = {nm: sym for sym, nm in coins}
    states = {}
    for sym, nm in coins:
        dd = build_matrix(sym, nm, vol_base=vol_bases.get(nm))
        keep = None
        if whitespace_rules:                    # witte ruimte: groepen zonder live trade (20-30)
            from discovery.whitespace import live_trade_times
            covered = dd.groups_hit(live_trade_times(sym, whitespace_rules))
            keep = set(range(len(dd.groups))) - covered
            print(f"  [{nm}] rule {rule_label}: {len(keep)}/{len(dd.groups)} promising groepen nog WIT "
                  f"(geen live trade van {whitespace_rules})")
        elif prev_subrules:                     # sequential covering: zoek op de NOG ONBEDEKTE groepen
            covered = dd.groups_hit(dd.survivors(prev_subrules))
            keep = set(range(len(dd.groups))) - covered
            print(f"  [{nm}] rule {rule_label}: {len(keep)}/{len(dd.groups)} promising groepen nog onbedekt "
                  f"door de vorige rule(s)")
        states[nm] = CoinState(dd, n_blocks, keep_groups=keep)
    names = list(states)
    cols = scale_invariant_cols(states[names[0]].dd.features)
    pooled_pvals = {c: np.concatenate([states[nm].pvals[c] for nm in names]) for c in cols}

    print(f"\n################ COIN-AGNOSTISCH — één gedeelde rule over {', '.join(names)} ################")
    print(f"  schaal-invariante features: {len(cols)} | start: "
          + " | ".join(f"{nm} sel {100*states[nm].cur_sel:.0f}%" for nm in names))

    structure, used = [], set()
    for step in range(max_sub):
        best = None
        for col in cols:
            if col in used:
                continue
            pv = pooled_pvals[col][np.isfinite(pooled_pvals[col])]
            if len(pv) < 16:
                continue
            for side in ("ge", "le"):
                thr = _pct(pv, side)                       # GEDEELDE drempel (gepoold over munten)
                per, ok = {}, True
                for nm in names:
                    r = states[nm].cand_eval(col, side, thr, recall_floor, abs_floor)
                    if r is None:
                        ok = False
                        break
                    per[nm] = r
                if not ok:
                    continue
                score = sum(per[nm][1] for nm in names)
                if best is None or score < best[0]:
                    best = (score, col, side, thr, per)
        if best is None:
            print("  -> geen gedeelde feature dikt ALLE munten verder in zonder instorting (STOP).")
            break
        _s, col, side, thr, per = best
        for nm in names:
            states[nm].apply(*per[nm])
        structure.append((col, side, thr))
        used.add(col)
        print(f"  +{col} {'≥' if side == 'ge' else '≤'} {thr:.3g} (gedeeld) | "
              + " | ".join(f"{nm}: tref {100*states[nm].cur_rec:.0f}% OOS {100*states[nm].cur_oos:.0f}% "
                           f"sel {100*states[nm].cur_sel:.3f}%" for nm in names))
        if all(states[nm].cur_sel <= target_sel for nm in names):
            print("  -> 20-23-selectiviteit op alle munten bereikt (STOP).")
            break

    base_subrules = structure_to_subrules(structure)
    added = refine_quality(states, base_subrules, n_blocks=n_blocks) if (refine and structure) else []
    shared_subrules = base_subrules + structure_to_subrules(added)

    print("\n" + "=" * 78 + "\n  EINDRAPPORT — ÉÉN gedeelde rule (zelfde banden voor alle munten)\n" + "=" * 78)
    print(f"  {len(structure)} gedeelde subregels + {len(added)} verlies-reductie = {len(shared_subrules)} totaal")
    keepers, results = {}, {}
    for nm in names:
        res = validate(states[nm].dd, shared_subrules, n_perm=n_perm, n_trials=len(shared_subrules),
                       refit=False, verbose=False)
        results[nm] = res
        keepers[nm] = print_report(res, n_trials=len(shared_subrules))

    print("\n" + "=" * 78 + "\n  SAMENVATTING (één coin-agnostische rule)")
    for nm in names:
        print("   " + compact_line(results[nm]) + ("   ← haalt de lat" if keepers[nm] else ""))
    if not structure:
        print("\n   Geen gedeelde schaal-invariante structuur die alle munten indikt — op 2 munten niet")
        print("   haalbaar; hefboom = meer munten (Epic 07).")
    elif all(keepers.values()):
        print("\n   >>> COIN-AGNOSTISCHE KEEPER: één rule haalt de 20-23-lat op ALLE munten.")
    else:
        print("\n   >>> GEEN KEEPER — de gedeelde rule is netto winstgevend maar haalt de strikte lat niet")
        print("   (zelfde band op beide munten; trade-kwaliteitsmix is de bindende grens).")

    out = {"shared": True, "structure": [[c, s, t] for c, s, t in structure],
           "added": [[c, s, t] for c, s, t in added],
           "subrules": [[col, side, lo, hi] for (col, side, lo, hi) in shared_subrules],
           "coins": {nm: {"symbol": syms[nm], "compact": compact_line(results[nm]),
                          "keeper": bool(keepers[nm]), "selectivity": results[nm]["selectivity"],
                          "mean": results[nm]["mean"], "sigma": results[nm]["sigma"],
                          "n_trades": results[nm]["n_trades"],
                          "cls": {k: int(results[nm]["cls"][k]) for k in ("goed", "middel", "slecht")},
                          "cpcv_oos": results[nm]["cpcv"]["mean_oos"], "perm_p": results[nm]["perm"]["p"],
                          "incr": {k: results[nm]["incr"][k] for k in
                                   ("added", "added_good", "added_bad", "d_sigma", "base_n", "base_sigma")}}
                     for nm in names}}
    cache_dir = os.path.join(os.path.dirname(__file__), ".cache")
    os.makedirs(cache_dir, exist_ok=True)
    with open(os.path.join(cache_dir, out_name), "w") as f:
        json.dump(out, f, indent=2)
    print(f"\n  [opgeslagen] {os.path.join(cache_dir, out_name)} (rule {rule_label})")
    return structure, results, keepers, shared_subrules


def main():
    import argparse
    ap = argparse.ArgumentParser(description="Coin-agnostische rule-ontdekking (gedeelde banden)")
    ap.add_argument("--round2", action="store_true",
                    help="zoek de VOLGENDE rule (31) op de groepen die rule 30 nog niet dekt (sequential covering)")
    ap.add_argument("--whitespace", action="store_true",
                    help="zoek de volgende rule (31) op de WITTE promising-groepen = geen live trade van "
                         "20-30 (Daans vaste werkwijze: prioriteer de grootste witte vlek)")
    ap.add_argument("--rule", default="31", help="rule-label voor de output (default 31)")
    args = ap.parse_args()
    coins = [(2525, "DOGEAI"), (244, "NOS")]
    if args.whitespace:
        run_pooled(coins, whitespace_rules=(20, 21, 22, 23, 30), rule_label=args.rule,
                   out_name=f"pooled_rule_{args.rule}.json")
    elif args.round2:
        prev = json.load(open(os.path.join(os.path.dirname(__file__), ".cache", "pooled_rule.json")))
        prev_subs = [(c, s, lo, hi) for (c, s, lo, hi) in prev["subrules"]]
        run_pooled(coins, prev_subrules=prev_subs, rule_label="31", out_name="pooled_rule_31.json")
    else:
        run_pooled(coins)


if __name__ == "__main__":
    main()
