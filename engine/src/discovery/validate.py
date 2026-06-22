#!/usr/bin/env python3
"""
validate.py — de overfit-remmen (Epic RD, Stap 4).

Per kandidaat-rule (lijst subregels (col, side, lo, hi)):
  (a) CPCV met HERFIT  — splits de dagen in blokken; per apart-gehouden blok worden de drempels
      OPNIEUW afgesteld op de andere blokken (+ embargo) en de gerealiseerde winst gemeten op het
      apart-gehouden blok. Dat is de échte buiten-data-winst (López de Prado's verdeling van OOS).
  (b) Toeval-toets    — gerealiseerde winst van de rule vs N× willekeurige instap (zelfde aantal
      trades, getrokken uit de achtergrond-winstverdeling). p = fractie toeval >= echt.
  (c) Šidák-correctie — corrigeer p voor het aantal geprobeerde kandidaten (de funnel-scan): de
      ruisvloer stijgt met meer pogingen (PBO/Deflated-Sharpe-gedachte).
  (d) Incrementele bijdrage — base 20-23 vs base+kandidaat (één-positie-dedup), IN HET GEHEUGEN
      (geen DB-schrijven): voegt de rule netto goede trades toe bovenop 20-23?

Alles op gerealiseerde profit_loss via de trouwe sell-engine-dedup (parent_eval.faithful_trades),
met |pl|>200% guard. Geen best_upside. Schrijft niets naar de DB.
"""
import datetime as dt

import numpy as np

from db import brain
from parent_eval import faithful_trades, trade_stats, fmt_cls, cls_pl

BASE_RULES = (20, 21, 22, 23)


# ---------------------------------------------------------------- helpers

def _refit_subrules(dd, subrules, train_prom_df):
    """zelfde kolommen/richtingen, drempel opnieuw uit de train-promising-ticks (p10 ge / p90 le)."""
    out = []
    for (col, side, lo, hi) in subrules:
        v = train_prom_df[col].to_numpy(dtype=float)
        v = v[np.isfinite(v)]
        if len(v) < 8:
            return None
        if side == "ge":
            out.append((col, "ge", float(np.percentile(v, 10)), None))
        elif side == "le":
            out.append((col, "le", None, float(np.percentile(v, 90))))
        else:                                        # band → herfit beide grenzen
            out.append((col, "band", float(np.percentile(v, 10)), float(np.percentile(v, 90))))
    return out


def _trades_on_days(dd, mask, day_set):
    surv = [dd.vdt[i] for i in np.flatnonzero(mask) if dd.vdt[i].date() in day_set]
    tr, _art = faithful_trades(dd.eng, dd.A, surv)
    return tr


# ---------------------------------------------------------------- (a) CPCV met herfit

def cpcv(dd, subrules, n_blocks=6, embargo_days=1, refit=True, verbose=False):
    """Per apart-gehouden blok de gerealiseerde winst. refit=True: drempels herfit op de andere blokken
    (per-munt rule). refit=False: VASTE gedeelde drempels (coin-agnostische rule herfit je niet per munt)
    — meet dan puur de temporele stabiliteit van de vaste rule op elk blok."""
    blocks = dd.blocks(n_blocks)
    df = dd.df
    prom = df[(df["is_promising"]) & (df["group_id"] >= 0)]
    prom_day = prom["dt"].map(lambda t: t.date())
    per_block = []
    for b, days in enumerate(blocks):
        if not days:
            continue
        if refit:
            d_lo, d_hi = min(days), max(days)
            emb_lo = d_lo - dt.timedelta(days=embargo_days)
            emb_hi = d_hi + dt.timedelta(days=embargo_days)
            train_prom = prom[(prom_day < emb_lo) | (prom_day > emb_hi)]   # purge + embargo
            if len(train_prom) < 12:
                continue
            rf = _refit_subrules(dd, subrules, train_prom)
            if rf is None:
                continue
        else:
            rf = subrules
        tr = _trades_on_days(dd, dd.mask(rf), days)
        st = trade_stats(dd.eng, tr, with_gap=False)
        per_block.append(dict(block=b, n=st["n"], mean=st["mean"], sigma=st["sigma"], cls=st["cls"]))
    means = [pb["mean"] for pb in per_block if pb["n"] > 0]
    tot_tr = sum(pb["n"] for pb in per_block)
    agg = dict(per_block=per_block, n_blocks=len(per_block), n_trades=tot_tr,
               mean_oos=float(np.mean(means)) if means else 0.0,
               median_oos=float(np.median(means)) if means else 0.0,
               frac_pos=float(np.mean([m > 0 for m in means])) if means else 0.0)
    if verbose:
        print(f"  CPCV ({agg['n_blocks']} blokken, herfit+embargo): gem OOS {agg['mean_oos']:+.3f}%/trade | "
              f"mediaan {agg['median_oos']:+.3f}% | blokken winst>0: {100*agg['frac_pos']:.0f}% | {tot_tr} trades")
    return agg


# ---------------------------------------------------------------- (b) toeval-toets + (c) Šidák

def permutation(dd, subrules, n=2000, seed=1):
    """gerealiseerde winst van de rule vs N× willekeurige instap (zelfde #trades, uit bg-winst)."""
    surv = dd.survivors(subrules)
    tr, _art = faithful_trades(dd.eng, dd.A, surv)
    st = trade_stats(dd.eng, tr, with_gap=False)
    real = st["mean"]
    nt = st["n"]
    bg = dd.df.loc[~dd.df["is_promising"], "pl"].to_numpy(dtype=float)
    bg = bg[np.isfinite(bg)]
    if nt < 1 or len(bg) < nt:
        return dict(real_mean=real, n_trades=nt, p=1.0, null_mean=0.0, null_p95=0.0)
    rng = np.random.default_rng(seed)
    null = np.array([rng.choice(bg, size=nt, replace=False).mean() for _ in range(n)])
    p = float(np.mean(null >= real))
    return dict(real_mean=real, n_trades=nt, p=p, null_mean=float(null.mean()),
                null_p95=float(np.percentile(null, 95)), cls=st["cls"], sigma=st["sigma"])


def sidak(p, n_trials):
    """conservatieve multiple-testing-correctie voor het aantal geprobeerde kandidaten."""
    if not n_trials or n_trials < 1:
        return p
    return float(1.0 - (1.0 - p) ** n_trials)


# ---------------------------------------------------------------- (d) incrementele bijdrage

def incremental(dd, subrules, base_rules=BASE_RULES, verbose=False):
    """base 20-23 vs base+kandidaat (in-memory, één-positie-dedup). Geen DB-schrijven.

    De echte base = de werkelijk ingezette 20-23-fires uit `coin_fires` (NIET her-afgeleid via
    RuleEngine — die gate't op brain_volume_found, dat in de huidige DB 0 is voor sommige munten)."""
    A, eng = dd.A, dd.eng
    ph = ",".join(["%s"] * len(base_rules))
    with brain().cursor() as c:
        c.execute(f"SELECT DISTINCT datetime FROM coin_fires WHERE trading_symbol_id=%s "
                  f"AND rule IN ({ph}) ORDER BY datetime", (dd.symbol, *base_rules))
        base = [r["datetime"] for r in c.fetchall()]
    cand = dd.survivors(subrules)
    comb = sorted(set(base) | set(cand))
    bt, _ = faithful_trades(eng, A, base)
    ct, _ = faithful_trades(eng, A, comb)
    bst = trade_stats(eng, bt, with_gap=False)
    cst = trade_stats(eng, ct, with_gap=False)
    base_keys = {t["buy_dt"] for t in bt}
    added = [t for t in ct if t["buy_dt"] not in base_keys]
    from collections import Counter
    addcls = Counter(cls_pl(t["pl"]) for t in added)
    out = dict(base_n=bst["n"], base_sigma=bst["sigma"],
               comb_n=cst["n"], comb_sigma=cst["sigma"],
               d_n=cst["n"] - bst["n"], d_sigma=cst["sigma"] - bst["sigma"],
               added=len(added), added_cls=addcls,
               added_good=addcls["goed"], added_bad=addcls["slecht"])
    if verbose:
        print(f"  incrementeel op 20-23: base {bst['n']} trades (Σ{bst['sigma']:+.0f}%) → "
              f"+{out['added']} nieuwe (goed {addcls['goed']}/slecht {addcls['slecht']}) | "
              f"ΔΣ {out['d_sigma']:+.0f}% | Δverliezers {addcls['slecht']:+d}")
    return out


# ---------------------------------------------------------------- orkestratie

def validate(dd, subrules, n_blocks=6, n_perm=2000, n_trials=0, refit=True, verbose=True):
    surv = dd.survivors(subrules)
    tr, art = faithful_trades(dd.eng, dd.A, surv)
    st = trade_stats(dd.eng, tr, with_gap=False)
    sel = len(surv) / dd.tot if dd.tot else 0.0
    rec = dd.recall_mask(dd.mask(subrules))
    cp = cpcv(dd, subrules, n_blocks=n_blocks, refit=refit, verbose=verbose)
    pm = permutation(dd, subrules, n=n_perm)
    pm["p_sidak"] = sidak(pm["p"], n_trials)
    inc = incremental(dd, subrules, verbose=verbose)
    res = dict(name=dd.name, subrules=list(subrules), n_sub=len(subrules), selectivity=sel, recall=rec,
               groups_total=len(dd.groups), groups_hit=len(dd.groups_hit(surv)),
               n_trades=st["n"], sigma=st["sigma"], mean=st["mean"], cls=st["cls"],
               n_artefact=art, cpcv=cp, perm=pm, incr=inc)
    if verbose:
        print(f"  in-sample: {st['n']} trades ({100*sel:.3f}% v.d. ticks) | trefkans {100*rec:.0f}% | "
              f"Σ {st['sigma']:+.0f}% | gem {st['mean']:+.3f}%/trade | {fmt_cls(st['cls'], st['n'])}")
        print(f"  toeval-toets: gem {pm['real_mean']:+.3f}% vs toeval {pm['null_mean']:+.3f}% "
              f"(p95 {pm['null_p95']:+.3f}) → p={pm['p']:.3f}"
              + (f" | Šidák×{n_trials} p={pm['p_sidak']:.3f}" if n_trials else ""))
    return res


if __name__ == "__main__":
    import sys
    from discovery.data import build_matrix
    from discovery.segment import discover, top_structure
    from discovery.funnel import run_funnel
    sym, nm = (int(sys.argv[1]), sys.argv[2]) if len(sys.argv) > 2 else (2525, "DOGEAI")
    dd = build_matrix(sym, nm)
    seg = discover(dd, target="promising")
    seed = top_structure(seg) if seg else None
    print(f"\n[seed] {'top-segment structuur' if seed else 'geen segment → start bij alle ticks'}")
    subrules, _mask, fstats = run_funnel(dd, seed_structure=seed)
    print(f"\n[validate] rule = {len(subrules)} subregels")
    validate(dd, subrules, n_trials=fstats.get("n_sub", 0))
