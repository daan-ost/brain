"""
READ-ONLY — korte-termijn AAN/UIT-gate onderzoek.

Vraag (Daan): kun je per minuut een gate aan/uit zetten op basis van de laatste X minuten
van ALLE indicatoren (incl. prijs), zodat je goede trades behoudt en een deel van de slechte
voorkomt? Een 'gemene deler' zoeken.

Aanpak:
- Voor elke executed trade T: kijk in het tijds-venster [T-W, T] (W = 15/30/45/60 min) naar
  elk kanaal (vzo, mfi, phobos, obv-x-value, volume, price) en bereken SCHAAL-VRIJE vorm-features
  (helling, versnelling, choppiness, positie-in-range, spike-grootte, bevestiging tussen indicatoren).
  Schaal-vrij want absoluut volume/prijs draagt niet cross-coin over EN lekt 'welke maand het is'.
- Label: goed = best_upside>=3, slecht = best_upside<0.5 (skill-definitie).
- Rank features op scheidend vermogen (Cohen's d) goed vs slecht.
- GATE-test: drempel aan de slechte rand → hoeveel goede behoud je (good_keep), hoeveel slechte
  voorkom je (bad_drop)? Daan wil good_keep hoog, bad_drop > 0.
- LEK-CHECK: discrimineert de gate ook BINNEN een maand (within-month), of codeert hij alleen
  vroege-vs-late maand (= het al bekende maand-stop signaal, vermomd)?
- CROSS-COIN: drempel op DOGEAI, ongewijzigd toepassen op NOS.

Niets wordt geschreven. Geen rule/engine-wijziging.
"""
import bisect
import sys
from datetime import timedelta

import numpy as np
import pandas as pd
import pymysql

import new_feat_lib as nfl

CHANNELS = ("vzo", "mfi", "phobos", "obv-x-value", "volume", "price")
INDS_CONFIRM = ("vzo", "mfi", "phobos", "obv-x-value")
WINDOWS = (15, 30, 45, 60)
SYM = {"DOGEAI": 2525, "NOS": 244}


def load_series(symbol_id):
    c = pymysql.connect(host="127.0.0.1", port=8889, user="root", password="root",
                        database="brain", cursorclass=pymysql.cursors.Cursor)
    cur = c.cursor()
    cur.execute("""SELECT indicator, datetime, value, price FROM indicators
                   WHERE trading_symbol_id=%s AND value IS NOT NULL ORDER BY datetime""", (symbol_id,))
    series = {}
    for ind, dt, val, price in cur.fetchall():
        s = series.setdefault(ind, {"dt": [], "v": [], "p": []})
        s["dt"].append(dt); s["v"].append(float(val))
        s["p"].append(float(price) if price is not None else np.nan)
    c.close()
    for s in series.values():
        s["v"] = np.array(s["v"]); s["p"] = np.array(s["p"])
    return series


def load_trades(symbol_id):
    c = pymysql.connect(host="127.0.0.1", port=8889, user="root", password="root",
                        database="brain", cursorclass=pymysql.cursors.DictCursor)
    cur = c.cursor()
    cur.execute("""SELECT datetime, best_upside, profit_loss FROM coin_fires
                   WHERE trading_symbol_id=%s AND is_executed=1 ORDER BY datetime""", (symbol_id,))
    rows = cur.fetchall(); c.close()
    out = []
    for r in rows:
        bu = float(r["best_upside"]) if r["best_upside"] is not None else None
        if bu is None:
            continue
        lab = "goed" if bu >= 3 else ("slecht" if bu < 0.5 else "middel")
        out.append({"T": r["datetime"], "label": lab, "best_upside": bu,
                    "pl": float(r["profit_loss"] or 0)})
    return out


def window_vals(series, channel, T, minutes):
    """Waarden in (T-minutes, T], NIEUWSTE EERST (index 0 = meest recent)."""
    ind = "volumeud" if channel in ("volume", "price") else channel
    s = series.get(ind)
    if not s:
        return []
    i = bisect.bisect_right(s["dt"], T)
    cut = T - timedelta(minutes=minutes)
    j = i - 1
    arr = s["p"] if channel == "price" else s["v"]
    out = []
    while j >= 0 and s["dt"][j] > cut:
        x = arr[j]
        if not (isinstance(x, float) and np.isnan(x)):
            out.append(float(x))
        j -= 1
    return out  # newest-first


def feats_for_window(series, T, W):
    """Schaal-vrije vorm-features over alle kanalen voor het tijds-venster W."""
    f = {}
    chans = {ch: window_vals(series, ch, T, W) for ch in CHANNELS}
    for ch, x in chans.items():
        pre = f"{ch}_W{W}"
        if len(x) >= 2:
            f[f"{pre}_zslope"] = nfl._zslope(x)
            f[f"{pre}_upfrac"] = nfl._up_frac(x)
            f[f"{pre}_posrange"] = nfl._pos_in_range(x)
            f[f"{pre}_maxstep"] = nfl._max_step_pct(x)
            sd = np.std(x)
            f[f"{pre}_vsmean"] = float((x[0] - np.mean(x)) / sd) if sd > 0 else 0.0
            f[f"{pre}_n"] = float(len(x))   # tick-dichtheid (activiteit) — informatief
        if len(x) >= 4:
            f[f"{pre}_accel"] = nfl._accel(x)
            f[f"{pre}_chop"] = (nfl._reversals(x) or 0) / len(x)
    # volume spike-grootte (scale-free: laatste / mediaan venster)
    for W2, x in [(W, chans["volume"])]:
        if len(x) >= 2:
            f[f"volume_W{W2}_relspike"] = nfl._relvol0(x)
    # cross-indicator bevestiging: hoeveel van de 4 indicatoren stijgen in het venster
    conf = 0
    for ind in INDS_CONFIRM:
        xi = chans[ind]
        sl = nfl._zslope(xi) if len(xi) >= 2 else None
        if sl is not None and sl > 0:
            conf += 1
    f[f"confirm_W{W}"] = float(conf)
    # prijs vs volume divergentie (prijs stijgt zonder volume = zwak)
    pz = nfl._zslope(chans["price"]) if len(chans["price"]) >= 2 else None
    vz = nfl._zslope(chans["volume"]) if len(chans["volume"]) >= 2 else None
    if pz is not None and vz is not None:
        f[f"div_pricevol_W{W}"] = pz - vz
    return f


def build_matrix(symbol_id):
    series = load_series(symbol_id)
    trades = load_trades(symbol_id)
    rows = []
    for tr in trades:
        rec = {"label": tr["label"], "best_upside": tr["best_upside"], "pl": tr["pl"],
               "ym": tr["T"].strftime("%Y-%m")}
        for W in WINDOWS:
            rec.update(feats_for_window(series, tr["T"], W))
        rows.append(rec)
    return pd.DataFrame(rows)


def cohens_d(a, b):
    a = a[~np.isnan(a)]; b = b[~np.isnan(b)]
    if len(a) < 5 or len(b) < 5:
        return np.nan
    na, nb = len(a), len(b)
    sp = np.sqrt(((na - 1) * a.var(ddof=1) + (nb - 1) * b.var(ddof=1)) / (na + nb - 2))
    return (a.mean() - b.mean()) / sp if sp > 0 else np.nan


def gate_test(df, feat, thr, side):
    """side='hoog' -> gate AAN als feat>=thr (goede zitten hoog). side='laag' -> AAN als feat<=thr."""
    v = df[feat].values
    aan = (v >= thr) if side == "hoog" else (v <= thr)
    aan = aan & ~np.isnan(v)
    g = df["label"].values == "goed"
    b = df["label"].values == "slecht"
    ng, nb = g.sum(), b.sum()
    good_keep = (aan & g).sum() / ng if ng else 0
    bad_drop = (b & ~aan).sum() / nb if nb else 0
    return good_keep, bad_drop, int((aan & g).sum()), int((b & ~aan).sum()), ng, nb


def best_threshold(df, feat, min_keep=0.90):
    """Vind de drempel die de meeste slechte voorkomt bij good_keep >= min_keep, beide kanten."""
    v = df[feat].values
    fin = v[~np.isnan(v)]
    if len(fin) < 20:
        return None
    best = None
    for q in np.linspace(1, 99, 99):
        thr = np.percentile(fin, q)
        for side in ("hoog", "laag"):
            gk, bd, *_ = gate_test(df, feat, thr, side)
            if gk >= min_keep and bd > 0:
                cand = (bd, gk, thr, side)
                if best is None or cand[0] > best[0]:
                    best = cand
    return best  # (bad_drop, good_keep, thr, side) of None


if __name__ == "__main__":
    pd.set_option("display.width", 200)
    dfs = {sym: build_matrix(sid) for sym, sid in SYM.items()}
    for sym, df in dfs.items():
        n = df["label"].value_counts().to_dict()
        print(f"{sym}: {len(df)} trades  {n}")

    # rank features op Cohen's d in DOGEAI (de coin met de meeste/duidelijkste trades)
    base = dfs["DOGEAI"]
    featcols = [c for c in base.columns if c not in ("label", "best_upside", "pl", "ym")]
    g = base[base.label == "goed"]; b = base[base.label == "slecht"]
    ranked = []
    for fc in featcols:
        d = cohens_d(g[fc].values.astype(float), b[fc].values.astype(float))
        if not np.isnan(d):
            ranked.append((abs(d), d, fc))
    ranked.sort(reverse=True)
    print("\n==== top-20 scheidende features (DOGEAI, |Cohen's d| goed vs slecht) ====")
    print(f"{'feature':28} {'d':>6}  richting")
    for ad, d, fc in ranked[:20]:
        print(f"{fc:28} {d:6.2f}  {'goed=hoog' if d>0 else 'goed=laag'}")
