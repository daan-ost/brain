# EPIC 09: Exit policy & position sizing (later)

**Phase:** 4 — Execution
**Status:** Planned (later)
**Depends on:** E03/E04 (a working entry filter to pair with)

## Goal

Optimize the other half of a trade — when to sell and how much to size — as separate, composable modules from the entry filter.

## Rationale

Daan: a good trade isn't only the entry. "10% was there, but it sold too late or too early." Exit timing is generally easier than entry but directly drives net return; position sizing scales both wins and losses. Keeping these separate from entry avoids the legacy monolith.

## Scope (high level — to detail when we reach this phase)

1. **Exit policy.** Model/optimize the sell moment (take-profit, trailing, time-based) against the available price path. Includes the protective check ("Future price"): exit if price falls ~1% below buy — cleanly on the exit side, where it belongs.
2. **Stop-loss.** Revisit `SL_settings` (the JSON on active strategies) as a tunable, backtested policy.
3. **Position sizing.** Size per signal confidence and risk, as its own module.
4. **Evaluation.** Through E04, on net P&L including exit and sizing.

## Acceptance criteria (to refine)

- [ ] Exit policy and sizing are separate modules, composable with any entry filter.
- [ ] The ≤1%-below-buy protective check lives on the exit side.
- [ ] Net P&L improves vs a naive fixed exit, validated through E04.

## Out of scope

- Live order placement (E10).

## Notes

- Daan currently does the ≤1%-below-buy check in selling rules and suspects some redundancy — worth consolidating here.
