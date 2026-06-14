# EPIC S: Sell-engine precision (87% → higher)

**Phase:** 0/1 — Foundation dependency (high-level placeholder so this is not forgotten)
**Status:** Parked — known gap, revisit before Epic A's per-datetime outcomes are trusted as ground truth.
**Depends on:** `validate_sell.py`, `sell_rule101.py`, methodology/selling-process.md

## Why this exists

The rebuilt sell engine currently reproduces **~87% of total P&L** vs legacy (mine +954.7% vs legacy +1102.0% over DOGEAI closed trades). That is good enough to move on for now, but it is a **silent dependency of Epic A**: Epic A runs the sell engine at *every* datetime in a good window to get "the result per moment". Every one of those results carries the sell engine's error. So before we treat per-datetime outcomes as ground truth — or train anything on them — the sell engine's accuracy must be either improved or explicitly bounded.

## The known gap

The 87% version is conservative and correct on the floor sells (the losing trades). The remaining error is **rule-101 sell-signal timing** — winners run slightly too long or exit slightly early because the exact moment rule 101 tightens the stop is imprecise. The blocker last time was that nailing it needs worked, minute-by-minute examples of the SL trail on a few specific trades.

## What this epic must produce (when picked up)

1. **A bounded error statement.** Per trade, the tolerance between my `profit_loss` and legacy's — so any consumer (Epic A, ML) knows the confidence of a per-datetime outcome.
2. **Rule-101 timing fix.** Reproduce the exact datetime rule 101 sets/raises the stop, using worked examples from `wp_trading_simulation_trades_indicator` for rule 101.
3. **Re-validation.** `validate_sell.py` total-P&L fidelity moves meaningfully above 87%, with the exact/within-0.5% agreement counts reported.
4. **A "confidence" column** carried into Epic A's `period_datetime_outcomes` so screens/graphs can show how trustworthy each per-datetime result is.

## Acceptance criteria

- [ ] Documented per-trade error bound between rebuilt and legacy sell outcomes.
- [ ] Rule-101 sell-signal timing validated against the oracle on worked examples.
- [ ] `validate_sell.py` total-P&L fidelity reported and improved vs the 87% baseline.
- [ ] Epic A's per-datetime outcomes carry a confidence/tolerance value.

## Out of scope

- Exit-policy *optimization* (selling better than legacy) — that is E09. This epic is about **faithfully reproducing** legacy selling, precisely enough to trust per-datetime outcomes.

## Notes

- The sell model itself is settled (max-of-mechanisms, never-lowered, trail from market/peak — see methodology/selling-process.md). This epic is precision, not redesign.
