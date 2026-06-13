#!/usr/bin/env python3
"""
Milestone 1 PoC — can an ML filter drop bad trades while keeping the good ones,
better than the legacy rule (which keeps every trade it fires)?

Reads the READ-ONLY slice exported by export_slice.sh (DOGEAI 5m, rules 20/21).
Target: result==3 (bad) vs result==1 (good). The model predicts P(bad);
we KEEP trades with low P(bad). Legacy keeps everything.

Leakage guard: every feature uses ONLY indicator values strictly BEFORE the
trade timestamp (np.searchsorted with side='left').

Honest caveat: small sample (196 trades). This uses out-of-fold stratified CV,
NOT yet purged walk-forward — that rigor is epic E04. This is a first signal,
not a deployable result.
"""
import json
from pathlib import Path

import numpy as np
import pandas as pd
from scipy import stats
import lightgbm as lgb
from sklearn.model_selection import StratifiedKFold
from sklearn.metrics import roc_auc_score, average_precision_score

DATA = Path("/Users/daanvantongeren/Documents/Sites/brain/engine/data")
OUT = Path("/Users/daanvantongeren/Documents/Sites/brain/engine/out")
OUT.mkdir(parents=True, exist_ok=True)

WINDOW = 20                  # last K indicator observations before the trade
GOOD_RECALL_TARGET = 0.90    # keep >= 90% of good trades (conservative choice)
INDICATORS = ["vzo", "phobos", "obv-x-value", "mfi", "volumeud"]


def load():
    tr = pd.read_csv(DATA / "trades.tsv", sep="\t", na_values=["\\N"])
    tr["datetime"] = pd.to_datetime(tr["datetime"])
    ind = pd.read_csv(DATA / "indicators.tsv", sep="\t", na_values=["\\N"])
    ind["datetime"] = pd.to_datetime(ind["datetime"])
    ind["value"] = pd.to_numeric(ind["value"], errors="coerce")
    tr = tr.sort_values("datetime").reset_index(drop=True)
    ind = ind.dropna(subset=["value"]).sort_values("datetime").reset_index(drop=True)
    return tr, ind


def index_indicators(ind):
    """Pre-sort each indicator into (datetime64 array, value array) for fast point-in-time lookups."""
    out = {}
    for name, g in ind.groupby("indicator"):
        g = g.sort_values("datetime")
        out[name] = (g["datetime"].values.astype("datetime64[ns]"), g["value"].values.astype(float))
    return out


def features_for_trade(t_dt, idx):
    t = np.datetime64(t_dt, "ns")
    feats = {}
    for name in INDICATORS:
        key = name.replace("-", "_")
        if name not in idx:
            vals = np.array([])
        else:
            dts, vals_all = idx[name]
            cut = np.searchsorted(dts, t, side="left")   # strictly before t -> no leakage
            vals = vals_all[max(0, cut - WINDOW):cut]
        if len(vals) == 0:
            for suf in ["last", "mean", "std", "min", "max", "slope", "skew", "rangepct", "n"]:
                feats[f"{key}_{suf}"] = np.nan
            continue
        m = float(np.mean(vals))
        feats[f"{key}_last"] = float(vals[-1])
        feats[f"{key}_mean"] = m
        feats[f"{key}_std"] = float(np.std(vals))
        feats[f"{key}_min"] = float(np.min(vals))
        feats[f"{key}_max"] = float(np.max(vals))
        feats[f"{key}_slope"] = float(np.polyfit(np.arange(len(vals)), vals, 1)[0]) if len(vals) >= 2 else 0.0
        feats[f"{key}_skew"] = float(stats.skew(vals)) if len(vals) >= 3 else 0.0
        feats[f"{key}_rangepct"] = float((np.max(vals) - np.min(vals)) / abs(m)) if m != 0 else 0.0
        feats[f"{key}_n"] = float(len(vals))
    return feats


def build_matrix(tr, ind):
    idx = index_indicators(ind)
    rows = []
    for _, t in tr.iterrows():
        f = features_for_trade(t["datetime"], idx)
        f["rule"] = float(t["rule"])
        f["price"] = float(t["price"])
        f["y_bad"] = 1 if int(t["result"]) == 3 else 0
        f["datetime"] = t["datetime"]
        rows.append(f)
    return pd.DataFrame(rows)


def evaluate(df):
    feat_cols = [c for c in df.columns if c not in ("y_bad", "datetime")]
    X = df[feat_cols].astype(float)
    y = df["y_bad"].values
    oof = np.zeros(len(df))
    skf = StratifiedKFold(n_splits=5, shuffle=True, random_state=42)
    for tr_idx, te_idx in skf.split(X, y):
        m = lgb.LGBMClassifier(
            n_estimators=300, learning_rate=0.03, num_leaves=15,
            min_child_samples=5, subsample=0.8, colsample_bytree=0.8,
            class_weight="balanced", verbose=-1, random_state=0,
        )
        m.fit(X.iloc[tr_idx], y[tr_idx])
        oof[te_idx] = m.predict_proba(X.iloc[te_idx])[:, 1]
    auc = roc_auc_score(y, oof)
    ap = average_precision_score(y, oof)
    # threshold to keep >= GOOD_RECALL_TARGET of good trades: keep p_bad <= q-th quantile of good p_bad
    thr = float(np.quantile(np.sort(oof[y == 0]), GOOD_RECALL_TARGET))
    kept = oof <= thr
    return auc, ap, thr, kept


def stats_for(mask, y, kept):
    sub_y, sub_k = y[mask], kept[mask]
    n_good, n_bad = int((sub_y == 0).sum()), int((sub_y == 1).sum())
    good_kept = int(((sub_y == 0) & sub_k).sum())
    bad_kept = int(((sub_y == 1) & sub_k).sum())
    return dict(
        n_good=n_good, n_bad=n_bad,
        good_kept=good_kept, good_kept_pct=round(100 * good_kept / max(n_good, 1), 1),
        bad_kept=bad_kept, bad_dropped=n_bad - bad_kept,
        bad_dropped_pct=round(100 * (n_bad - bad_kept) / max(n_bad, 1), 1),
    )


def main():
    tr, ind = load()
    df = build_matrix(tr, ind)
    y, rules = df["y_bad"].values, df["rule"].values
    auc, ap, thr, kept = evaluate(df)

    res = {
        "model": {"auc_roc": round(auc, 3), "avg_precision": round(ap, 3),
                  "threshold": round(thr, 4), "good_recall_target": GOOD_RECALL_TARGET,
                  "method": "OOF stratified 5-fold CV (not yet purged walk-forward — see E04)"},
        "overall": stats_for(np.ones(len(df), bool), y, kept),
        "by_rule": {int(r): stats_for(rules == r, y, kept) for r in sorted(np.unique(rules))},
    }
    (OUT / "rule_filter_result.json").write_text(json.dumps(res, indent=2))

    print("=" * 64)
    print("MILESTONE 1 — ML entry-filter vs legacy (legacy keeps EVERY trade)")
    print(f"Model: AUC-ROC={res['model']['auc_roc']}  AvgPrecision={res['model']['avg_precision']}")
    print(f"Operating point: keep >= {int(GOOD_RECALL_TARGET*100)}% of good trades")
    print("-" * 64)
    o = res["overall"]
    print(f"OVERALL ({o['n_good']} good / {o['n_bad']} bad):")
    print(f"  good kept:   {o['good_kept']}/{o['n_good']}  ({o['good_kept_pct']}%)")
    print(f"  bad dropped: {o['bad_dropped']}/{o['n_bad']}  ({o['bad_dropped_pct']}%)   <-- legacy drops 0%")
    for r, s in res["by_rule"].items():
        print(f"RULE {r} ({s['n_good']} good / {s['n_bad']} bad): "
              f"good kept {s['good_kept_pct']}%, bad dropped {s['bad_dropped_pct']}%")
    print("=" * 64)
    print("CAVEAT: 196 trades, OOF stratified CV (NOT purged walk-forward). First signal, not deployable.")
    print(f"Wrote {OUT / 'rule_filter_result.json'}")


if __name__ == "__main__":
    main()
