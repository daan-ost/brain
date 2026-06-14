# Good-moment defaults — grounded in DOGEAI's own good trades

**Date:** 2026-06-14 · Status: **proposed (data-grounded), awaiting Daan's final confirm.**
Feeds: E02 (labeling) + Epic A (good-period discovery).

## The question

What numbers define a "good entry moment" purely from the post-entry price path (triple-barrier / MFE)? Three knobs: `min_upside`, `max_drawdown`, and the forward `horizons`. Rather than guess, we read what DOGEAI's hand-labeled good trades actually did.

## Evidence (read-only query on `bot_signals`, DOGEAI = 2525)

Per-label, what the trades achieved (`highest_profit_loss` = max favorable excursion, `lowest_profit_loss` = max adverse excursion before exit):

| label | n | avg max-up | best up | avg dip-first | worst dip |
|---|---|---|---|---|---|
| **1 goed** | 78 | **+12.54%** | +174.45% | **−0.06%** | −1.69% |
| 2 middel | 49 | +2.25% | +17.15% | −0.29% | −2.39% |
| 3 slecht | 312 | **+0.55%** | +4.88% | −1.05% | −6.83% |

Upside distribution of good trades: p10 +3.08, **p25 +5.05**, median +6.86, p75 +12.02, p90 +18.13.

Dip-before-rise of good trades: **75/78 stayed above −1%**, 3 between −1% and −2%, **0 below −2%**.

## What the data says

- **Upside cleanly separates good from bad.** ~75% of good trades reach ≥5%; bad trades essentially never do (best bad = +4.88%, avg +0.55%). +5% is a near-perfect divider.
- **Good entries barely dip first.** Average dip before the rise is −0.06%; the worst good trade only dipped −1.69%. Bad trades averaged −1.05% adverse.
- **The 1% drawdown is not arbitrary** — it matches the sell model's hard floor (never more than ~1% loss). A good entry is, by construction, one that would *not* have been stopped out at the 1% floor before its rise.

## Proposed defaults

| Knob | Default | Why |
|---|---|---|
| `min_upside` | **5%** | p25 of good trades; bad trades never reach it |
| `max_drawdown` | **1%** | catches 75/78 good trades; equals the sell-floor (internal consistency) |
| `horizons` | **5 / 10 / 15 / 20 / 45 min** | stacked: did it reach +5% within any window? |

Tunable later. Calibration against the 78 good / 312 bad hand-labels (E02 scope #2) is the acceptance test: the definition should reproduce the good trades and exclude the bad ones, with a disagreement list for edge cases.

## Open
- `min_upside` 5% vs 4% (4% would catch a few more good trades at the cost of admitting near-miss bad ones) — confirm with Daan.
- Per-coin: these numbers are DOGEAI's. The second coin (TBD) should get its own read before assuming the same thresholds transfer.
