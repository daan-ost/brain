# Strategy — filtering good vs bad trades

**Date:** 2026-06-13 · Approved by Daan.

## The core principle: split recall from precision

The mistake that stalls every attempt is trying to make **one rule** both catch all good trades *and* drop the bad ones. Those goals fight: tightening a rule's boundaries to drop bad trades also drops good ones. The fix is to separate the two concerns into layers.

### Layer 1 — RECALL (catch every good moment)

A **union of precise rules**. Each rule is one sharp hypothesis (volume gate + an anchor-indicator regime). Goal of this layer: **miss no good trade** — bad trades may still come through.

- Missed good moments → **add a new rule**, don't widen an existing one to mush.
- Rules are OR'd together: a trade is a candidate if *any* rule fires.
- Precondition (the enabler): **capture ALL potential good trade moments** as ground truth first (E02). Only then can we measure, per rule, which good moments are caught and which are still uncovered → those need a new rule.

### Layer 2 — PRECISION (drop the bad)

A filter layer on top of the rule-union, with three levers:

1. **More calculations / features** (beyond skewness & volatility) — the full legacy "Test type" library. More features = better good/bad separation. (E01/E06)
2. **Coin volatility-gating** — stop trading coins that have gone quiet; removes a whole class of bad trades cheaply. (E05)
3. **ML as the final "rule"** — meta-labeling: the model takes the rule-union's signals + the features and decides buy / skip. This is the layer that hits the precision target. (E03/E06)

> **"Max as many bad as good" is a PRECISION target — hit it in Layer 2, NOT by tightening the rules** (which loses good trades). This is the key reframe.

## Ordered plan

| # | Item | Layer | Epic |
|---|---|---|---|
| A | Selling process (rule 101 + time-based stop-loss) — needed for net P&L | (separate) | E09 |
| B | Port remaining buy rules: 20 → 22 → 23 → 10/11/12/18 | Recall union | Step-1 cont. |
| C | Capture all good trade moments (ground truth) — the enabler | unlocks measurement | E02 |
| D | Add more calculations/features | Precision | E01/E06 |
| E | Volatility coin-gating (stop a coin earlier) | Precision | E05 |
| F | ML filter-rule (meta-labeling) | Precision | E03/E06 |
| G | Iteratively take over more coins/periods (not all 100M) | data | E07 |

**Sequence:** A (selling, for net P&L) → B (complete the recall union) → C (capture good moments) → then the precision layer (D/E/F). Precision work needs the ground truth from C.

Why A first: without the sell side we have no net P&L, so we cannot tell whether filtering actually makes money — which is the whole point.
