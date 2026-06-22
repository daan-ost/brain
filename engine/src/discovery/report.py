#!/usr/bin/env python3
"""
report.py — compacte rapportage + het oordeel tegen de 20-23-lat (Epic RD, Stap 5).

Vast format ([[feedback-compact-result-format]]):
    {munt}: {N}/{M} promising groepen | goed g / middel md / slecht b | Σprofit ±x%

Oordeel ([[docs/methodology/rule-discovery.md]] §5): de lat is 20-23, NIET de willekeurige nullijn.
KEEPER = selectief (≤~0,1% ticks) + winstgevend (≥+0,7%/trade) + weinig slecht (≤45%) + genoeg goed
(≥19%) + Σ>0 + standhoudt op CPCV (buiten-data winst>0) + toeval-toets p<0,05 + positief op een 2e munt
(of expliciet coin-specifiek). De willekeurige nullijn blijft alleen als sanity-vloer.
"""
from parent_eval import fmt_cls

# de 20-23-lat (§5)
SEL_MAX = 0.0011       # ≤ ~0,1% van de ticks (vuurt op de schaal van de promising groepjes)
MEAN_MIN = 0.7         # gem winst/trade ≥ +0,7% (zwakste bestaande regel); streef ~+2%
BAD_MAX = 0.45         # slecht ≤ ~45%
GOOD_MIN = 0.19        # goed ≥ ~19%
P_MAX = 0.05           # toeval-toets significant


def compact_line(res):
    c = res["cls"]
    return (f"{res['name']}: {res['groups_hit']}/{res['groups_total']} promising groepen | "
            f"goed {c['goed']} / middel {c['middel']} / slecht {c['slecht']} | "
            f"Σprofit {res['sigma']:+.0f}%")


def checks(res):
    n = max(res["n_trades"], 1)
    bad = res["cls"]["slecht"] / n
    good = res["cls"]["goed"] / n
    p = res["perm"]["p"]
    return {
        "selectief (≤0,1% ticks)": (res["selectivity"] <= SEL_MAX, f"{100*res['selectivity']:.3f}%"),
        "winst ≥+0,7%/trade": (res["mean"] >= MEAN_MIN, f"{res['mean']:+.3f}%"),
        "slecht ≤45%": (bad <= BAD_MAX, f"{100*bad:.0f}%"),
        "goed ≥19%": (good >= GOOD_MIN, f"{100*good:.0f}%"),
        "Σprofit > 0": (res["sigma"] > 0, f"{res['sigma']:+.0f}%"),
        "CPCV buiten-data > 0": (res["cpcv"]["mean_oos"] > 0, f"{res['cpcv']['mean_oos']:+.3f}%/trade"),
        "toeval-toets p<0,05": (p < P_MAX, f"p={p:.3f}"),
    }


def is_keeper(res):
    return all(ok for ok, _ in checks(res).values())


def print_report(res, n_trials=0, cross=None):
    print("\n" + "=" * 78)
    print("  " + compact_line(res))
    print(f"  ({res['n_trades']} trades, {100*res['selectivity']:.3f}% ticks, gem {res['mean']:+.3f}%/trade, "
          f"{res['n_sub']} subregels, {res['n_artefact']} artefacten geweerd)")
    inc = res["incr"]
    print(f"  incrementeel op 20-23: +{inc['added']} nieuwe trades "
          f"(goed {inc['added_good']}/slecht {inc['added_bad']}) | ΔΣ {inc['d_sigma']:+.0f}%")
    ch = checks(res)
    print(f"\n  oordeel tegen de 20-23-lat:")
    for label, (ok, val) in ch.items():
        print(f"    [{'✓' if ok else '✗'}] {label:24s} {val}")
    if res["perm"].get("p_sidak") is not None and n_trials:
        print(f"    (Šidák×{n_trials} pogingen: p={res['perm']['p_sidak']:.3f})")
    keep = all(ok for ok, _ in ch.values())
    if cross is not None:
        ok2, txt = cross
        print(f"    [{'✓' if ok2 else '·'}] 2e munt {txt}")
        keep = keep and ok2
    print(f"\n  >>> {'KEEPER — haalt de 20-23-lat' if keep else 'GEEN KEEPER — onder de 20-23-lat'}")
    return keep
