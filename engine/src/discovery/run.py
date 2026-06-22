#!/usr/bin/env python3
"""
run.py — CLI-entrypoint van de Rule-Discovery Engine (Epic RD, Stap 5).

Keten per munt:  feature-tabel → segmenteren (pysubgroup) → funnel (CPCV-gestuurd) → validatie
(CPCV + toeval-toets + incrementeel) → compacte rapportage + oordeel tegen de 20-23-lat.

Bij twee munten: de op munt A gevonden rule wordt op munt B herfit en getoetst (de "2e munt"-check).

Draaien (vanuit engine/src):
    python -m discovery.run --coin DOGEAI
    python -m discovery.run --coin both          # beide munten + cross-coin
    python -m discovery.run --coin both --target good
"""
import argparse
import gc

from discovery.data import build_matrix
from discovery.segment import discover, print_catalog, cross_coin, top_structure
from discovery.funnel import run_funnel
from discovery.validate import validate, _refit_subrules
from discovery.report import print_report, compact_line

COINS = {"DOGEAI": 2525, "NOS": 244}


def discover_rule(dd, target, max_sub, n_perm, show_catalog=True):
    cat = discover(dd, target=target)
    if show_catalog:
        print_catalog(dd, cat, f"segmentatie (doel={target})")
    seed = top_structure(cat) if cat else None
    print(f"\n[seed] {'top-segment structuur (' + str(len(seed)) + ' features)' if seed else 'geen segment → start bij alle ticks'}")
    subrules, _mask, fstats = run_funnel(dd, seed_structure=seed, max_sub=max_sub, verbose=True)
    res = validate(dd, subrules, n_perm=n_perm, n_trials=fstats["n_sub"], verbose=True)
    return cat, subrules, res


def crosscoin_check(subrules, ddB, n_perm):
    """rule van munt A herfit op munt B en toetsen — de '2e munt'-poort uit het oordeel."""
    prom = ddB.df[(ddB.df["is_promising"]) & (ddB.df["group_id"] >= 0)]
    rf = _refit_subrules(ddB, subrules, prom)
    if rf is None:
        return None, (False, "n.v.t. (kon niet herfitten)")
    res = validate(ddB, rf, n_perm=n_perm, verbose=False)
    ok = res["mean"] > 0 and res["perm"]["p"] < 0.10 and res["sigma"] > 0
    return res, (ok, f"({ddB.name}: gem {res['mean']:+.3f}%/trade, p={res['perm']['p']:.3f}, Σ {res['sigma']:+.0f}%)")


def main():
    ap = argparse.ArgumentParser(description="Rule-Discovery Engine (Epic RD)")
    ap.add_argument("--coin", default="both", help="DOGEAI | NOS | both")
    ap.add_argument("--target", default="promising", help="promising | good | pl")
    ap.add_argument("--max-sub", type=int, default=45)
    ap.add_argument("--n-perm", type=int, default=2000)
    args = ap.parse_args()

    names = list(COINS) if args.coin == "both" else [args.coin]
    rules, results, cats = {}, {}, {}
    # FASE 1 — ontdekken, één munt tegelijk in geheugen (anders OOM bij 2 munten)
    for nm in names:
        print("\n" + "#" * 78 + f"\n### {nm}\n" + "#" * 78)
        dd = build_matrix(COINS[nm], nm)
        cat, subrules, res = discover_rule(dd, args.target, args.max_sub, args.n_perm)
        rules[nm], results[nm], cats[nm] = subrules, res, cat
        del dd
        gc.collect()

    # FASE 2 — cross-coin (rule van A herfit op B) + rapport, opnieuw één munt tegelijk
    print("\n" + "=" * 78 + "\n  EINDRAPPORT\n" + "=" * 78)
    keepers = {}
    for nm in names:
        cross = None
        if len(names) == 2:
            other = [x for x in names if x != nm][0]
            ddO = build_matrix(COINS[other], other, verbose=False)
            _resB, cross = crosscoin_check(rules[nm], ddO, args.n_perm)
            del ddO
            gc.collect()
        keepers[nm] = print_report(results[nm], n_trials=results[nm]["n_sub"], cross=cross)

    if len(names) == 2:
        cross_coin(cats[names[0]], names[0], cats[names[1]], names[1])

    print("\n" + "=" * 78)
    print("  SAMENVATTING")
    for nm in names:
        print("   " + compact_line(results[nm]) + ("   ← KEEPER" if keepers[nm] else ""))
    if not any(keepers.values()):
        print("\n  Geen rule haalt de 20-23-lat op deze munten — met pysubgroup + CPCV. Het 2-munten-")
        print("  plafond is daarmee verdiend met het juiste gereedschap; de hefboom is meer munten (Epic 07).")


if __name__ == "__main__":
    main()
