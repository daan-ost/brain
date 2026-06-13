# EPIC 03: Entry-filter model PoC

**Phase:** 1 — Prove edge
**Status:** Planned
**Depends on:** E01 (features), E02 (labels), E04 (eval harness — can start in parallel)

## Goal

Train a gradient-boosting classifier on the labeled trades and prove, on the DOGEAI 25 Feb showcase, that at equal recall on good trades it drops more bad trades than the current hand-tuned subrules.

## Start narrow: one coin, one rule, one period

Do **not** model all rules at once. Start with **rule 20 on DOGEAI** — it has the worst false-positive ratio (31 good vs 128 bad across all dates), so it has the most to gain and enough labeled examples to learn from. Get this one rule right ("keep the 31 good, drop as many of the 128 bad as possible"), then expand to rule 21 (11 good / 26 bad), then 23, then a second coin. We focus only on the rules Daan actually used: **11, 12, 20, 21, 23**; the rest is junk and ignored.

## Rationale

This is the direct test of the whole premise: that an ML filter beats the manual rules at keeping the good and dropping the bad. If it can't beat the baseline out-of-sample, we learn that early and cheaply.

## Scope

1. **Model.** LightGBM/XGBoost classifier, target good (1) vs bad (3), features from E01. Handle class imbalance (948 good vs 2,503 bad vs auto-labeled NULLs) with class weights, not accuracy.
2. **Training set.** Labeled DOGEAI 5m days (lean, per the slice choice), plus auto-labeled trades from E02 where useful. Walk-forward split via E04 (train past, test later) — never mixed.
3. **Conservative threshold.** Tune the decision threshold to keep **≥90% recall on good trades** (your conservative choice), then maximize bad-trade rejection within that constraint.
4. **Showcase test.** On DOGEAI 25 Feb: report how many of the 5 good trades survive and how many of the 20 bad are filtered, at the chosen threshold.
5. **Baseline comparison.** Run the current subrules over the same slice (via the copied `wp_trading_rules`) and the "buy everything" baseline. Show the model rejects more bad at equal good-recall.
6. **Profit-weighted view.** Report not just counts but net P&L of the filtered set vs unfiltered — make sure we're not dropping the rare +68% winners.
7. **Explainability.** SHAP per decision and globally, so each filter call is inspectable ("filtered because vzo low and volume-skew negative").

## Acceptance criteria

- [ ] A trained model produces a `P(bad)` score per trade with a tuned threshold at ≥90% good-recall.
- [ ] On DOGEAI 25 Feb, the model keeps ≥90% of good trades and drops materially more bad trades than the 776 subrules at equal good-recall.
- [ ] Profit-weighted P&L of the filtered set ≥ baseline (we didn't throw away the big winners).
- [ ] SHAP explanations are produced for the showcase decisions.
- [ ] All splits go through the E04 harness (no leakage, walk-forward).

## Out of scope

- Autonomous rule generation (E06), coin gating (E05), live execution.

## Success criterion (the headline)

> At ≥90% recall on good trades, the ML filter rejects significantly more `result=3` trades than the current 776 subrules — on out-of-sample data. That is the proof of Daan's core goal.

## Recommended tooling & prior art (from research)

> Provenance: verified research bundle + adversarial verification pass (supersedes the earlier empty-payload note). No third-party 'ML beats baseline by X%' figure survived verification — prove edge only in our own purged, post-cost harness (E04).

**Model stack — order matters for our sample size** [VERIFIED reasoning]
- **CatBoost** (https://github.com/catboost/catboost) as the **first** choice: its Ordered Boosting has the largest documented benefit on datasets **under 40k samples** and is more stable on imbalanced financial classification than LightGBM. Use `class_weights` for imbalance. (Our good+bad core ≈ 3.4k — squarely in CatBoost's sweet spot.)
- **LightGBM** (https://github.com/microsoft/LightGBM) as the fast-iteration baseline. For imbalance use `is_unbalance=True` **OR** `scale_pos_weight` — **never both** (silent override); `scale_pos_weight = n_neg/n_pos` is the recommended start.
- **XGBoost** (https://github.com/dmlc/xgboost) as a cross-check; agreement across all three is more credible.
- **Note:** if a path ever routes through FreqAI, **CatBoost was removed in FreqAI 2025.12** — that path is LightGBM/XGBoost only.

**Metric & threshold** [VERIFIED]
- **Optimize AUC-PR or F-beta (β<1 to weight precision), NEVER accuracy or AUC-ROC.** With imbalance, AUC-ROC can read 0.75+ while positive-class precision is below 0.5.
- Tune the decision threshold jointly with hyperparameters in **Optuna** (https://github.com/optuna/optuna) — the default 0.5 is almost never optimal at low positive-class rate. The **GHOST** method (JCIM 2022, https://pubs.acs.org/doi/10.1021/acs.jcim.1c00160) formalizes exactly this joint threshold search; the structural problem is identical to ours.
- **Calibrate** probabilities (Platt/isotonic) before thresholding so the ≥90%-recall threshold is stable out-of-sample (and so E08's CBPE drift monitor works later).

**Explainability → rules** [VERIFIED]
- **SHAP** (https://github.com/shap/shap) TreeSHAP for global importance and per-decision attribution. **SHAP alone does NOT produce rules** — it gives attributions. To get the human-readable IF-THEN conditions for the rule-20 showcase, add **imodels** (https://github.com/csinva/imodels): RuleFit / Skope-Rules / FIGS convert tree paths into L1-selected readable rules. This is the bridge to E06.

**Honest caveat** [VERIFIED base rate]
- The base rate for 'ML beats a tuned baseline net of costs, out-of-sample' is low. A **small** improvement at equal good-recall is the realistic best case for rule 20; a **large** in-sample lift is a red flag for leakage, not a win. One practitioner account (LightGBM, AUC 0.58, Sharpe 1.4 after costs on crypto) also disclosed an **18% loss on a $2M live deployment despite 73% backtest accuracy** — traced to normalization leakage + regime shift. Every PoC number must pass E04 before it counts.

**References**
- LightGBM docs — https://lightgbm.readthedocs.io · CatBoost vs LightGBM vs XGBoost — https://towardsdatascience.com/catboost-vs-lightgbm-vs-xgboost-c80f40662924/ · SHAP (Lundberg & Lee 2017) — https://arxiv.org/abs/1705.07874 · imodels — https://github.com/csinva/imodels · GHOST threshold method — https://pubs.acs.org/doi/10.1021/acs.jcim.1c00160 · *AFML* Ch. 3 (meta-labeling)
