# Promising-port validation — ascending order wins

**Date:** 2026-06-14 · `engine/src/promising.py` ports legacy `find_promising_trades()`.

## The order ambiguity

The legacy fetch `get_indicator_bydate_unix()` hard-forces `ORDER BY datetime DESC` and ignores the `"asc"` argument passed by `find_promising_trades`. That would make `result[0]` the price ~180 min *after* entry (not the entry itself) — semantically wrong for "upside from entry". Rather than guess, we ported both orders and let the `result=1/3` labels arbitrate.

## Result (labeled trades, result 1 vs 3)

| coin | order | precision | recall | full labeler agreement |
|---|---|---|---|---|
| DOGEAI (2525) | desc (literal legacy) | 0.348 | 0.205 | 84.1% |
| **DOGEAI** | **asc** | **0.849** | **0.795** | **95.1%** |
| NOS (244) | desc | 0.455 | 0.174 | 65.7% |
| **NOS** | **asc** | **0.797** | **0.570** | **78.3%** |

"entry-quality" = `highest > 5% AND lowest_10 > −0.1%`. "full labeler" also requires realised `profit_loss > 2%`.

## Decision

**Port ascending (entry = `[0]`, look forward).** The literal DESC is a legacy quirk; the hand-labels (the owner's actual judgment) follow ascending intent. We are faithful to the labels, not to the buggy fetch. `promising.py` defaults to `asc`.

## Reading the numbers

- **DOGEAI 95.1%** — the port reproduces the owner's good/bad judgment. Validated.
- **NOS 78.3%, recall 0.57** — the 5% / −0.1% thresholds are DOGEAI-tuned and do not transfer cleanly. NOS misses 74 good trades (likely bigger early dips or smaller peaks in that regime). **Per-coin thresholds are needed** — exactly the non-stationarity caveat in E02. Action: calibrate `percentage_highest` / `max_lowest` per coin before trusting auto-labels on a new coin.

## Next

- Cluster the raw promising moments into periods (they overlap minute-to-minute during one rise) and pick the best entry per period — `cluster_promising.py`.
- Overlay rule 20/21/22/23 fires to see what the rules catch vs miss.
