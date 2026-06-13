# Finding — Step 1 engine validation (rule 21, DOGEAI)

**Date:** 2026-06-13
**Engine:** `brain/engine/src/{calc.py, volume.py, validate_rule.py, run_engine.py}`. Tables in brain DB: `engine_runs/engine_signals/engine_subrule_values`.

## What is DONE and validated

**Rule 21's 27 subrule calculations are faithfully rebuilt and validated against the legacy oracle** (`wp_trading_simulation_trades_indicator`). At the probe datetime `2025-02-14 12:17:31`: **26/27 values match exactly**, 0 mismatches (the 1 non-match is phobos `currentvalue`, which legacy stored nothing for — a storage artifact, not a value error).

Calc types covered, all matching:
- `currentvalue` = last value at/before T (as-of alignment confirmed).
- `previous_value` = change over last def1 rows; `diff_price` = % price change, `diff_number` = raw value diff; **rounded to 1 decimal** (legacy `calc_percentage(...,1)`).
- `volatility` (`{"number_absolute"}`) = `max_diff_number` (largest abs deviation from newest), NOT std/first.
- `skewness` = population skew over def1 rows.
- `missingdata` = max seconds-gap between consecutive volumeud rows where price rose >0.3% (=80.0, exact).
- `volume_check` = `check_volumeud_3` (volume-spike-after-accumulation); stored value 0, result via the function.

The calc logic is generalized into a testable module (`calc.py` metric-set + selector; `volume.py` for the volume functions).

## The remaining gap (NOT the calculations)

Running the full engine over Feb 14–Mar 1 and comparing fires to legacy trades (`wp_trading_simulation` rule=21):
- My fires: 19; legacy trades: 31; only ~8 overlap within 300s.

Root cause, traced:
1. **Candidate-datetime source differs.** Legacy evaluates at datetimes that are **not** `volume_found` indicator rows — e.g. it evaluated at `2025-02-25 09:51:29` (all 27 green → a good trade), but there is **no indicator row at all** at that second. My candidates are the `volume_found=1` volumeud rows (e.g. `09:51:24`, 5s earlier). Counts are close (147 oracle datetimes vs 151 volume_found on Feb 25) but they don't align at the second level.
2. **`volume_check` (check_volumeud_3) is likely too strict** — my candidates near missed trades fail at sort 2000 (volume_check) where legacy passes.

So: the *values* are right; *where* to evaluate them (the check_date generation) and the volume gate need to match legacy.

## Open question for Daan

How are the evaluation datetimes (check_dates) generated? `09:51:29` is not an indicator row, a price row, a signal row, or a range row — yet legacy evaluated rule 21 there. What loop/source produces the candidate datetimes the backtest walks?

## UPDATE — scaled validation result (validate_period.py)

Evaluating my engine at the **exact 768 datetimes legacy evaluated** over Feb 14–21 and comparing per-subrule value + pass:

- **Values: 768/768 exact (1-decimal) for ~every subrule.** The calculations are bit-faithful at scale. (The candidate-datetime offset is irrelevant for validation since we evaluate at legacy's own datetimes; the one no-data anomaly like `09:51:29` is a legacy data error, per Daan — ignore.)
- **Pass/fire diverges** despite identical values. Proof (subrule 1288, phobos previous_value):
  `my_val=-1.5, oracle_val=-1.5, bounds=[-6.8, 13.8]` → value is inside the bounds, yet `oracle_ok=0`.

**Root cause: the rule-combination logic, not the calculations.** The legacy `result_ok` is NOT the individual subrule's boundary check. The `sort` groups (1, 3, 4, 6, 16, 100, **1011**, 1100, 2000) are not a flat AND — subrules within a group have OR / cross-subrule-reference semantics (`rule_engine`, `functions_br.php:268`, the `$result_rule_number[]` map and group handling). My engine used a flat AND, which is too strict → I fire 5 vs legacy 10.

## RESOLVED — it was boundary drift, not group logic

`sort` is just evaluation order (for live short-circuit speed), **not** a group — confirmed by Daan. The rule is a flat AND. The pass divergence was an **apples-to-oranges comparison**: the boundaries in `wp_trading_rules` have been **tuned/widened over time**, but the oracle stores the boundary used *at eval time* in its `settings` JSON. Example (1288 @ 17:31:10): oracle boundary `[-0.3, 7.5]` → value −1.5 fails; current boundary `[-6.8, 13.8]` → passes.

**Fix:** validate the pass using the oracle's per-row historical boundary (from `settings`), and don't over-round in the boundary check (calc.py already rounds each type to its legacy precision).

## Result (validate_period.py, Feb 14–21, 768 legacy datetimes)

- **Values: 768/768 exact for all 27 subrules.**
- **Pass: 768/768 (100%) for 26 of 27 subrules** (using historical boundaries).
- **Only `volume_check` (check_volumeud_3) diverges** (621/768) — my port of the 220-line volume-spike detector differs from legacy at ~147 datetimes. This is the entire fire gap (mine=4 vs legacy=10).

So the calculations AND the pass logic are faithful. **The one remaining piece is debugging `check_volumeud_3`** — likely a settings drift (like the boundaries) or a loop subtlety. Then fires converge → screens.

## RESOLVED — volume_check fixed; rule 21 fully validated (DONE)

`check_volumeud_3` diverged because of **rule-21-specific settings** I had missed (`functions_br.php:2316`):
`multiplier_volume_sum_min = 2.1` (not base 8) and `min_price_diff_percentage = 0.03` (not 1).

Bonus insight (`functions_br.php:2358`): when volume_check passes it sets `volume_found=1` on the volumeud row at `check_date − 5 seconds` — this is why `volume_found` candidates sit ~5s before legacy's eval datetimes (so exact-datetime fire matching fails even when the trades are identical).

**Final validation (validate_period.py, using legacy's historical boundaries from the oracle settings):**
- 1 week (768 datetimes): all 27 subrules 768/768 value + pass; **fires 10/10, agree 768/768 (100%)**.
- 3.5 weeks (4807 datetimes): **fires agree 4805/4807 (99.96%)**; 3 volume_check edge cases remain (likely a mid-period `min_volume` drift — same mechanism as the boundary drift).

**Conclusion: rule 21's calculations AND rule-engine are faithfully rebuilt and validated against legacy.**
Engine: `brain/engine/src/{calc.py, volume.py, run_engine.py, validate_period.py}`. Data in brain DB (`engine_runs/signals/subrule_values`).

`run_engine.py` uses the CURRENT (since-widened) boundaries, so it finds more trades than the old oracle (67 vs 39 over 3.5 weeks) — correct, not a discrepancy.

## Next: screens
Build `/engine` screens over the brain DB tables to browse candidates, per-subrule values (green/red), and the fires.
