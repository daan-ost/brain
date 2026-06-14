# EPIC 02: Labeling & entry-quality definition

**Phase:** 0 — Foundation
**Status:** Planned
**Depends on:** E01 (needs the price/indicator series and the slice)

## Goal

Turn "good trade" from a gut judgment into a defensible, coded definition based on the price path after entry — and use it to auto-label the 10,356 unlabeled trades, calibrated against the existing hand-labels.

## Rationale

The model can never beat its labels. Today's labels are manual and a bit inconsistent (one trade is "good" with only +0.5% upside). For a self-learning system we need an automatic, reproducible target. Crucially, a "good trade" mixes two things — a good *entry moment* and a good *exit*. For an entry filter we must label on **available upside**, not realized profit, so sloppy exits don't poison the training signal.

## Scope

1. **Entry-quality definition (coded) — the owner's method, formalized.** Based on the price path after entry, not realized `profit_loss`. The owner already used this method by hand and partly in code (legacy `calc_abs_diff_percentage()` at `functions_br.php:7709` computes max-upside% and max-drawdown% within a window; `find_promising_trades()` at `8719` is the routine). The rule:
   - **Multi-horizon forward check.** At the signal timestamp, compute the price move over several forward windows — **5, 10, 15, 20, 45 minutes** (configurable).
   - **Upside barrier.** A trade is a candidate-good if **at least one horizon** reaches the upside threshold (e.g. **≥5%**).
   - **Path constraint (drawdown).** It only counts if there was **no disqualifying drop first** — no decline beyond `max_drawdown` (e.g. **>1% below entry**) before that upside is reached. (This is the multi-horizon **triple-barrier** method: upside barrier + downside barrier + time barrier.)
   - **Whipsaw / instability filter (the addition).** Reject coins that swing wildly up and down — a path that reaches +6% via violent ±3% oscillation is *worse* than a clean climb and is often a bad trade. Score path quality (e.g. realized vol / reversal count of the post-entry path), not just the endpoint. This connects to the E05 volatility-gating insight.
   - The owner's note: *beyond hand-labeling, this can increasingly be determined from data* — so this definition is what lets us auto-label and scale past manual judgment. Parameters configurable; defaults to be confirmed.
2. **Calibration against hand-labels.** Run the definition over the 948 good / 2,503 bad hand-labeled trades. Tune parameters so the automatic label agrees with your judgment as closely as possible; report the confusion vs your labels and the disagreements (so you can eyeball edge cases like trade 12044).
3. **Auto-label the NULLs.** Apply the calibrated definition to the 10,356 unlabeled trades to expand the training set. Store automatic labels separately from human labels (never overwrite the source — labels live in the new results DB).
4. **Available-upside target.** Provide both a binary target (good/bad) and a continuous target (the max favorable excursion within the window) for regression experiments.
5. **Medium handling.** `result=2` may flow through but is treated passively (you do nothing active with it); primary training is good (1) vs bad (3).

## Acceptance criteria

- [ ] The entry-quality definition is implemented as a pure, point-in-time function (uses only post-entry *price*, never as an entry feature).
- [ ] Calibration report shows agreement between the automatic definition and the 1/3 hand-labels, with the disagreement list.
- [ ] The 10,356 NULL trades receive automatic labels, stored outside `bot_signals`.
- [ ] Both binary and continuous (available-upside) targets are available for the slice.
- [ ] The label uses multiple forward horizons (5/10/15/20/45 min) and rejects whippy/oscillating paths, not just the endpoint return.

## Recommended tooling & prior art (from research)

> Provenance: verified research bundle + adversarial verification pass (supersedes the earlier empty-payload note).

**The right names for what this epic does** [VERIFIED]
- The coded good-entry definition (drop ≤ max_drawdown, then ≥ min_upside within X minutes) **is the triple-barrier method** (López de Prado, *AFML* Ch. 3): upper barrier (min_upside), lower barrier (max_drawdown), vertical/time barrier (the X-minute window). The verifier confirmed this is the canonical formalization.
- Labeling on **available upside, not realized profit**, is **maximum favorable excursion (MFE)** (mirror: MAE; Sweeney 1996). The continuous target in scope #4 is literally MFE-within-window.
- Filtering the 776-subrule signal with a secondary model trained on these labels is **meta-labeling** (*AFML* Ch. 3), **verified** as established prior art with four peer-reviewed JFDS papers (2022–2023). Use the name — it unlocks the right literature.

**Tooling** [ESTABLISHED]
- Implement triple-barrier + MFE/MAE in **Polars** for speed. Cross-check the boundary logic against **mlfinlab** docs (`get_events`, `add_vertical_barrier`, `get_bins`) — but treat it as a **reference only**: mlfinlab is now **commercial (~£100/month per user)**, so do not take it as a runtime dependency. Lighter open port for cross-checking: **mlfinpy** (https://mlfinpy.readthedocs.io/en/latest/Labelling.html).

**Technique upgrades** [VERIFIED / REASONED]
- The known label inconsistency (a '+0.5% good' trade) is exactly what the meta-labeling literature warns about. Keep the calibration confusion-vs-handlabels and the disagreement list as **permanent artifacts**, not one-offs.
- Store automatic labels with a **label version / parameter hash** so retrains are reproducible and label sets are diffable.
- Provide **sample weights by MFE magnitude** (return-attribution / uniqueness weighting, *AFML* Ch. 4) so the rare +68% setups aren't drowned by marginal trades — directly supports E03's 'don't throw away the big winners.'
- If E03 ever uses the model's probability for bet sizing (E09), the classifier output must be **calibrated** (Platt scaling / isotonic regression) — raw tree-model probabilities are skewed (JFDS *Calibration & Position Sizing*, Spring 2023).

**Honest caveats** [VERIFIED concerns]
- **'The model can't beat its labels' is the binding constraint.** No tooling fixes bad labels — review a sample of auto-labels before training (the answer to the open question should be yes).
- **Labels are not stationary.** They were assigned in specific market regimes (bull/bear/lateral); a model trained on them learns regime-specific behavior, not universal alpha. The verifier flagged this for low-cap crypto specifically.
- **Survivorship bias:** the labeled trades come from coins liquid enough to trade and still observable; dead/rugged coins are under-represented, so the label set is systematically optimistic about low-caps.

**References**
- López de Prado, *AFML* (2018), Ch. 3 (triple-barrier, meta-labeling) & Ch. 4 (sample weights) — https://www.wiley.com/en-us/Advances+in+Financial+Machine+Learning-p-9781119482086 · Triple-barrier explainer — https://www.newsletter.quantreo.com/p/the-triple-barrier-labeling-of-marco · Meta-labeling theory & framework (JFDS 2022) — https://jfds.pm-research.com/content/early/2022/06/23/jfds.2022.1.098

## Out of scope

- Exit-timing modeling (E09). This epic only *defines* quality via the available path; it does not optimize selling.

## Proposed defaults (data-grounded — see findings/good-moment-defaults.md)

Read from DOGEAI's own 78 good / 312 bad hand-labels:

| Knob | Proposed | Why |
|---|---|---|
| `min_upside` | **5%** | p25 of good trades; bad trades essentially never reach it (best bad +4.88%) |
| `max_drawdown` | **1%** | catches 75/78 good trades; equals the sell-floor (internal consistency) |
| `horizons` | **5 / 10 / 15 / 20 / 45 min** | stacked — did it reach +5% within any window? |

Status: proposed, awaiting Daan's final confirm. Calibration vs the hand-labels is the acceptance test.

## Open questions (for Daan)

- Confirm `min_upside` = 5% (vs 4%, which catches a few more good trades but admits near-miss bad ones).
- Do you want to review the auto-labels on a sample before we train on them?
