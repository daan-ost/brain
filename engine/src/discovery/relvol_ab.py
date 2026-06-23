#!/usr/bin/env python3
"""
relvol_ab.py — zuivere A/B op rule 30: MET vs ZONDER de relvol-band, in identieke code + data.

Verschil = alleen of de funnel de relvol-grootte als gedeelde band MAG gebruiken. Run A leegt
SCALE_INVARIANT_INDS (relvol valt uit de kandidaten — exact de pre-relvol situatie); run B is de
huidige rule 30. Zelfde matrices (gecached, gedeeld), zelfde seed/parameters, zodat het enige verschil
de relvol-feature is. Rapporteert compact per munt naast elkaar.

Draaien (vanuit engine/src):  NUMBA_DISABLE_JIT=1 python -m discovery.relvol_ab
"""
import discovery.pooled as P

COINS = [(2525, "DOGEAI"), (244, "NOS")]


def _stand(results):
    out = {}
    for nm in ("DOGEAI", "NOS"):
        r = results[nm]
        cls = r["cls"]
        tot = cls["goed"] + cls["middel"] + cls["slecht"]
        out[nm] = dict(sel=100 * r["selectivity"], mean=r["mean"],
                       bad=100 * cls["slecht"] / tot if tot else 0,
                       good=100 * cls["goed"] / tot if tot else 0,
                       cpcv=r["cpcv"]["mean_oos"], p=r["perm"]["p"], n=r["n_trades"],
                       g=cls["goed"], m=cls["middel"], b=cls["slecht"], sigma=r["sigma"])
    return out


if __name__ == "__main__":
    runs = {}

    print("\n" + "#" * 90 + "\n###### A — rule 30 ZONDER relvol (relvol uit de gedeelde-band-kandidaten) ######\n" + "#" * 90)
    P.SCALE_INVARIANT_INDS = set()
    _s, resA, _k, _sub = P.run_pooled(COINS, n_perm=1000, out_name="pooled_rule_ab_zonder.json")
    runs["zonder relvol"] = _stand(resA)

    print("\n" + "#" * 90 + "\n###### B — rule 30 MET relvol (huidige rule 30) ######\n" + "#" * 90)
    P.SCALE_INVARIANT_INDS = {"relvol"}
    _s, resB, _k, _sub = P.run_pooled(COINS, n_perm=1000, out_name="pooled_rule_ab_met.json")
    runs["met relvol"] = _stand(resB)

    print("\n" + "=" * 90)
    print("  A/B — verbetert relvol rule 30? (zelfde data + code, alleen relvol aan/uit)")
    print("=" * 90)
    for nm in ("DOGEAI", "NOS"):
        print(f"\n  {nm}:")
        print(f"    {'variant':16s} | {'sel%':>6s} | {'gem/trade':>10s} | {'goed/mid/slecht':>16s} | "
              f"{'slecht%':>7s} | {'CPCV':>8s} | {'Σ%':>6s} | p")
        for label in ("zonder relvol", "met relvol"):
            d = runs[label][nm]
            print(f"    {label:16s} | {d['sel']:6.3f} | {d['mean']:+9.3f}% | "
                  f"{d['g']:3d}/{d['m']:3d}/{d['b']:3d}      | {d['bad']:6.0f}% | {d['cpcv']:+7.3f}% | "
                  f"{d['sigma']:+5.0f} | {d['p']:.3f}")
        a, b = runs["zonder relvol"][nm], runs["met relvol"][nm]
        print(f"    Δ (met − zonder) : gem {b['mean']-a['mean']:+.3f}%/trade | "
              f"slecht {b['bad']-a['bad']:+.0f}pp | CPCV {b['cpcv']-a['cpcv']:+.3f}%")
