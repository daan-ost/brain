#!/usr/bin/env python3
"""
funnel.py — subregels stapelen met CPCV-gestuurde keuze (Epic RD, Stap 3).

DE KERN-FIX t.o.v. de proef. De ruwe funnel (parent_funnel.py) koos elke subregel op de TRAIN-trefkans
met één vroeg→laat-splitsing → de trefkans op de apart-gehouden testperiode zakte naar 6-7% (vastpinnen
op toeval). Hier kiezen we elke volgende subregel op de **trefkans BUITEN de trainingsdata**, gemeten via
CPCV-tijdblokken: de drempel wordt afgesteld op de train-blokken en de trefkans gemeten op het
apart-gehouden blok. We STOPPEN zodra geen enkele subregel verder indikt zónder die buiten-trefkans te
laten instorten — precies het overfit-signaal dat de proef miste.

Tijdens het indikken kijken we NIET naar goed/slecht (alleen trefkans + selectiviteit); winst meet
validate.py/report.py pas op het eind. Volgorde-pool zoals 20-23: volume → indicator-waarde → prijs.

Draaien (vanuit engine/src):  python -m discovery.funnel [symbol naam]
"""
import sys

import numpy as np

from discovery.data import build_matrix


def _percentile(vals, side):
    """drempel uit de promising-tick-verdeling: ge → p10 (houd top 90%), le → p90 (houd onder 90%)."""
    return float(np.percentile(vals, 10 if side == "ge" else 90))


def run_funnel(dd, seed_structure=None, n_blocks=5, recall_floor=0.7, abs_floor=0.10,
               target_sel=0.001, max_sub=30, verbose=True):
    """Stapel subregels met reproduceerbare percentiel-drempels, CPCV-gestuurd. Geef (subrules, mask,
    stats). seed_structure = lijst (col, side) uit pysubgroup (alleen STRUCTUUR; drempels hier gezet)."""
    blocks = dd.blocks(n_blocks)
    day2block = {d: b for b, ds in enumerate(blocks) for d in ds}
    group_block = np.array([day2block.get(g[0].date(), -1) for g in dd.groups])
    block_groups = {b: [gi for gi in range(len(dd.groups)) if group_block[gi] == b]
                    for b in range(len(blocks))}
    block_groups = {b: gs for b, gs in block_groups.items() if gs}

    prom = dd.df[(dd.df["is_promising"]) & (dd.df["group_id"] >= 0)]
    pgb = group_block[prom["group_id"].to_numpy()]
    cols = dd.features
    pvals = {c: prom[c].to_numpy(dtype=float) for c in cols}
    finite = {c: np.isfinite(dd.col_arrays[c]) for c in cols}

    # seed = alleen STRUCTUUR (col, side) uit pysubgroup; drempel hier via dezelfde percentiel-regel
    current, used = [], set()
    for (col, side) in (seed_structure or []):
        if col in used or col not in pvals:
            continue
        pv = pvals[col][np.isfinite(pvals[col])]
        if len(pv) < 8:
            continue
        if side == "band":
            current.append((col, "band", float(np.percentile(pv, 10)), float(np.percentile(pv, 90))))
        else:
            thr = _percentile(pv, side)
            current.append((col, "ge", thr, None) if side == "ge" else (col, "le", None, thr))
        used.add(col)
    cur_mask = dd.mask(current) if current else np.ones(dd.tot, dtype=bool)

    def oos_fixed(mask):
        return float(np.mean([dd.recall_mask(mask, gs) for gs in block_groups.values()]))

    cur_oos = oos_fixed(cur_mask)
    cur_sel = dd.selectivity(cur_mask)
    cur_rec = dd.recall_mask(cur_mask)
    if verbose:
        print(f"\n################ {dd.name} — FUNNEL (CPCV-gestuurd, {len(block_groups)} blokken) ################")
        seedtxt = f"{len(current)} seed-subregels" if current else "alle ticks"
        print(f"  start ({seedtxt}): trefkans {100*cur_rec:.0f}% | OOS-trefkans {100*cur_oos:.0f}% | "
              f"selectiviteit {100*cur_sel:.3f}%")

    for step in range(max_sub):
        best = None
        for col in cols:
            if col in used:
                continue
            vc = dd.col_arrays[col]
            fin = finite[col]
            pv_all = pvals[col][np.isfinite(pvals[col])]
            if len(pv_all) < 8:
                continue
            for side in ("ge", "le"):
                # CPCV-eerlijke OOS-trefkans: drempel uit train-blokken, trefkans op het test-blok
                oos = []
                ok = True
                for b, tg in block_groups.items():
                    tr = (pgb != b) & np.isfinite(pvals[col])
                    if tr.sum() < 8:
                        ok = False
                        break
                    thr_b = _percentile(pvals[col][tr], side)
                    mb = cur_mask & fin & (vc >= thr_b if side == "ge" else vc <= thr_b)
                    oos.append(dd.recall_mask(mb, tg))
                if not ok or not oos:
                    continue
                oos_m = float(np.mean(oos))
                if oos_m < recall_floor * cur_oos or oos_m < abs_floor:
                    continue                       # mag de buiten-trefkans niet laten instorten
                # ingezette drempel uit de VOLLE promising-set; selectiviteit over alle vdt-ticks
                thr_full = _percentile(pv_all, side)
                nm = cur_mask & fin & (vc >= thr_full if side == "ge" else vc <= thr_full)
                sel = dd.selectivity(nm)
                if sel >= cur_sel:                 # moet indikken
                    continue
                if best is None or sel < best[0]:
                    sr = (col, "ge", thr_full, None) if side == "ge" else (col, "le", None, thr_full)
                    best = (sel, oos_m, sr, nm)
        if best is None:
            if verbose:
                print("  -> geen subregel dikt verder in zonder de OOS-trefkans te laten instorten (STOP).")
            break
        sel, oos_m, sr, nm = best
        current.append(sr)
        used.add(sr[0])
        cur_mask, cur_sel, cur_oos = nm, sel, oos_m
        cur_rec = dd.recall_mask(cur_mask)
        if verbose:
            col, side, lo, hi = sr
            cond = f">={lo:.3g}" if side == "ge" else f"<={hi:.3g}"
            print(f"  +{col} {cond:>12s} | trefkans {100*cur_rec:3.0f}% | OOS {100*cur_oos:3.0f}% | "
                  f"selectiviteit {100*cur_sel:.3f}% ({int(cur_mask.sum())} ticks)")
        if cur_sel <= target_sel:
            if verbose:
                print("  -> 20-23-selectiviteit (<=0,1%) bereikt (STOP).")
            break

    stats = dict(selectivity=cur_sel, oos_recall=cur_oos, recall=cur_rec, n_sub=len(current),
                 n_ticks=int(cur_mask.sum()))
    return current, cur_mask, stats


def main():
    syms = [(2525, "DOGEAI"), (244, "NOS")]
    if len(sys.argv) > 1:
        syms = [(int(sys.argv[1]), sys.argv[2] if len(sys.argv) > 2 else sys.argv[1])]
    for sym, nm in syms:
        dd = build_matrix(sym, nm)
        run_funnel(dd)


if __name__ == "__main__":
    main()
