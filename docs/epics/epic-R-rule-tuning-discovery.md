# EPIC R: Rule tuning, scoring & automated discovery

**Phase:** 1 — Prove edge (build after the full-mode store + screens)
**Status:** Planned · concretizes grand-plan E06 (autonomous rule-discovery) + the precision half of E03/E05.
**Depends on:** the full-mode feature store (Epic B), promising periods (Epic A), the sell engine (Epic S, for overlap dedup).

## The objective (Daan's words, made measurable)

**Per coin, per rule: the number of BAD trades must not exceed the number of GOOD trades.**
And the automatable definition: **GOOD = a fire inside a promising period; BAD = a fire outside any promising period.** (Promising-membership replaces the partly-manual result=1/3 label — it is reproducible and complete.)

Two jobs, kept separate (recall vs precision):
- **Recall:** the *union* of rules should catch as many promising periods as possible.
- **Precision:** each rule is tuned/gated until its `#bad <= #good`.

## Current state (full-mode store, both coins)

| coin | promising best-entries (good opps) | rule-fires | fires inside promising (good) | fires outside (bad) |
|---|---|---|---|---|
| DOGEAI | 1276 | 423 | 108 | **315** |
| NOS | 1093 | 237 | 54 | **183** |

So today `#bad >> #good` for both — the precision problem, quantified with the automatable label.

## Trade-overlap dedup (Daan's requirement)

Fires overlap, even across rules: rule 20 opens a trade at 04:01, rule 21 fires at 04:04 while that trade is still open. In practice rule 21 would NOT execute (a position is open), so it must **not be double-counted in the result/P&L**. But for **rule evaluation** rule 21 is still credited as a (good) fire.

Therefore two distinct counts:
1. **Position count (result/P&L):** merge time-overlapping fires (across all rules) into non-overlapping positions (buy→sell via the sell engine). Each position's result counts once.
2. **Per-rule attribution (precision):** each rule's fires are scored independently for `#good/#bad`, regardless of whether a position was already open. A rule can be "successful" even if its fire wouldn't have executed.

## The process (what to get right)

### 1. Band tuning (`band_gate.py` — built)
Per (indicator, lookback, metric): the good envelope `[min,max]` and how many bad fires fall outside it. Finding: strict min/max keeps all good but drops little bad (~4%); a **trimmed band** (p2.5–p97.5, ~5% good lost) drops 27–33% of bad on a single feature. Top gate both coins: `volumeud lowest_value` (bad trades carry a recent sell-volume spike). → **stack trimmed gates** until `#bad <= #good`.

### 2. New-rule discovery (the hard part — to build)
Automate what Daan did by hand (start from a premise, often phobos, refine via graphs). This is **interpretable rule induction**: greedy/beam search over (indicator × lookback × metric × threshold) for axis-aligned bands that **retain promising periods (recall) and exclude non-promising fires (precision)**.
- **Do not privilege phobos.** Let the data pick the starting feature — "which gate blocks the most bad while keeping good" (band_gate already ranks this). The best start may be volume, obv, etc.
- **Greedy stacker:** pick the best gate, remove the bad it drops, repeat on the remaining bad → a conjunction = a candidate rule. 
- **Cover the missed promising periods:** for periods no current rule catches, induct a new rule (band conjunction) that fires inside them and little outside. Possibly 0, possibly 50 new rules — data decides.
- **Later: combine rules** (union for recall; shared gates for precision).

### 3. Refinement
Add calculations beyond the current 27 subrules; improve the volume formula (e.g. trailing-baseline relative volume) so more bad drop. Each addition is accepted only if it improves the held-out score.

## Manual annotations as a discovery target (built — coin_annotations)

The coin-explorer lets Daan click any promising period or fire and label it (pulldown +
comment): `te snelle stijging`, `te volatiel / schokkerig`, `exchange: niet uitvoerbaar`,
etc. These flag promising trades that look good on paper but won't execute in practice (the
exchange can't buy fast enough on a too-fast spike). Stored in `coin_annotations` (brain),
with the legacy `wp_trading_simulation.remark` shown read-only beside them.

For discovery this is gold: each flagged promising trade is a **negative the feature store
must learn to exclude**. The loop: Daan annotates → we search the feature store for a
band/feature that separates the flagged ones (e.g. a volatility/range metric that catches
"te volatiel", or an early-slope metric that catches "te snelle stijging") → propose a gate
that removes them from the promising set without dropping the clean ones. This refines the
good ground truth itself, not just the rules.

## The overfitting guard (THE thing to get right)

With ~1000 features × thresholds there are billions of options and few good examples (NOS: ~50 good fires). A greedy search **will** find spurious bands that look great in-sample. So every rule/gate must be validated **out-of-sample**:
- **Temporal split:** induct on the first ~70% of history, score on the last ~30%. `#bad <= #good` must hold on the held-out part.
- **Cross-coin:** a rule found on DOGEAI is re-scored on NOS (and vice-versa). Transfer is evidence it's real, not fitted.
- Report in-sample vs out-of-sample for every candidate; reject any rule whose edge doesn't survive. No rule ships on in-sample numbers alone.

## Acceptance criteria

- [ ] A scoring harness: per coin per rule, `#good/#bad` with overlap-deduped positions for P&L and per-rule attribution for precision.
- [ ] Band-tuning report per existing rule (20/21/22/23): which trimmed gates bring it to `#bad <= #good`, with held-out validation.
- [ ] A discovery loop that proposes new rules (band conjunctions) covering missed promising periods, each with in-sample AND out-of-sample scores.
- [ ] On DOGEAI and NOS: a rule set whose union meets `#bad <= #good` per rule on held-out data.

## Out of scope
- ML meta-filter (E03/E06 ML) — this epic is the interpretable-rule path that gets us to ~legacy level first; ML comes after.
- Live execution.

## Open questions (for Daan)
- Trimmed-band good-loss budget: 5% (p2.5–p97.5) acceptable, or stricter?
- `#bad <= #good` measured on promising-membership, or on result=1/3 where available, or both?
