# Good-moment definition — from the legacy code (authoritative)

**Date:** 2026-06-14 · Status: **code-authoritative.** The owner's fine-tuned method, recovered from `find_promising_trades()` and the auto-labeler. Corroborated by DOGEAI's trade data.
Feeds: E02 (labeling) + Epic A (good-period discovery).

## Source of truth

`legacy/managesignal/functions_br.php:8719` — `find_promising_trades($symbol, $datefrom, $settings, $log)`.
Caller + tuned settings: `legacy/managesignal/simulate_buy.php:1550`. Auto-labeler: `legacy/managesignal/save_subrule.php:1140`.

This replaces the earlier data-derived guess (which proposed `max_drawdown 1%` — wrong; the real early-dip gate is ~−0.1%).

## What the routine computes

Looks **180 minutes forward** from the entry datetime on the `volumeud` price series (asc, ≤1000 rows), `first_price` = price at entry. Then:

- **`percentage_highest`** = highest price in the window as % from entry. ("highest price in period → percentage".)
- **checkpoint gains** `perc15, perc30, … perc180` = % gain at count-fractions `round(count/12 * k)` of the window ≈ **15-minute steps**, gated by `period_length` (only checkpoints ≤ period_length are computed).
- **`lowest_10` / `lowest_20`** = deepest dip (negative %) within the first ~10 / ~20 ticks after entry. (Ticks, not minutes — volumeud is event-driven, so this is a short early window.)

## The verdict (is this moment "interesting"?)

`verdict = "buy"` iff ALL hold:

1. `percentage_highest > setting_percentage_highest` — peak upside over the window clears the bar.
2. at least one checkpoint `perc{15..120} > check_number_verdict` — the gain actually materializes (not a one-tick spike).
3. `perc15 ≥ first_15_above` (if set) — the early checkpoint itself clears a bar.
4. `lowest_10 ≥ max_lowest` (if set, max_lowest < 0) — **the early dip is no worse than this** → "only rises, at most a tiny dip first".

## Tuned settings — two profiles

**5-min rules (20, 21, 22, 23 — our scope):**

| Knob | Value | Meaning |
|---|---|---|
| `setting_percentage_highest` | **3%** | peak over window > 3% |
| `check_number_verdict` | **2%** | some checkpoint > 2% |
| `first_15_above` | **2%** | early (≈15-min) gain ≥ 2% |
| `period_length` | **60** min | checkpoints 15/30/45/60 |
| `max_lowest` | **−0.1%** (strict, when saving) / −1% (display) | early dip gate |

**Slower rules (everything else):** `setting_percentage_highest` 10, `check_number_verdict` 6, `first_15_above` 1, `period_length` 180, `max_lowest` −2. (Out of our current scope but documents the pattern.)

## The "good" auto-label (the cleanest statement)

`save_subrule.php` sets `result = 1` (good) when:

> **`profit_loss > 2%`  AND  `percentage_highest > 5%`  AND  `lowest_10 > −0.1%`**

- `percentage_highest > 5%` — clean upside (matches the data: good trades' p25 = +5.05%).
- `lowest_10 > −0.1%` — barely dipped first (matches the data: good trades averaged −0.06% adverse).
- `profit_loss > 2%` — realised profit. **For Epic A's entry-quality (independent of the sell), drop this** and use only `highest > 5%` AND `lowest_10 > −0.1%` — that is the available-upside view E02 argued for (label on upside, not realised profit).

## Defaults for Epic A (entry-quality, sell-independent)

| Knob | Value | From |
|---|---|---|
| min peak upside (`percentage_highest`) | **5%** | auto-labeler |
| early-dip gate (`lowest_10`) | **> −0.1%** | auto-labeler / strict save mode |
| forward window | **180 min**, 15-min checkpoints | find_promising_trades |
| early-gain gate (`first_15_above`) | **2%** | 5-min profile |
| checkpoint floor (`check_number_verdict`) | **2%** | 5-min profile |

## Corroborating data (DOGEAI 2525, read-only)

| label | n | avg peak-up | avg early-dip | worst dip |
|---|---|---|---|---|
| 1 goed | 78 | +12.54% | −0.06% | −1.69% |
| 3 slecht | 312 | +0.55% | −1.05% | −6.83% |

Good trades' upside p25 = +5.05%; 75/78 dipped < 1%, 0 dipped > 2%. The code's 5%/−0.1% gates sit right on this separation.

## Note on the rebuild

- Checkpoints are **count-fraction** based (`count/12`), so they approximate time only if ticks are evenly spaced. The rebuild should decide: replicate count-fraction exactly (faithful) or use true time-windows (cleaner). Faithful first, per project rule.
- `lowest_10` is first ~10 ticks, not 10 minutes — keep it tick-based to match legacy.
