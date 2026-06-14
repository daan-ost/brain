# Trade horizon = 1 hour (config) + overlap removal

**Date:** 2026-06-14 · `engine/src/config.py` (`FORWARD_MINUTES = 60`).

## Why

A trade runs on the 5-minute timeframe and lasts at most ~1 hour. The legacy
`find_promising_trades` looked **180 minutes** forward, so a 14:10 entry could be called
"promising" because of a peak at 16:42 — 2.5 hours later, unreachable on a 5-min trade.
Daan: cap it to 1 hour, and the same horizon belongs to the sell-engine (max hold).

## Change

- `config.py::FORWARD_MINUTES = 60` — single source for the promising look-ahead AND the
  sell-engine max hold. `promising.py` now looks forward `FORWARD_MINUTES`, so upside and
  peak reflect a realistic hold.
- **Overlap removal:** clustering gap = `FORWARD_MINUTES`. A new trade can't start while
  one is running, so promising moments within an hour of the previous are the SAME
  opportunity; the best entry (highest upside) wins.

## Effect (re-validation vs labels)

| coin | metric | 180-min (old) | 60-min (new) |
|---|---|---|---|
| DOGEAI | precision | 0.849 | **0.967** |
| DOGEAI | recall | 0.795 | 0.744 |
| DOGEAI | full-labeler | 95.1% | 94.4% |
| NOS | precision | 0.797 | 0.796 |
| NOS | recall | 0.570 | 0.430 |

DOGEAI **precision jumped 0.85 → 0.97** (false positives 11 → 2): the 1-hour cap removes
the spurious "promising" entries that only looked good via a far-off peak. NOS recall drops
(some NOS good trades developed only after >1h — not realistically catchable on a 5-min
trade anyway). The 1-hour rule is the more realistic, more precise determination.

Periods: DOGEAI 1276 → 609, NOS 1093 → 453 (overlap merged).

## Worked example (Daan's 14 Feb case)

Old (180-min) showed three separate periods 14:10 / 15:42 / 16:22, all upside 31–44%,
because each 180-min window reached a far peak. New (60-min):
- 14:10 drops out (flat within its hour).
- 15:42 and 16:22 are the **same opportunity** (both peak at 16:42) → merged into one
  period. Best entry = **15:42** (upside 35.45%) not 16:22 (34.96%): same peak from a lower
  base = more upside. 16:00 fails (early dip −1.48%). The old "16:22 = 43.64%" was the
  180-min artifact.

## Refinement (2026-06-14b): short upside horizon — periods must be SHORT

A 1-hour look-ahead still made periods span HOURS: a moment was "promising" if the peak
came anytime in the next hour, so a 5-hour run-up chained into one period (e.g. 15 Feb
23:30→04:52). Daan: promising must be SHORT — the price rises *soon* (within x min), not
too fast, with a minimum duration.

Split the horizons in `config.py`:
- `FORWARD_MINUTES = 60` — max hold (sell-engine).
- `UPSIDE_MINUTES = 15` — promising upside horizon: the rise must arrive within this short
  window. The peak/upside come from here, so promising = the start of a quick move.
- `CLUSTER_GAP_MINUTES = 15` — distinct short moves stay separate periods (execution overlap
  is handled by the shadow logic on fires, not by merging periods).

Effect: 15 Feb went from ONE 5-hour blob to **4 short periods** (01:44 +14.6%, 02:22 +38.3%,
04:07 +12.6%, 04:49 +8.6%) — matching how Daan reads the chart.

Re-validation at UPSIDE=15 (vs labels): DOGEAI precision 0.98 / recall 0.60; NOS precision
0.78 / **recall 0.11**. Very precise but low recall — +5% within 15 min is rare, especially
on slower coins (NOS). **15 min is a starting point; the upside horizon + the extra gates
("niet te snel" rate cap, minimum duration) are the next tuning, likely per-coin.**

## Refinement (2026-06-14c): per-coin upside + duration gate + clean criteria

Calibrated on the good trades:
- **Per-coin `UPSIDE_MINUTES`** from each coin's p90 time-to-+5%: DOGEAI fast → 25 min, NOS slow
  → 45 min (default 30). DOGEAI good trades reach +5% in ~11 min median; NOS ~24 min.
- **Duration gate** (`MIN_DURATION_MINUTES=10`): the move must stay above entry (within −0.3%)
  ≥10 min. Strong separator — good trades sustain ~56 min, bad collapse in ~4-9 min; at 10 min
  it keeps ~71-81% good and drops ~52-70% bad. ("minstens een bepaalde duur")
- **"niet te snel" dropped** — the first-60s rise is ~0% for all trades (entries sit at the
  bottom before the move), so it carries no signal here.

The promising verdict is now the **clean 3-criteria definition** (legacy checkpoint logic removed):
`highest ≥ MIN_UPSIDE(5%)` within the per-coin upside window, `lowest_10 ≥ MAX_EARLY_DIP(−0.1%)`,
and `duration ≥ MIN_DURATION(10 min)`. **Best entry = the EARLIEST promising moment** (enter at
the start of the move, not near the top).

Re-validation (verdict vs labels): DOGEAI precision **0.982** / recall 0.705; NOS precision 0.771
/ recall 0.372. 15 Feb: clean short periods, each with an early best entry (e.g. the 01:17→02:47
climb now has best entry 01:17, not 02:20). DOGEAI 534 periods / 66 good fires; NOS 513 / 32.

### Still open / to tune
- NOS recall (0.37) — slow coin; revisit the per-coin upside or accept it.
- Whether a long continuous climb (e.g. 90 min) should split into sub-moves (currently one period).

## Still to align

- The **sell-engine** (`validate_sell.py`, `MAX_MIN = 1500`) should adopt `FORWARD_MINUTES`
  as the max hold so buy-horizon and sell-horizon match. Parked under Epic S.
- Rebuild the Parquet feature store with the new periods before the next Epic R analysis.
