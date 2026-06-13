#!/usr/bin/env python3
"""
Milestone 1 — HARDENED evaluation.

Two upgrades over poc_rule_filter.py:
  1. Purged WALK-FORWARD CV (time-honest) instead of shuffled stratified CV.
     Train only on the past; embargo a gap before each test block so a test
     trade cannot share its feature/label window with a neighbouring train trade.
     Thresholds are chosen on each fold's TRAIN side only (no test leakage).
  2. NET P&L AFTER COSTS, with a cost sensitivity sweep — because slippage,
     not fees, is the thesis-killer on 5m low-cap coins.

MEXC fees (June 2026): maker 0% / taker 0.05% per side -> ~0.10% round-trip for
market orders (less with MX discount / limit orders). Real cost = fees + slippage.
We sweep round-trip cost so we SEE where the edge dies.

Honest scope: still a small sample (196 trades) on one coin / two rules. This is
the honest first read, not a deployable backtest (that's epic E04 at full scope).
"""
import json
import sys
from pathlib import Path

import numpy as np
import pandas as pd
import lightgbm as lgb
from sklearn.metrics import roc_auc_score

sys.path.insert(0, str(Path(__file__).parent))
from poc_rule_filter import load, build_matrix  # reuse leak-free feature builder

OUT = Path("/Users/daanvantongeren/Documents/Sites/brain/engine/out")
OUT.mkdir(parents=True, exist_ok=True)

GOOD_RECALL_TARGET = 0.90
N_SPLITS = 5
EMBARGO = pd.Timedelta("3h")                 # gap purged between train and test
# round-trip cost scenarios (% of position): fee + slippage, both sides combined
COST_SCENARIOS = {
    "fees_only_taker (0.10%)": 0.10,
    "low slippage (0.30%)": 0.30,
    "moderate (0.60%)": 0.60,
    "high low-cap (1.00%)": 1.00,
}


def walk_forward_oof(df):
    """Expanding-window walk-forward with embargo. Returns p_bad for forward-test trades only."""
    df = df.sort_values("datetime").reset_index(drop=True)
    # EXCLUDE outcome/leaky & non-stationary columns from features:
    #   profit_loss = the outcome (label leak!), price = absolute coin price (time proxy)
    feat_cols = [c for c in df.columns if c not in ("y_bad", "datetime", "profit_loss", "price")]
    X = df[feat_cols].astype(float)
    y = df["y_bad"].values
    dt = df["datetime"].values
    n = len(df)

    oof = np.full(n, np.nan)
    kept = np.zeros(n, dtype=bool)
    # forward test blocks = last N_SPLITS contiguous chunks
    bounds = np.linspace(0, n, N_SPLITS + 1, dtype=int)
    for k in range(1, N_SPLITS + 1):
        te_lo, te_hi = bounds[k - 1], bounds[k]
        if te_lo == 0:
            continue  # need some history to train the first block
        test_idx = np.arange(te_lo, te_hi)
        test_start = df["datetime"].iloc[te_lo]
        # train = everything before the test block, minus an embargo window
        train_mask = (df["datetime"] < (test_start - EMBARGO)).values
        train_idx = np.where(train_mask)[0]
        if len(train_idx) < 15 or y[train_idx].sum() == 0 or (1 - y[train_idx]).sum() == 0:
            continue
        m = lgb.LGBMClassifier(
            n_estimators=300, learning_rate=0.03, num_leaves=15,
            min_child_samples=5, subsample=0.8, colsample_bytree=0.8,
            class_weight="balanced", verbose=-1, random_state=0,
        )
        m.fit(X.iloc[train_idx], y[train_idx])
        p_train = m.predict_proba(X.iloc[train_idx])[:, 1]
        p_test = m.predict_proba(X.iloc[test_idx])[:, 1]
        oof[test_idx] = p_test
        # threshold chosen on TRAIN good trades only (keep >=90% of train good)
        good_p_train = p_train[y[train_idx] == 0]
        thr = np.quantile(good_p_train, GOOD_RECALL_TARGET) if len(good_p_train) else 1.0
        kept[test_idx] = p_test <= thr
    return df, oof, kept


def pnl(profit_loss, mask_taken, cost):
    """Mean & total net P&L (%) over taken trades after round-trip cost."""
    taken = profit_loss[mask_taken]
    if len(taken) == 0:
        return dict(n=0, mean=0.0, total=0.0)
    net = taken - cost
    return dict(n=int(len(taken)), mean=round(float(net.mean()), 3), total=round(float(net.sum()), 2))


def main():
    tr, ind = load()
    df = build_matrix(tr, ind)
    # attach realized profit_loss
    df = df.sort_values("datetime").reset_index(drop=True)
    df["profit_loss"] = pd.to_numeric(tr.sort_values("datetime")["profit_loss"].values, errors="coerce")
    df = df.dropna(subset=["profit_loss"]).reset_index(drop=True)

    df, oof, kept = walk_forward_oof(df)
    scored = ~np.isnan(oof)               # forward-test trades only
    y = df["y_bad"].values
    pl = df["profit_loss"].values

    auc = roc_auc_score(y[scored], oof[scored]) if len(np.unique(y[scored])) > 1 else float("nan")
    n_good = int((y[scored] == 0).sum()); n_bad = int((y[scored] == 1).sum())
    good_kept = int(((y[scored] == 0) & kept[scored]).sum())
    bad_kept = int(((y[scored] == 1) & kept[scored]).sum())

    # cost sweep: legacy takes ALL scored trades, filter takes only KEPT scored trades
    legacy_mask = scored
    filter_mask = scored & kept
    sweep = {}
    for name, cost in COST_SCENARIOS.items():
        sweep[name] = {
            "legacy_all": pnl(pl, legacy_mask, cost),
            "filtered": pnl(pl, filter_mask, cost),
        }

    res = {
        "method": "purged walk-forward CV (embargo 3h); threshold chosen on train only",
        "n_scored": int(scored.sum()),
        "model": {"auc_roc_walk_forward": round(auc, 3)},
        "operating_point": {
            "good_recall_target": GOOD_RECALL_TARGET,
            "good_kept": good_kept, "n_good": n_good,
            "good_kept_pct": round(100 * good_kept / max(n_good, 1), 1),
            "bad_dropped": n_bad - bad_kept, "n_bad": n_bad,
            "bad_dropped_pct": round(100 * (n_bad - bad_kept) / max(n_bad, 1), 1),
        },
        "cost_sweep_net_pnl": sweep,
    }
    (OUT / "rule_filter_hardened.json").write_text(json.dumps(res, indent=2))

    op = res["operating_point"]
    print("=" * 70)
    print("MILESTONE 1 — HARDENED (purged walk-forward + costs)")
    print(f"Forward-tested trades: {res['n_scored']}   Walk-forward AUC-ROC: {res['model']['auc_roc_walk_forward']}")
    print(f"At >= {int(GOOD_RECALL_TARGET*100)}% good-recall (threshold set on train only):")
    print(f"  good kept:   {op['good_kept']}/{op['n_good']} ({op['good_kept_pct']}%)")
    print(f"  bad dropped: {op['bad_dropped']}/{op['n_bad']} ({op['bad_dropped_pct']}%)")
    print("-" * 70)
    print("NET P&L per trade (%) after round-trip cost — legacy(all) vs filtered(kept):")
    print(f"  {'cost scenario':<26}{'legacy mean':>13}{'filtered mean':>15}{'edge':>9}")
    for name, s in res["cost_sweep_net_pnl"].items():
        lm, fm = s["legacy_all"]["mean"], s["filtered"]["mean"]
        print(f"  {name:<26}{lm:>12.3f}%{fm:>14.3f}%{(fm-lm):>+8.3f}")
    print("=" * 70)
    print("CAVEAT: 196 trades, one coin, two rules. Honest direction, not a deployable backtest.")
    print(f"Wrote {OUT / 'rule_filter_hardened.json'}")


if __name__ == "__main__":
    main()
