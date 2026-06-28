#!/usr/bin/env python3
"""
subrule_power.py — READ-ONLY. Meet de GEISOLEERDE KRACHT van een kandidaat-subregel (Daans methode):

  "Houd de basis aan; bepaal hoeveel SLECHTE trades EEN losse subregel tegenhoudt, met behoud van
   ALLE goede trades. Dat geeft de kracht van die subregel, los van de andere subregels."

Voor een rule R nemen we zijn EXECUTED trades (met gerealiseerd sell-resultaat profit_loss) als basis-set.
Per kandidaat (indicator x window-metric x lookback x kant) plaatsen we de drempel op de BAD-EDGE (net
voorbij de meest extreme GOEDE trade -> good_keep = 100% in de leerperiode) en tellen hoeveel SLECHTE
trades daarbuiten vallen = de kracht.

EERLIJKHEID (na critical-eye review):
  - WALK-FORWARD: niet 1 vaste 70/30-grens maar 5 splits (0.60..0.80); de drempel komt uit de vroege
    leerperiode, gemeten op de late testperiode. We rapporteren de MEDIANE test-drop + hoe vaak
    good_keep=100% bleef (stabiliteit). Eén-split-cijfers zijn te wiebelig (min/max-drempel).
  - MIN. GOEDE IN TEST: een split telt alleen als de testperiode >= MIN_GOOD_TEST goede trades heeft,
    anders is "0 goede verloren" betekenisloos.
  - TOEVAL-TOETS op de TESTPERIODE (niet in-sample): schud de goed/slecht-labels, leid de drempel
    opnieuw af op de geschudde leerperiode, meet de drop op de testperiode. p = hoe vaak toeval >= echt.
  - DEDUP relvol/volumeud: relvol = volumeud / constante, dus voor schaal-invariante metrics IDENTIEK.
    We tellen die maar EEN keer (relvol alleen voor LEVEL-metrics, waar het de schaal-vrije variant is).

We rekenen op de RAUWE engine-reeks via RuleEngine._vals (leak-vrij, as-of) — exact het pad dat de
engine gebruikt, dus een gevonden drempel is direct als subregel inzetbaar (geen cache/scale-mismatch).

Gebruik:  ../.venv/bin/python -u subrule_power.py 30 31      (rules; default 30 31 20 21 22 23)
"""
import sys
from collections import defaultdict
from statistics import median

import numpy as np

import regime
from db import brain
from rule_engine import RuleEngine
from calc import window_metrics, WINDOW_METRIC_KEYS
from extra_calcs import extra_metrics, EXTRA_CALCS
from cross_calcs import cross_metrics, CROSS_CALCS
from opt_lib import GOOD_PL, BAD_PL, scale_unsafe

COINS = [(2525, "DOGEAI"), (244, "NOS")]
INDICATORS = ["volumeud", "relvol", "phobos", "vzo", "mfi", "obv-x-value", "price", "volprice"]
LOOKBACKS = [5, 10, 20]
EPS = 1e-9
SPLITS = [0.60, 0.65, 0.70, 0.75, 0.80]
MIN_GOOD_TEST = 4         # minder goede in test -> good_keep onbetrouwbaar -> split telt niet
N_PERM = 400
TOPN = 16


def load_trades(conn, sym, rule, include_inactive=False):
    # Epic H: default zónder de inactieve-periode-trades (regime-gate); include_inactive=True = alles.
    reg = "" if include_inactive else " AND " + regime.active_sql_clause()
    with conn.cursor() as c:
        c.execute("SELECT datetime, profit_loss FROM coin_fires WHERE trading_symbol_id=%s AND rule=%s "
                  "AND is_executed=1 AND profit_loss IS NOT NULL" + reg + " ORDER BY datetime", (sym, rule))
        return [(r["datetime"], float(r["profit_loss"])) for r in c.fetchall()]


def cls(pl):
    return "goed" if pl >= GOOD_PL else ("slecht" if pl < BAD_PL else "middel")


def feature_at(eng, ind, lb, T):
    """31 window-metrics + nieuwe extra (univariate) of cross-kanaal (volprice = volume x prijs)."""
    if ind == "volprice":
        vol, prices = eng._vals("volumeud", lb, T)
        if len(prices) < 4 or len(vol) < 4:
            return None
        return cross_metrics([float(x) for x in vol], [float(x) for x in prices])
    if ind == "price":
        _, vals = eng._vals("volumeud", lb, T)
    else:
        vals, _ = eng._vals(ind, lb, T)
    if len(vals) < 2:
        return None
    vals = [float(x) for x in vals]
    m = window_metrics(vals)
    m.update(extra_metrics(vals))
    return m


def candidate_keys():
    """Alle (ind, metric, lb): 31 window + 13 extra (univariate) + cross-kanaal (op volprice).
    relvol weggelaten voor schaal-invariante metrics (== volumeud)."""
    out = []
    for ind in INDICATORS:
        if ind == "volprice":
            for metric in CROSS_CALCS:
                for lb in LOOKBACKS:
                    out.append((ind, metric, lb))
            continue
        for metric in list(WINDOW_METRIC_KEYS) + list(EXTRA_CALCS):
            if ind == "relvol" and not scale_unsafe("volumeud", metric):
                continue                      # identiek aan volumeud -> niet dubbel tellen
            for lb in LOOKBACKS:
                out.append((ind, metric, lb))
    return out


def build_features(conn, sym, rule, keys):
    trades = load_trades(conn, sym, rule)
    if len(trades) < 16:
        return None
    klass = [cls(pl) for _, pl in trades]
    n = len(trades)
    feat = {k: [None] * n for k in keys}
    needed = defaultdict(set)                  # (ind,lb) -> metrics
    for ind, metric, lb in keys:
        needed[(ind, lb)].add(metric)
    eng = RuleEngine(sym)
    for ti, (T, _pl) in enumerate(trades):
        for (ind, lb), metrics in needed.items():
            m = feature_at(eng, ind, lb, T)
            if m is None:
                continue
            for metric in metrics:
                feat[(ind, metric, lb)][ti] = m.get(metric)
    eng.close()
    return klass, feat, klass.count("goed"), klass.count("slecht"), n


def edge(vals, klass, lo, hi, side):
    """Drempel uit de GOEDE trades in [lo,hi) op de bad-edge."""
    g = [vals[i] for i in range(lo, hi) if klass[i] == "goed" and vals[i] is not None]
    if not g:
        return None
    return min(g) if side == "b_min" else max(g)


def apply_drop(vals, klass, lo, hi, side, thr):
    bd = gl = ng = 0
    for i in range(lo, hi):
        v = vals[i]
        if klass[i] == "goed":
            ng += 1
        if v is None:
            continue
        out = (v < thr - EPS) if side == "b_min" else (v > thr + EPS)
        if out:
            if klass[i] == "slecht":
                bd += 1
            elif klass[i] == "goed":
                gl += 1
    return bd, gl, ng


def walk_forward(vals, klass, n, side):
    """Over alle splits: drempel uit de vroege leerperiode, gemeten op de late testperiode.
    Return (med_drop_clean, n_clean, n_valid) — med_drop over splits met good_keep=100%."""
    drops_clean, n_clean, n_valid = [], 0, 0
    for frac in SPLITS:
        s = int(n * frac)
        # geldigheid: leerperiode genoeg goed+slecht, testperiode genoeg goede
        if sum(1 for i in range(s) if klass[i] == "goed") < 2:
            continue
        if sum(1 for i in range(s) if klass[i] == "slecht") < 2:
            continue
        if sum(1 for i in range(s, n) if klass[i] == "goed") < MIN_GOOD_TEST:
            continue
        if sum(1 for i in range(s, n) if klass[i] == "slecht") < 2:
            continue
        thr = edge(vals, klass, 0, s, side)
        if thr is None:
            continue
        n_valid += 1
        bd, gl, _ = apply_drop(vals, klass, s, n, side, thr)
        if gl == 0:
            n_clean += 1
            drops_clean.append(bd)
    med = median(drops_clean) if drops_clean else 0
    return med, n_clean, n_valid


def best_side(vals, klass, n):
    res = {}
    for side in ("b_min", "b_max"):
        res[side] = walk_forward(vals, klass, n, side)
    # kies de kant met de meeste schone splits, dan hoogste mediane drop
    return max(res.items(), key=lambda kv: (kv[1][1], kv[1][0]))


def perm_p_test(vals, klass, n, side, rng):
    """Toeval-toets OP DE TESTPERIODE (split 0.70). Schud labels, leid drempel af op geschudde
    leerperiode, meet test-drop (eis good_keep=100% in test). p = fractie toeval >= echt."""
    s = int(n * 0.70)
    if sum(1 for i in range(s, n) if klass[i] == "goed") < MIN_GOOD_TEST:
        return None
    thr = edge(vals, klass, 0, s, side)
    if thr is None:
        return None
    obs_bd, obs_gl, _ = apply_drop(vals, klass, s, n, side, thr)
    if obs_gl != 0:
        return None
    lab = np.array(klass, dtype=object)
    idx = np.arange(n)
    ge = 0
    for _ in range(N_PERM):
        rng.shuffle(idx)
        sh = lab[idx]
        gtr = [vals[i] for i in range(s) if sh[i] == "goed" and vals[i] is not None]
        if not gtr:
            ge += 1; continue
        t = min(gtr) if side == "b_min" else max(gtr)
        bd = gl = 0
        for i in range(s, n):
            v = vals[i]
            if v is None:
                continue
            out = (v < t - EPS) if side == "b_min" else (v > t + EPS)
            if out:
                if sh[i] == "slecht":
                    bd += 1
                elif sh[i] == "goed":
                    gl += 1
        if gl == 0 and bd >= obs_bd:
            ge += 1
    return (ge + 1) / (N_PERM + 1), obs_bd


def analyse_rule(conn, rule):
    print(f"\n{'#'*96}\n# RULE {rule} — geisoleerde subregel-kracht, WALK-FORWARD ({len(SPLITS)} splits, drempel uit vroege deel)\n{'#'*96}")
    keys = candidate_keys()
    data = {}
    for sym, name in COINS:
        d = build_features(conn, sym, rule, keys)
        if d is None:
            print(f"  {name}: te weinig trades — overslaan"); continue
        klass, feat, ng, nb, n = d
        data[name] = d
        gtest = sum(1 for i in range(int(n*0.7), n) if klass[i] == "goed")
        print(f"  basis {name}: {n} trades = {ng} goed / {nb} slecht / {n-ng-nb} middel "
              f"(goede in test-30%: ~{gtest}{'  <- te weinig voor betrouwbare holdout' if gtest < MIN_GOOD_TEST else ''})")
    if not data:
        return

    rng = np.random.default_rng(42)
    n_hyp = len(keys) * 2                          # echte # hypothesen (x2 kanten) voor de correctie
    ranked = []
    for key in keys:
        ind, metric, lb = key
        unsafe = scale_unsafe(ind, metric)
        per = {}
        for name in data:
            klass, feat, ng, nb, n = data[name]
            vals = feat[key]
            side, (med, nclean, nvalid) = best_side(vals, klass, n)
            if nvalid > 0 and nclean > 0:
                per[name] = {"side": side, "med": med, "nclean": nclean, "nvalid": nvalid}
        if not per:
            continue
        both_stable = (len(data) == 2 and all(
            c in per and per[c]["nvalid"] >= 3 and per[c]["nclean"] >= per[c]["nvalid"] - 1
            and per[c]["med"] >= 2 for c in ("DOGEAI", "NOS")) and not unsafe)
        total_med = sum(per[c]["med"] for c in per)
        ranked.append((both_stable, total_med, key, per, unsafe))
    ranked.sort(key=lambda x: (x[0], x[1]), reverse=True)

    print(f"\n  {'indicator':12s} {'metric':28s} {'lb':>2s} {'kant':5s} | per munt: mediaan slecht-weg (schone splits/geldig) | p-toeval (Bonf.)")
    shown = 0
    for both, tmed, key, per, unsafe in ranked:
        if shown >= TOPN:
            break
        ind, metric, lb = key
        detail = "  ".join(f"{c[:3]}:{per[c]['med']}({per[c]['nclean']}/{per[c]['nvalid']})"
                           for c in ("DOGEAI", "NOS") if c in per)
        pcoin = max(per, key=lambda c: data[c][4])
        klass, feat, ng, nb, n = data[pcoin]
        pr = perm_p_test(feat[key], klass, n, per[pcoin]["side"], rng)
        if pr:
            p, _ = pr
            pbonf = min(1.0, p * n_hyp)
            ptxt = f"p={p:.3f} Bonf={pbonf:.2f}"
            sig = "  <== overleeft" if pbonf < 0.05 and both else ""
        else:
            ptxt, sig = "p=n.v.t.", ""
        tag = "STABIEL" if both else ("scale!" if unsafe else "")
        print(f"  {tag:8s}{ind:12s} {metric:28s} {lb:>2d} {per[pcoin]['side']:5s} | {detail:44s} | {ptxt}{sig}")
        shown += 1
    print(f"  (hypothesen getest: {n_hyp}; Bonferroni = p × {n_hyp}. STABIEL = good_keep 100% op >=#geldig-1 splits, "
          f"mediaan >=2 slecht-weg, op BEIDE munten.)")


def main():
    rules = [int(a) for a in sys.argv[1:]] or [30, 31, 20, 21, 22, 23]
    conn = brain()
    for r in rules:
        analyse_rule(conn, r)
    conn.close()


if __name__ == "__main__":
    main()
