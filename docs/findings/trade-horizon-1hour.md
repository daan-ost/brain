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

## Still to align

- The **sell-engine** (`validate_sell.py`, `MAX_MIN = 1500`) should adopt `FORWARD_MINUTES`
  as the max hold so buy-horizon and sell-horizon match. Parked under Epic S.
- Rebuild the Parquet feature store with the new periods before the next Epic R analysis.
