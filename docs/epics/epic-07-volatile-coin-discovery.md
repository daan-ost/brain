# EPIC 07: New volatile-coin discovery

**Phase:** 3 — Autonomy
**Status:** Planned
**Depends on:** E05 (the gating concept), E04 (to prove added coins help)

## Goal

Scan the universe for coins entering a tradable volatility regime and propose them for activation — the intake side of E05's gate, feeding the active-coin set the rest of the system trades.

## Rationale

E05 removes coins that went quiet. The mirror image is finding coins that just woke up. Since the edge lives in volatile low-cap meme/AI coins, continuously refreshing the active set is what keeps the system fed with opportunity instead of trading stale names.

## Scope

1. **Universe scan.** Over the available symbols (8,360 known, 105 currently active), measure recent volatility/activity and detect coins entering a tradable regime.
2. **Data-availability check.** Confirm a candidate has enough indicator feed (the TradingView webhook must be delivering its series) before proposing it.
3. **Candidate ranking.** Rank by expected tradability (volatility, volume, feed quality) and propose a shortlist for activation.
4. **Hand-off to gating.** Activated coins enter E05's active/inactive lifecycle.
5. **Backtest intake value.** Through E04: show that coins added this way would have produced net-positive filtered trades.

## Acceptance criteria

- [ ] A scan ranks candidate coins by tradable-volatility signals and feed availability.
- [ ] Proposed coins flow into E05's active set with state tracking.
- [ ] Backtest evidence that intake adds net-positive opportunity.

## Recommended tooling & prior art (from research)

> Provenance: verified research bundle + adversarial verification pass (supersedes the earlier empty-payload note).

**Reuse, don't reinvent** [REASONED, shares E05's verified primitives]
- This is the **intake side of E05's regime gate** — the same volatility primitives (realized vol / ATR / range %) and the same HMM/Choppiness/ADX signals, applied across the **full 8,360-symbol universe** instead of the active set. **No new ML is needed.** Compute the metrics with **DuckDB** universe scans and rank candidates by tradable-volatility signal + feed availability. Share the rolling-window code with E05.

**Tooling** [ESTABLISHED]
- **DuckDB** (https://duckdb.org) for fast universe-wide scans; **Polars** for the rolling-window volatility compute (shared with E05). **ruptures** (offline, https://centre-borelli.github.io/ruptures-docs/) to check that a candidate's new volatility regime is a genuine change-point, not a one-bar blip.

**Technique notes** [VERIFIED concern]
- **Survivorship / one-spike bias is the central risk** (the verifier flagged it for the labeled set, and it applies doubly to a recency-ranked universe scan). **Require volatility to persist across the window**, not just appear once — rank on persistence, not peak.
- Make the **data-availability / feed-completeness check a hard ranking gate**, not a soft signal: a coin with a sparse/irregular indicator feed produces garbage features.
- Validate intake value through E04 (would the added coins have produced net-positive *filtered* trades) so the scan can also say 'nothing worth adding this cycle' — the same can-say-no discipline as E06.

**Honest caveat** [REASONED]
- The whole low-cap universe is the regime where edges decay fastest and slippage bites hardest (verified for 5m low-cap). A coin entering a tradable regime today can be dead in days — couple every activation to E05's deactivation lifecycle so the active set self-cleans.

**References**
- DuckDB — https://duckdb.org · ruptures (Truong, Oudre, Vayatis 2020) — https://arxiv.org/pdf/1801.00826 · shares ATR/realized-vol/HMM primitives with epic-05.

## Out of scope

- Onboarding new TradingView feeds/webhooks (depends on Daan's TV setup, out of project scope per decision #7).

## Open questions (for Daan)

- Does activating a coin require any TradingView-side setup to start its webhook feed, or do all 8,360 already stream?
