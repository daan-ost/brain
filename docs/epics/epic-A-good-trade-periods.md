# EPIC A: Good trade-period discovery + per-datetime outcome store + explorer

**Phase:** 0 — Foundation (build now)
**Status:** Planned
**Depends on:** E02 (the entry-quality definition), the sell engine (`validate_sell.py`)
**Refines:** E02 (labeling) + methodology/feature-store.md — this is the concrete, buildable version with screens.

## Goal

Find **good trade periods independent of whether any rule fires** — a from→to window where a good trade could start — and, for **every datetime inside that window**, (1) store all computed indicator/feature values and (2) run the sell-engine check so we know the **potential result at every moment**. Then make it explorable: screens + a price/result graph.

This turns "which moments are good entries" into measurable ground truth that does not depend on the current rules — the substrate for discovering new rules and training the precision filter.

## Rationale

Some good moments are already caught by rules 20/21/22/23; many are not. If we only store rule-fires we can never find the moments the rules miss. By scanning whole good-potential windows and recording the per-datetime feature values + the per-datetime sell outcome, we get a complete, queryable picture: "if you had bought at this minute, here is what every indicator said and here is what you would have made."

## Scope

1. **Good-period definition.** Use E02's entry-quality method (triple-barrier / MFE: upside reached within a forward horizon, no disqualifying drop first, clean non-whippy path) to mark from→to windows where a good trade could start — **without requiring a rule to fire**. Output: a list of `(symbol, from_datetime, to_datetime)` good windows per coin.
2. **Per-datetime feature snapshot.** For every indicator datetime inside each window, compute `window_metrics` (calc.py) for every indicator and store the values. (The full lookback cube is Epic B; here we store at least the as-of values needed to reason about the moment.)
3. **Per-datetime sell-engine outcome.** For every datetime in the window, run the sell engine **as if bought at that datetime** → store `selling_price`, `selling_date`, `profit_loss %`, time-in-trade, highest/lowest excursion. So per datetime we know the result.
4. **Storage.** New brain DB tables (read-only `bot_signals` stays untouched):
   - `good_periods` — `(id, symbol_id, from_datetime, to_datetime, label_version, params_hash, created_at)`.
   - `period_datetime_outcomes` — `(id, good_period_id, symbol_id, datetime, selling_price, selling_date, profit_loss, minutes_in_trade, highest_pl, lowest_pl)`. One row per datetime per window.
   - Per-datetime feature values reuse Epic B's store (don't duplicate).
5. **Screens (Laravel/Livewire, `www`).** Following the WorkMyAgent sidebar pattern already used for `/trades`:
   - **Good periods list** — filter by coin/period; columns: coin, from–to, length, # datetimes, best achievable %, # rule-fires inside.
   - **Period detail** — table of every datetime in the window with the per-datetime sell outcome (profit_loss, sell datetime) and whether a rule fired there.
6. **Graph.** A price chart over the window with the per-datetime result overlaid: price line + a colored band/heat showing the sell-outcome % per entry datetime, and markers where rules fired. So you can *see* where the good entry moments are and where the current rules catch vs miss them.

## Acceptance criteria

- [ ] Good-potential windows are produced per coin from price-path alone, independent of rule-fires, with a `params_hash`/`label_version`.
- [ ] For every datetime in each window, the sell-engine outcome is stored (profit_loss, sell datetime, excursions).
- [ ] `good_periods` and `period_datetime_outcomes` exist in the brain DB and are populated for the DOGEAI slice.
- [ ] A `/good-periods` screen lists windows with filters (coin/period) and a detail view per window.
- [ ] A graph shows price + per-datetime result + rule-fire markers over a window.
- [ ] Validation: where a window contains a real legacy trade, the per-datetime outcome at that buy datetime matches the legacy `profit_loss` within the sell engine's known tolerance.

## Out of scope

- The full per-rule lookback cube (Epic B).
- The ML filter / new-rule search (later precision epics) — this epic produces their input, it does not build them.
- Live execution.

## Decided

- **Good-period defaults:** `min_upside` 5%, `max_drawdown` 1%, horizons 5/10/15/20/45 min — data-grounded, see findings/good-moment-defaults.md (awaiting final confirm on 5% vs 4%).
- **Rule set is fixed:** buy rules 20/21/22/23 only; 10/11/12/18 stay out of scope. No need to enumerate other rules.

## Open questions (for Daan)

- Graph: per-entry sell-outcome as a heat band over the price line — is that the view you want, or a separate result line under the price chart?
- Do we scan every indicator datetime in a window, or down-sample for very long windows?
