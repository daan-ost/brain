# Finding — Milestone 1: entry-filter on DOGEAI rules 20/21

**Date:** 2026-06-13
**Scope:** DOGEAI 5m (`symbol 2525`), rules 20 + 21, 196 hand-labeled trades (42 good / 154 bad), 5 base indicators + simple window features. Engine: `brain/engine/src/{export_slice.sh,poc_rule_filter.py,harden_rule_filter.py}`.

## Headline

The entry-filter shows **real in-distribution structure but no out-of-sample edge** on this slice. The apparent first result (42% bad dropped) was an artifact of leakage + non-time-aware CV.

| Test | AUC-ROC | Read |
|---|---|---|
| Shuffle CV, **with** `price` + `profit_loss` as features | 0.76 / 0.95 | inflated — leakage |
| Shuffle CV, leak-free | **0.76** | genuine in-distribution signal exists |
| Purged walk-forward, leak-free | **0.50** | does NOT transfer forward in time |

## What went wrong in the first pass (caught during hardening)

1. **`profit_loss` leaked in as a feature** in the first hardened run — it *is* the outcome. Gave AUC 0.955 / "100% bad dropped". Removed.
2. **`price` (absolute coin price) is a time-proxy** — drifts monotonically in trends, lets a shuffle-CV model memorise the era. Removed from features.
3. **Shuffle CV is not time-honest** — folds share the whole time range, so the model exploits period structure it won't have live. Replaced with purged walk-forward (embargo 3h, threshold set on train only).

## Diagnosis: non-stationarity

Leak-free shuffle CV = 0.76 but leak-free walk-forward = 0.50. The indicator→quality relationship is **real within a period but drifts across periods**. A single static model trained on the past cannot predict the future on this coin over a 146-day span.

After costs the point is moot: with AUC ≈ 0.50 the filter has no real edge to bank, so the cost-sweep (legacy ~+1%/trade vs filtered negative) just reflects the filter rejecting almost everything.

## Implications

- **Trust only time-honest, leak-free validation.** Shuffle CV and any level/outcome feature flatter us. (Reinforces E04 and the leakage guard in E01.)
- **The static entry-filter is the hard path** (matches the research's low–moderate prior). Drift is the enemy.
- **Higher-prior bets:** (a) the **volatility gate (E05)** — low-DOF, economically grounded, more likely stationary; (b) **short-horizon retraining** (train recent → predict near-term) per E08; (c) **more data** (more coins/time → more than 42 good trades for a robust forward test); (d) **proper multi-horizon labels (E02)** in case the manual `result` labels are noisy.

## Caveats

Small sample (42 good), one coin, two rules, ~4 walk-forward folds. AUC 0.50 has a wide confidence interval — this is "no edge demonstrated," not "edge disproven." But the shuffle-vs-walk-forward gap (0.76 → 0.50) is large and consistent with regime drift.
