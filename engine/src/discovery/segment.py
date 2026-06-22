#!/usr/bin/env python3
"""
segment.py — segmenteren van de goede trades via Subgroup Discovery (Epic RD, Stap 2).

NIEUW t.o.v. de proef: i.p.v. met-de-hand-gemaakte natuurlijke splitsingen gebruiken we pysubgroup —
een gevestigde bibliotheek die conjuncties van feature-banden zoekt met ingebouwde kwaliteits-maten en
non-redundantie. Doel = `is_promising`: vind beschrijfbare deelverzamelingen waar de goede ticks
oververtegenwoordigd zijn t.o.v. de achtergrond. De pocket-proef faalde op ÉÉN feature (zelfs een venster
dat 87% van de groepen ving pakte 81% achtergrond); pysubgroup bouwt meervoudige banden → kan een
promising-dichte ~10-25%-plak vinden die geen enkele losse feature geeft.

DISCIPLINE: een gevonden segment is een HYPOTHESE, geen bewijs. Het aantal geprobeerde kandidaten is
groot → de ruisvloer stijgt. Een segment telt pas als het door funnel (CPCV) + validate (toeval-toets +
PBO) komt. Hier alleen: de catalogus + per segment groep-dekking, precisie en selectiviteit over ALLE ticks.

Draaien (vanuit engine/src):  python -m discovery.segment [symbol naam]
"""
import os
os.environ.setdefault("NUMBA_DISABLE_JIT", "1")   # numba/llvmlite SIGBUST op Apple Silicon; JIT niet nodig

import sys

import numpy as np
import pandas as pd
import pysubgroup as ps

from discovery.data import build_matrix, feature_cols

IGNORE = ["is_promising", "group_id", "pl", "good", "dt", "day"]


def _sel_subrule(sel):
    """pysubgroup-selector → (col, side, lo, hi). Numerieke features = IntervalSelector."""
    name = getattr(sel, "attribute_name", None)
    lo = getattr(sel, "lower_bound", None)
    hi = getattr(sel, "upper_bound", None)
    if name is None:
        return None
    lo_f = lo is not None and np.isfinite(lo)
    hi_f = hi is not None and np.isfinite(hi)
    if lo_f and hi_f:
        return (name, "band", float(lo), float(hi))
    if lo_f:
        return (name, "ge", float(lo), None)
    if hi_f:
        return (name, "le", None, float(hi))
    return None


def subrules_of(sg):
    """Conjunction → lijst subregels (col, side, lo, hi), dedup per kolom-richting."""
    out = []
    for sel in getattr(sg, "selectors", []):
        sr = _sel_subrule(sel)
        if sr:
            out.append(sr)
    return out


def df_match(df, subrules):
    m = pd.Series(True, index=df.index)
    for (col, side, lo, hi) in subrules:
        v = df[col]
        ok = v.notna()
        if side == "ge":
            ok &= v >= lo
        elif side == "le":
            ok &= v <= hi
        else:
            ok &= (v >= lo) & (v <= hi)
        m &= ok
    return m


def fmt_subrules(subrules):
    parts = []
    for (col, side, lo, hi) in subrules:
        if side == "ge":
            parts.append(f"{col}>={lo:.3g}")
        elif side == "le":
            parts.append(f"{col}<={hi:.3g}")
        else:
            parts.append(f"{col}∈[{lo:.3g},{hi:.3g}]")
    return "  ∧  ".join(parts) if parts else "(leeg)"


def discover(dd, target="promising", depth=3, top=40, nbins=6, min_cov=0.08, max_cov=0.40):
    """Run pysubgroup; geef gefilterde, gerangschikte catalogus van segmenten (lijst dicts)."""
    df = dd.df
    cols = feature_cols()
    if target == "promising":
        work = df.copy()
        work["is_promising"] = work["is_promising"].astype(bool)
        tgt = ps.BinaryTarget("is_promising", True)
        qf = ps.StandardQF(0.5)
    elif target == "good":
        work = df.dropna(subset=["good"]).copy()
        work["good"] = work["good"].astype(bool)
        tgt = ps.BinaryTarget("good", True)
        qf = ps.StandardQF(0.5)
    elif target == "pl":
        work = df.dropna(subset=["pl"]).copy()
        tgt = ps.NumericTarget("pl")
        qf = ps.StandardQFNumeric(0.5)
    else:
        raise ValueError(target)

    space = ps.create_selectors(work[cols], nbins=nbins)
    task = ps.SubgroupDiscoveryTask(work, tgt, space, result_set_size=top, depth=depth, qf=qf)
    result = ps.BeamSearch(beam_width=max(top, 30)).execute(task)

    ng = len(dd.groups)
    out = []
    for q, sg in result.to_descriptions():
        subs = subrules_of(sg)
        if not subs:
            continue
        m = df_match(df, subs)                       # over de hele tabel (promising + bg)
        cov_groups = df.loc[m & (df["group_id"] >= 0), "group_id"].nunique()
        cov = cov_groups / ng if ng else 0.0
        n_match = int(m.sum())
        prec = float(df.loc[m, "is_promising"].mean()) if n_match else 0.0
        sel = float(dd.mask(subs).mean())            # selectiviteit over ALLE vdt-ticks
        out.append(dict(q=float(q), subrules=subs, cov=cov, cov_groups=int(cov_groups),
                        n_match=n_match, precision=prec, selectivity=sel))
    # filter op groep-dekking (een segment = een PLAK, niet alles/niets) en rangschik op kwaliteit
    cat = [s for s in out if min_cov <= s["cov"] <= max_cov]
    cat.sort(key=lambda s: -s["q"])
    return cat


def print_catalog(dd, cat, label):
    print(f"\n=== {dd.name}: {label} — {len(cat)} segmenten (10-40% groep-dekking) ===")
    print(f"  {'q':>7s} {'grp%':>5s} {'prec%':>6s} {'sel%':>6s}  segment")
    for s in cat[:15]:
        print(f"  {s['q']:7.4f} {100*s['cov']:4.0f}% {100*s['precision']:5.0f}% {100*s['selectivity']:6.3f}  "
              f"{fmt_subrules(s['subrules'])}")
    if not cat:
        print("  (geen segment in de 10-40% dekkingsband)")


def top_structure(cat, k=1):
    """(col, side) van de top-k segmenten als seed-STRUCTUUR voor de funnel (drempels zet de funnel
    zelf via percentielen). Dedup per kolom, volgorde behouden."""
    out, seen = [], set()
    for seg in cat[:k]:
        for (col, side, _lo, _hi) in seg["subrules"]:
            if col in seen:
                continue
            seen.add(col)
            out.append((col, side))
    return out


def cross_coin(cat_a, name_a, cat_b, name_b):
    """features (kolom) die in de top-segmenten van BEIDE munten voorkomen = coin-agnostisch kenmerk."""
    def feats(cat):
        s = set()
        for seg in cat[:15]:
            for (col, _side, _lo, _hi) in seg["subrules"]:
                s.add(col)
        return s
    shared = feats(cat_a) & feats(cat_b)
    print(f"\n=== CROSS-COIN: features in de top-segmenten van BEIDE munten ({name_a} ∩ {name_b}) ===")
    if shared:
        for c in sorted(shared):
            print(f"  {c}")
    else:
        print("  (geen overlap in de top-15 segmenten — segmenten zijn munt-specifiek)")
    return shared


def main():
    syms = [(2525, "DOGEAI"), (244, "NOS")]
    if len(sys.argv) > 1:
        syms = [(int(sys.argv[1]), sys.argv[2] if len(sys.argv) > 2 else sys.argv[1])]
    cats = {}
    for sym, nm in syms:
        dd = build_matrix(sym, nm)
        cat = discover(dd, target="promising")
        print_catalog(dd, cat, "segmentatie (doel=promising)")
        cats[nm] = cat
    if len(cats) == 2:
        (na, ca), (nb, cb) = list(cats.items())
        cross_coin(ca, na, cb, nb)


if __name__ == "__main__":
    main()
