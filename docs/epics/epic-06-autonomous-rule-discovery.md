# EPIC 06: Autonomous rule-discovery loop

**Phase:** 2 — Robustness
**Status:** Planned
**Depends on:** E01, E02, E03, E04

## Goal

A search that proposes new subrules *and* new strategies, tests each leak-free out-of-sample through E04, and rejects what doesn't generalize — turning the manual rule-tuning you used to do by eye into an automatic, honest loop.

## Rationale

Per Daan's choice, the system should invent new strategies, not just tune within the 8 existing ones. The legacy discovery always produces a rule (envelope + `removeExtremes`), so it always overfits. The fix is a search that can say "no good rule found," favors simple robust rules, and proves edge out-of-sample before anything is kept.

## Scope

1. **Search over subrules.** Optimize boundaries/parameters within a strategy's base hypothesis (volume gate + anchor indicator regime) using Bayesian optimization (Optuna) over the feature/threshold space.
2. **Search over strategies.** Propose new `rule_number`-level hypotheses: new anchor indicators and feature combinations, optionally via genetic programming over the feature library. Respect the "volume gate + anchor" structure as a prior, since it worked.
3. **Leak-free evaluation gate.** Every candidate scored only through E04 (walk-forward, purge/embargo, baselines, profit-based fitness). Candidates that don't beat baseline out-of-sample are rejected.
4. **Overfit control.** Complexity penalty (fewer conditions preferred) and a hard reject when train-vs-test degradation exceeds a bound. The loop must be able to return "nothing found."
5. **Explanation → human-readable rule.** SHAP on winners produces readable conditions ("phobos in [−55,−30] and volume-skew > 0"), stored as candidate rules.
6. **Audit trail & promotion.** Every candidate, score, and decision logged. Human approves promotions first; auto-promotion is a later toggle once trust is established.
7. **Results DB.** Discovered rules and their evidence live in the new database the Laravel app manages — never in `bot_signals`.

## Acceptance criteria

- [ ] The loop generates candidate subrules and strategies and scores each only via E04.
- [ ] It rejects candidates that don't beat baseline out-of-sample, and can return "nothing found."
- [ ] A complexity penalty is applied; overfit candidates are filtered.
- [ ] Winners come with SHAP-derived human-readable conditions and a full audit record.
- [ ] Promotion is human-gated, with an auto-promote toggle scoped for later.

## Out of scope

- The daily scheduling that runs this (E08), self-modifying code (E08 north-star).

## Recommended tooling & prior art (from research)

> Provenance: verified research bundle + adversarial verification pass (supersedes the earlier empty-payload note). **This is the highest overfitting-risk epic in the project. The verification verdict on GP-for-trading was `partly-verified` with sharp qualifications — read the caveats before the tools.**

**The right names** [VERIFIED prior art, PARTLY-VERIFIED efficacy]
- Parameter search within a hypothesis = **Bayesian optimization**. Proposing new strategies = **automated strategy search / symbolic regression / genetic programming for trading rules** (Allen & Karjalainen 1999). The **verified honest picture:** GP *does* produce out-of-sample predictive signal (equity IC ~0.03–0.07 across independent groups), but the canonical Allen & Karjalainen result is that **vanilla GP does NOT beat buy-and-hold after costs** on liquid markets, and **vanilla gplearn/DEAP is specifically the weak baseline in every modern benchmark.** Strong positive results (Warm-Start GP: Sharpe ~1.06 vs vanilla ~0.22; AlphaGen KDD 2023) all add **RL augmentation / warm-start seeding / grammar constraints** and are tested on **Chinese equities at daily timeframe** — a very different regime from 5m low-cap crypto. Defensible here ONLY because E04 gates every candidate and the loop can return 'nothing found.'

**Tooling** [VERIFIED]
- **Optuna** (https://github.com/optuna/optuna) — TPE/Bayesian search over boundaries and thresholds within a strategy's base hypothesis. Use its study/trial tracking as the audit trail.
- **gplearn** (https://github.com/trevorstephens/gplearn) — `SymbolicTransformer` to generate **new derived features** from the indicator windows. Note: gplearn outputs **features, not trading rules** — you must apply a downstream classifier (e.g. decision tree / imodels) to get IF-THEN rules.
- **DEAP** (https://github.com/DEAP/deap) — the correct choice if you want **profit-based fitness** and **strongly-typed GP (STGP)** to forbid semantically invalid expressions (e.g. dividing a momentum value by a volume value). Respect the 'volume gate + anchor indicator' structure as a strong prior/grammar constraint — this is exactly the kind of constraint the verified literature says vanilla GP lacks.
- **imodels** (https://github.com/csinva/imodels) to distill any GBM/GP winner into readable conditions for the audit trail.

**Mandatory overfit controls** [VERIFIED]
- Feed the **number of trials** into a **Deflated Sharpe Ratio / PBO** correction (pypbo, from E04). After ~7 independent trials an in-sample Sharpe>1 at zero alpha is expected (MinBTL) — without trial-count deflation this epic is a backtest-overfitting machine.
- Constrain the search to limit memorization: `max_depth ≤ 4`, `min_samples_leaf ≥ 30`, evaluate fitness on a **held-out** fold the evolution never sees. Hard-reject on train-vs-test degradation; complexity penalty (fewer conditions). Require survival on **unseen coins AND unseen periods** before any promotion.
- **Strictly separate** the data used to *discover* rules from the data used to *evaluate* them — shared splits inflate apparent precision (the verifier flagged this leakage path explicitly).

**Honest caveat** [VERIFIED]
- Expect backtest Sharpe in the **0.2–1.0** range with heavy sensitivity to market and time window. Alpha decay is **faster in low-cap crypto** than the equity markets where GP research is done — a rule found in one 3-month window may have zero edge in the next. The closest published autonomous-discovery analogs (QuantEvolve, CGA-Agent — both `partly-verified`) have authors who themselves flag **data-snooping bias** and tiny test universes. Treat every winner with maximum suspicion; **a clean 'nothing found this cycle' is a success.**

**References**
- Optuna (Akiba et al. 2019) — https://optuna.org · Allen & Karjalainen (1999) — https://doi.org/10.1016/S0304-405X(98)00052-X · Vectorial GP for trading (honest OOS, 2025) — https://arxiv.org/html/2504.05418v1 · Warm-Start GP alpha mining (2024) — https://arxiv.org/abs/2412.00896 · Strongly-typed GP for trading — https://www.sciencedirect.com/science/article/pii/S0950705125001017

## Open questions (for Daan)

- The dead 8-line fitness scoring (`showEffect.php:577`) — once you check whether it was intentional, do we adopt its logic as the fitness base?
