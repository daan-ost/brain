#!/usr/bin/env python3
"""
data.py — de BRUG tussen de in-memory harness en de discovery-engine (Epic RD, Stap 1).

Bouwt per munt één platte feature-tabel (pandas DataFrame) die pysubgroup + de funnel kunnen lezen:
  - rijen     = de goede-groep-ticks (yes-marks, via rises()) + een achtergrond-steekproef uit vdt;
  - kolommen  = lean-featureset INDS1 × LB1(5,10,20) × METRICS1(12) + prijs-features (change/mindip/
                maxrise over 5,10,20). Dit is de set waarin de proef het signaal vond — de volledige
                30×lookback-1-20 Parquet-store is bewust uitgesteld (zie plan, schaal-upgrade);
  - doel      = gerealiseerde profit_loss per tick via de ECHTE sell-engine (NIET gededupliceerd; de
                één-positie-dedup gebeurt pas in de eindtelling, validate/report), met |pl|>200% guard;
  - meta      = is_promising, group_id (welke rise), day (voor CPCV-tijdblokken), dt.

Snel: arrays1() berekent de lean-featureset één keer vectorized over ÁLLE vdt-ticks; achtergrond-rijen
lezen hun features uit die cache via hun index (geen herberekening). Alleen groep-ticks (niet altijd
exact een vdt-tick) worden as-of herberekend met dezelfde formules.

Levert DiscoveryData: de tabel + project()/group-recall/CPCV-helpers die funnel.py en validate.py delen.
Alleen-lezen op brain (via AsOf/SellEngine/rises). Schrijft niets.
"""
import bisect
import datetime as dt
import os
import random

import numpy as np
import pandas as pd

from calc import calc_percentage
from db import brain
from parent_crossgroup import AsOf
from parent_fullperiod import rises
from parent_spoor1 import lean_metrics, arrays1, LB1, METRICS1, INDS1
from parent_eval import faithful_trades, trade_stats, PL_CAP
from sell_engine import SellEngine

PRICE_LBS = (5, 10, 20)
PRICE_KINDS = ("change", "mindip", "maxrise")
GROUP_PAD = dt.timedelta(minutes=2)   # tolerantie rond een groep-venster (zoals parent_eval.evaluate)
GOOD_PL = 3.0                         # goed-trade = gerealiseerde pl >= 3% (zoals cls_pl)

# Het muntenuniverse — dynamisch uit brain.coins (via coins.active_coins). Nieuwe coin wordt automatisch
# opgepikt zodra import_indicators.py is gedraaid (geen code-edit). Zie memory coins-universe-4.
from coins import active_coins as _active_coins
COINS = _active_coins()

# relvol = volumeud / min_volume (per munt). Het enige statistisch bevestigde signaal was volume-
# GROOTTE (volumeud|standard_deviation, p=0,001), maar die is schaal-afhankelijk → onbruikbaar als
# gedeelde band over munten. Door op de per-munt basislijn (min_volume, zoals feature_store.py:
# value / min_volume) te delen wordt diezelfde grootte coin-vergelijkbaar. De schaal-VRIJE metrics
# (skewness, volatility, range_percentage, ...) zijn al aanwezig als volumeud-kolom en identiek aan
# hun relvol-variant (delen door een constante verandert ze niet); alleen deze GROOTTE-metrics
# worden relatief opgenomen — exact afgeleid uit de al-berekende volumeud-arrays (geen herberekening).
RELVOL_METRICS = ("standard_deviation", "diff_lowest_value_period")


def min_volume(symbol):
    """Laagste min_volume uit coin_rule_settings — de per-munt volume-basislijn (zoals
    build_indicator_metrics.py: ORDER BY min_volume LIMIT 1). Dit getal is zelf een gemiddeld-
    volume-schatting over tijd; voor coin-agnostische werking telt vooral de VERHOUDING tussen de
    basislijnen van de munten, dus build_matrix laat 'm overschrijven (gevoeligheids-check)."""
    with brain().cursor() as c:
        c.execute("SELECT min_volume FROM coin_rule_settings WHERE trading_symbol_id=%s "
                  "AND min_volume IS NOT NULL ORDER BY min_volume LIMIT 1", (symbol,))
        r = c.fetchone()
    return float(r["min_volume"]) if r and r.get("min_volume") else 1.0


def indicator_cols():
    return [f"{ind}|L{lb}|{m}" for ind in INDS1 for lb in LB1 for m in METRICS1]


def relvol_cols():
    return [f"relvol|L{lb}|{m}" for lb in LB1 for m in RELVOL_METRICS]


def price_cols():
    return [f"price|L{lb}|{k}" for lb in PRICE_LBS for k in PRICE_KINDS]


def feature_cols():
    return indicator_cols() + relvol_cols() + price_cols()


def _pwin(vpx, i, lb):
    """prijs-venster oudste..nieuwste over [i-lb, i) (i = exclusieve bovengrens in vdt)."""
    return [p for p in vpx[max(0, i - lb):i] if p is not None]


def _price_feat(pr, kind):
    if len(pr) < 2 or not pr[0]:
        return np.nan
    old = pr[0]
    if kind == "change":
        return calc_percentage(old, pr[-1])
    if kind == "mindip":
        return 100.0 * (min(pr) - old) / old
    if kind == "maxrise":
        return 100.0 * (max(pr) - old) / old
    return np.nan


def feat_at(A, t, vol_base=1.0):
    """lean-featureset + prijs-features op moment t (as-of), zelfde formules als de all-vdt-cache."""
    row = {}
    for ind in INDS1:
        s = A.series[ind]
        k = bisect.bisect_right(s["dt"], t)
        for lb in LB1:
            w = s["v"][max(0, k - lb):k][::-1]
            mm = lean_metrics(w) if len(w) >= 2 else {}
            for m in METRICS1:
                v = mm.get(m, np.nan)
                row[f"{ind}|L{lb}|{m}"] = float(v) if v is not None and np.isfinite(v) else np.nan
    # relatieve volume-grootte = volumeud-grootte / per-munt basislijn (exact afgeleid)
    for lb in LB1:
        for m in RELVOL_METRICS:
            base = row.get(f"volumeud|L{lb}|{m}", np.nan)
            row[f"relvol|L{lb}|{m}"] = base / vol_base if np.isfinite(base) else np.nan
    i = bisect.bisect_right(A.vdt, t)
    for lb in PRICE_LBS:
        pr = _pwin(A.vpx, i, lb)
        for k in PRICE_KINDS:
            row[f"price|L{lb}|{k}"] = _price_feat(pr, k)
    return row


def _all_tick_arrays(A, vol_base=1.0):
    """{colname: np.array(len(vdt))} voor ALLE vdt-ticks — indicator-lean (via arrays1) + relvol + prijs."""
    cache = arrays1(A)                     # cache[(ind,lb)][metric] = np.array(len(vdt))
    cols = {}
    for ind in INDS1:
        for lb in LB1:
            cm = cache[(ind, lb)]
            for m in METRICS1:
                cols[f"{ind}|L{lb}|{m}"] = cm[m]
    # relatieve volume-grootte = volumeud-grootte / per-munt basislijn (exact, geen herberekening)
    for lb in LB1:
        for m in RELVOL_METRICS:
            cols[f"relvol|L{lb}|{m}"] = cols[f"volumeud|L{lb}|{m}"] / vol_base
    n = len(A.vdt)
    for lb in PRICE_LBS:
        arrs = {k: np.full(n, np.nan) for k in PRICE_KINDS}
        for i in range(n):
            pr = _pwin(A.vpx, i + 1, lb)   # as-of t=vdt[i] → window t/m i → exclusieve grens i+1
            for k in PRICE_KINDS:
                arrs[k][i] = _price_feat(pr, k)
        for k in PRICE_KINDS:
            cols[f"price|L{lb}|{k}"] = arrs[k]
    return {k: v.astype(np.float32, copy=False) for k, v in cols.items()}   # float32 = halve geheugen


_CACHE_DIR = os.path.join(os.path.dirname(__file__), ".cache")


def _all_tick_arrays_cached(A, symbol, vol_base=1.0):
    """Schijf-cache (dev) voor de dure all-vdt-arrays. Sleutel = symbol + vol_base + signatuur van de
    vdt-reeks (lengte + eerste/laatste tick) zodat verse data OF een andere basislijn de cache
    automatisch ongeldig maakt. Dit is GEEN Parquet feature-store (die is bewust uitgesteld) — alleen
    een herhaal-versneller binnen een sessie."""
    sig = f"{len(A.vdt)}_{A.vdt[0]:%Y%m%d%H%M}_{A.vdt[-1]:%Y%m%d%H%M}_v{vol_base:g}"
    path = os.path.join(_CACHE_DIR, f"cols_{symbol}_{sig}.npz")
    cols = feature_cols()
    if os.path.exists(path):
        z = np.load(path, allow_pickle=False)
        names = list(z["names"])
        data = z["data"]                       # ÉÉN keer inladen! z["data"][i] in een loop her-
        if names == cols and data.shape == (len(cols), len(A.vdt)):   # decomprimeert de npz per kolom → 12GB+
            return {c: data[i].astype(np.float32, copy=False) for i, c in enumerate(names)}
    arr = _all_tick_arrays(A, vol_base)
    os.makedirs(_CACHE_DIR, exist_ok=True)
    np.savez_compressed(path, names=np.array(cols), data=np.stack([arr[c] for c in cols]))
    return arr


class DiscoveryData:
    """Feature-tabel + gedeelde projectie/recall/CPCV-helpers voor één munt."""

    def __init__(self, symbol, name, df, A, eng, groups, col_arrays):
        self.symbol = symbol
        self.name = name
        self.df = df
        self.A = A
        self.eng = eng
        self.groups = groups                       # [[t0,t1,t2], ...]  (de rises, eerste-3)
        self.col_arrays = col_arrays               # {colname: np.array(len(vdt))}
        self.vdt = A.vdt
        self.tot = len(A.vdt)
        self.features = feature_cols()
        self._gwin = [(g[0] - GROUP_PAD, g[-1] + GROUP_PAD) for g in groups]
        # per groep het vdt-index-bereik [lo,hi) van zijn venster — voor snelle mask-recall
        self.group_span = [(bisect.bisect_left(self.vdt, w0), bisect.bisect_right(self.vdt, w1))
                           for (w0, w1) in self._gwin]

    # ---- projectie over ALLE vdt-ticks (per-tick conjunctie, zoals 20-23) ----
    def mask(self, subrules):
        """boolean mask over vdt: ticks waar ALLE subregels gelden. subrule = (col, side, lo, hi)."""
        m = np.ones(self.tot, dtype=bool)
        for (col, side, lo, hi) in subrules:
            v = self.col_arrays[col]
            ok = np.isfinite(v)
            if side == "ge":
                ok &= v >= lo
            elif side == "le":
                ok &= v <= hi
            else:                                  # band
                ok &= (v >= lo) & (v <= hi)
            m &= ok
        return m

    def survivors(self, subrules):
        return [self.vdt[i] for i in np.flatnonzero(self.mask(subrules))]

    # ---- snelle trefkans + selectiviteit rechtstreeks uit een mask (geen survivor-lijst) ----
    def recall_mask(self, mask, idx=None):
        """fractie groepen (idx = subset, anders alle) met >=1 vdt-tick van hun venster in de mask."""
        rng = range(len(self.groups)) if idx is None else idx
        n = 0
        hit = 0
        for gi in rng:
            n += 1
            lo, hi = self.group_span[gi]
            if hi > lo and mask[lo:hi].any():
                hit += 1
        return hit / n if n else 0.0

    def selectivity(self, mask):
        return float(mask.mean())

    # ---- groep-dekking (trefkans) ----
    def groups_hit(self, survivors, idx=None):
        """welke groepen heeft minstens één survivor in hun venster? (idx = subset groep-indices)."""
        ss = sorted(survivors)
        hit = set()
        rng = range(len(self.groups)) if idx is None else idx
        for gi in rng:
            w0, w1 = self._gwin[gi]
            j = bisect.bisect_left(ss, w0)
            if j < len(ss) and ss[j] <= w1:
                hit.add(gi)
        return hit

    def recall(self, survivors, idx=None):
        n = len(self.groups) if idx is None else len(idx)
        return len(self.groups_hit(survivors, idx)) / n if n else 0.0

    # ---- CPCV-tijdblokken (over dagen, met embargo) ----
    def day_index(self):
        """gesorteerde unieke dagen van de groep-starts (de tijd-as voor de splitsingen)."""
        return sorted({g[0].date() for g in self.groups})

    def blocks(self, n_blocks):
        """splits de groep-dagen in n aaneengesloten blokken; geef per blok de set dagen."""
        days = self.day_index()
        if len(days) < n_blocks:
            n_blocks = max(1, len(days))
        edges = np.linspace(0, len(days), n_blocks + 1).astype(int)
        return [set(days[edges[b]:edges[b + 1]]) for b in range(n_blocks)]

    def group_idx_in_days(self, day_set):
        return [gi for gi, g in enumerate(self.groups) if g[0].date() in day_set]


def build_matrix(symbol, name, bg_n=6000, seed=1, verbose=True, vol_base=None):
    """Bouw de feature-tabel + DiscoveryData voor één munt. vol_base=None → laagste min_volume uit
    coin_rule_settings; expliciet meegeven om de relvol-basislijn te variëren (gevoeligheids-check)."""
    random.seed(seed)
    if vol_base is None:
        vol_base = min_volume(symbol)
    A = AsOf(symbol)
    eng = SellEngine(symbol)
    groups, _bad = rises(symbol)
    groups.sort(key=lambda g: g[0])
    col_arrays = _all_tick_arrays_cached(A, symbol, vol_base)
    tot = len(A.vdt)
    gwin = [(g[0] - GROUP_PAD, g[-1] + GROUP_PAD) for g in groups]

    # dev-cache van de tabel (de ~6000 sell-simulaties): sleutel = symbol+bg_n+seed+vol_base+data-sig
    sig = f"{tot}_{A.vdt[0]:%Y%m%d%H%M}_{A.vdt[-1]:%Y%m%d%H%M}_{len(groups)}g_v{vol_base:g}"
    dfpath = os.path.join(_CACHE_DIR, f"df_{symbol}_bg{bg_n}_s{seed}_{sig}.pkl")
    if os.path.exists(dfpath):
        df = pd.read_pickle(dfpath)
        dd = DiscoveryData(symbol, name, df, A, eng, groups, col_arrays)
        if verbose:
            npos = int(df["is_promising"].sum())
            print(f"[{name}] {len(groups)} groepen | tabel {len(df)} rijen ({npos} promising) [cache] "
                  f"| {len(dd.features)} features | {tot} vdt-ticks")
        return dd

    def group_of(t):
        for gi, (w0, w1) in enumerate(gwin):
            if w0 <= t <= w1:
                return gi
        return -1

    rows = []

    def add_row(t, idx_in_vdt, is_group, gid):
        # features
        if idx_in_vdt is not None:
            feats = {c: col_arrays[c][idx_in_vdt] for c in feature_cols()}
            buy = A.vpx[idx_in_vdt]
        else:
            feats = feat_at(A, t, vol_base)
            i = bisect.bisect_right(A.vdt, t)
            buy = A.vpx[i - 1] if i > 0 else None
        # doel: gerealiseerde pl via de echte sell-engine (niet gededupliceerd)
        pl = np.nan
        if buy is not None and buy > 0:
            r = eng.sell(t, buy, 20)
            if r is not None and abs(r["profit_loss"]) <= PL_CAP:
                pl = float(r["profit_loss"])
        rec = dict(feats)
        rec.update(dt=t, is_promising=bool(is_group or gid >= 0), group_id=(gid if gid >= 0 else -1),
                   day=t.date(), pl=pl, good=(np.nan if np.isnan(pl) else float(pl >= GOOD_PL)))
        rows.append(rec)

    # promising rijen = alle ticks in alle groepen
    seen = set()
    for gi, g in enumerate(groups):
        for t in g:
            if t in seen:
                continue
            seen.add(t)
            add_row(t, None, True, gi)

    # achtergrond-steekproef uit vdt (sluit ticks in een groep-venster niet uit; markeer ze)
    bg_idx = random.sample(range(tot), min(bg_n, tot))
    for i in bg_idx:
        t = A.vdt[i]
        if t in seen:
            continue
        seen.add(t)
        add_row(t, i, False, group_of(t))

    df = pd.DataFrame(rows)
    os.makedirs(_CACHE_DIR, exist_ok=True)
    df.to_pickle(dfpath)
    dd = DiscoveryData(symbol, name, df, A, eng, groups, col_arrays)
    if verbose:
        npos = int(df["is_promising"].sum())
        ntr = int(df["pl"].notna().sum())
        print(f"[{name}] {len(groups)} groepen | tabel {len(df)} rijen ({npos} promising, {len(df)-npos} bg) "
              f"| {ntr} met sell-resultaat | {len(dd.features)} features | {tot} vdt-ticks")
    return dd


if __name__ == "__main__":
    import sys
    syms = COINS
    if len(sys.argv) > 1:
        syms = [(int(sys.argv[1]), sys.argv[2] if len(sys.argv) > 2 else sys.argv[1])]
    for sym, nm in syms:
        dd = build_matrix(sym, nm)
        # sanity: doelverdeling + dagen-dekking
        g = int((dd.df["good"] == 1).sum()); b = int((dd.df["good"] == 0).sum())
        print(f"    doel: goed(pl>=3)={g}  niet-goed={b}  | CPCV-dagen={len(dd.day_index())}  blokken(5)={[len(x) for x in dd.blocks(5)]}")
