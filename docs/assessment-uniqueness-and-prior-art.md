# Assessment — Uniqueness & Prior Art

**Date:** 2026-06-13
**Author:** Senior quant-ML architect review
**Scope:** Honest appraisal of how novel the `nobrainersbot` approach is, the right academic/industry names for what we're building, what the hard evidence actually shows about achievable edge in retail/low-cap crypto ML, the concrete stack to use, and what genuinely differentiates *our* situation.

> **Provenance (this revision).** Unlike the first draft of this document — which was written against *empty* research payloads — this version incorporates a **fan-out research run (6 topic bundles) plus an adversarial verification pass (6 verdicts).** Where the verification verdict was `verified`, the claim is stated plainly. Where it was `partly-verified`, `unverified`, or `refuted`, that status is reflected in the wording. Community/marketing numbers are never presented as verified fact. Tags used below:
>
> - **[VERIFIED]** — confirmed by the adversarial verification pass against a primary source.
> - **[PARTLY-VERIFIED]** — real but with material caveats the verifier flagged (e.g. backtest-only, single market, conflict of interest).
> - **[ESTABLISHED]** — standard textbook quant/ML knowledge, not contentious.
> - **[COMMUNITY / UNVERIFIED]** — a blog/forum/marketing claim that did *not* survive verification; treat as a hypothesis, not a fact.

---

## 0. One correction before anything else: our dataset is bigger than the research assumed

The research bundles repeatedly reasoned about a **"~3,400-trade labeled dataset."** That number is the **good+bad hand-labeled subset** (948 good + 2,503 bad = 3,451). Our actual ground truth is **15,262 rows**: 948 good, 710 medium, 2,503 bad, and **10,356 unlabeled** that E02 will auto-label via a coded triple-barrier/MFE definition. So:

- Every small-sample warning in the research (CPCV fold sizing, MinBTL trial budget, overfitting on a tight set) applies to the **hand-labeled core (~3.4k)**, which is what E03's first model trains on. Those warnings stand and are load-bearing.
- After E02 auto-labels the NULLs, the effective training set is materially larger (~13k+), which relaxes — but does not remove — the small-sample constraints, *provided the auto-labels are calibrated and trustworthy*. Garbage auto-labels would make the larger set worse, not better.

Read every "3,400" in the sourced research as "the labeled core"; we have more, but only as much as E02's calibration earns us.

---

## 1. How unique is our approach? (Honest verdict)

**Verdict: the *method* is well-trodden and now explicitly verified as prior art; the *edge*, if it exists, is in our data, labels, and discipline — not in the architecture.** [VERIFIED for the method; REASONED for the edge location]

The pipeline we designed —

> meta-labeling a primary rule-signal → engineered technical features → gradient-boosting filter → regime/volatility gating → walk-forward validation with purge/embargo → profit-based fitness

— is, almost beat for beat, the canonical recipe from Marcos López de Prado's *Advances in Financial Machine Learning* (2018). The adversarial verifier **confirmed with high confidence** that meta-labeling is introduced in *AFML* Ch. 3, p. 50 ("I call this problem meta labeling because we want to build a secondary ML model that learns how to use a primary exogenous model"), and that it has four peer-reviewed follow-up papers in the *Journal of Financial Data Science* (2022–2023). It is taught, published, and implemented in open source. **We did not invent it and should not pretend we did.**

What that means, in two directions:

**The good (de-risking).** Proven scaffolding exists, and the verification pass closed the door on "is this real?" — it is. Every component (meta-labeling, GBM filters, leakage guards, purged CV) has reference implementations, published failure modes, and a body of practitioners who already hit the potholes. Crucially, the verifier confirmed the **single most important nuance in our favor**: meta-labeling's *valid* use case is precisely **a non-ML, rule-based primary model** — which is exactly our 776 subrules. The well-known "squeeze the orange twice" critique (QuantConnect/Baldisserri) only applies when the primary model is *itself* ML trained on the same data; it does **not** apply to us, and in fact *validates* our architecture. We sit squarely in the documented success zone, not at its edge.

**The sobering (no moat).** Because the method is standard, **there is no secret sauce in the algorithm.** Anyone with the same data and the same textbook builds the same thing. The architecture is necessary but not sufficient: it stops us fooling ourselves, but it cannot manufacture an edge the data doesn't contain. If our labeled trades and the TradingView indicators carry no exploitable signal, a flawless López de Prado pipeline will correctly tell us so. The edge, if real, lives in three places only: **(a) the proprietary indicator feed and its derived features, (b) the hand-labeled ground truth, and (c) the volatility-gating insight.** The ML is the harness, not the horse.

Where we are genuinely *off* the well-trodden path, and how risky that is:

- **Autonomous rule discovery that proposes *new strategies*, not just tunes existing ones (E06).** This is the high-risk frontier. The verifier rated the underlying claim **`partly-verified`**: genetic programming *does* produce out-of-sample predictive signal (equity IC ~0.03–0.07 across multiple groups), but **vanilla gplearn/DEAP is specifically the weak baseline in every modern benchmark**, the canonical Allen & Karjalainen (1999) result is that plain GP does *not* beat buy-and-hold after costs on liquid markets, and the only strong positive results (Warm-Start GP, AlphaGen) add RL/warm-start/grammar constraints on top of GP and are tested on Chinese *equities at daily timeframe* — a very different regime from 5m low-cap crypto. E06 is defensible **only** because E04's gate sits in front of it and the loop is allowed to return "nothing found."
- **Self-extension / the system shipping its own code diffs (E08 north-star).** Not standard quant practice; an agentic-systems ambition layered on top. The closest published analogs (Microsoft RD-Agent — NeurIPS 2025, `verified`; QuantEvolve / CGA-Agent — `partly-verified`, authors themselves flag data-snooping risk) show automated discovery loops *can* beat a baseline on backtests, but none has audited live results and all warn loudly about overfitting. Fine as a staged, human-gated direction; **not** where any edge comes from.
- **Using human-labeled *trade quality* as the supervised target** (rather than raw price direction). The research notes this specific framing has *sparse direct prior art in open-source frameworks* though it maps to "trade quality classification" in proprietary shops. This is a modest, genuine point of differentiation (see §5) — it reduces label noise versus regressing on forward returns.

**One-line verdict:** *Textbook method, correctly chosen and now verified as prior art. The novelty budget should be spent on data, labels, and the volatility gate — not on reinventing the pipeline. Our realistic prior on success is "modest, conditional, and entirely dependent on whether the hand-labeled signal survives out-of-sample, after costs" — not "we have a unique algorithm."*

---

## 2. Prior art & the right names

Use these names — they make the work legible to any quant and unlock the right libraries and literature. All rows are [VERIFIED] or [ESTABLISHED] unless flagged.

| What we call it (roadmap) | The established name | Canonical source |
|---|---|---|
| "Entry-filter on top of the 776 subrules" | **Meta-labeling** (secondary model that filters/sizes a primary model's bets) — [VERIFIED] | López de Prado, *AFML* (2018), Ch. 3; Joubert et al., *JFDS* 2022–2023 |
| "Coded good-entry definition from the price path" | **Triple-barrier labeling** (upper/lower/vertical barriers) | *AFML*, Ch. 3 |
| "Available upside, not realized profit" | **Maximum favorable excursion (MFE)** / path-dependent labeling | Sweeney (1996); *AFML* Ch. 3 |
| "Walk-forward with purge/embargo" | **Purged K-Fold** → **Combinatorial Purged Cross-Validation (CPCV)** — [VERIFIED] | *AFML*, Ch. 7; SSRN 4778909 |
| "Leakage guard / point-in-time contract" | **Look-ahead-bias prevention / label concurrency** | *AFML* Ch. 7; "Label Concurrency" (MQL5) |
| "Profit-based fitness, not accuracy" | **Economic/utility backtest metric**; **Deflated Sharpe Ratio (DSR)** — [VERIFIED] | Bailey & López de Prado, *The Deflated Sharpe Ratio* (2014, JPM) |
| "The search must be able to say no" | **Probability of Backtest Overfitting (PBO)** under multiple testing — [VERIFIED] | Bailey, Borwein, López de Prado, Zhu (2014/2016) |
| "Complexity penalty for simple rules" | **Regularization / MinBTL trial budgeting**; deflated metrics under N trials — [VERIFIED] | *AFML*; "What to Look for in a Backtest" (SSRN 2308682) |
| "SHAP per decision → human-readable rule" | **Model explainability (Shapley)** → **RuleFit / imodels** for IF-THEN extraction | Lundberg & Lee (2017); Friedman & Popescu (2008) RuleFit |
| "Coin volatility gating" | **Regime filtering / volatility-regime conditioning / tradability gating** | HMM regime literature; QuantStart HMM-in-QSTrader |
| "Autonomous rule discovery proposing new strategies" | **Automated strategy search / symbolic regression / GP for trading rules** (high overfitting risk) — [PARTLY-VERIFIED] | Allen & Karjalainen (1999); Vectorial GP (arXiv 2504.05418) |
| "Drift monitoring: live diverges from backtest" | **Concept drift / model decay**; **CBPE** for label-delayed estimation; PSI/KS drift | NannyML CBPE docs; Evidently |

**Takeaway:** essentially everything in Phases 0–2 has a precise, *verified* name and a reference implementation. When briefing any contractor or LLM, say "meta-labeling with triple-barrier targets, validated under combinatorial purged CV against the deflated Sharpe, with the trial count fed into PBO" — not "a smart entry filter."

---

## 3. Verified vs hype — what the hard evidence actually shows

The owner asked for hard, verified data on achievable edge in retail/low-cap crypto ML, not marketing. Here is the brutally honest version, now backed by the verification pass.

### What is VERIFIED (safe to rely on)

1. **Meta-labeling on a rule-based primary is real, named, peer-reviewed, and fits us exactly.** [VERIFIED] Confirmed in *AFML* Ch. 3 and four *JFDS* papers. The technique is *designed* for "start high-recall on a rule, raise precision with a secondary classifier" — our exact plan.

2. **Backtest overfitting is the dominant failure mode, and it is mathematically guaranteed if you try enough rules.** [VERIFIED] Bailey, Borwein, López de Prado & Zhu (2014, peer-reviewed, J. Computational Finance / AMS Notices) proved that after **~7 independent trials** you can expect to find a 2-year backtest with Sharpe > 1 at **zero true alpha** (the MinBTL result). The more configurations E06 searches, the more the in-sample winner is the luckiest noise-fit. This is *why* E04, a deflated metric, and E06's "nothing found" power are non-negotiable.

3. **CPCV beats walk-forward for ML model selection.** [VERIFIED] Arian, Norouzi & Seco (*Knowledge-Based Systems*, 2024) found in a controlled synthetic study that CPCV achieves the lowest PBO and best DSR of {walk-forward, purged k-fold, CPCV}. Walk-forward remains the right tool for *live-deployment simulation*; CPCV is the right tool for *choosing among candidates*.

4. **There is essentially NO independently audited, live-tracked, profitable retail/crypto bot track record.** [VERIFIED] The verifier found the retail/crypto layer "weak to fraudulent": the SEC charged multiple fake crypto "trading platforms" in 2025; community leaderboards (freqst.com, NostalgiaForInfinity) publish **backtests only** — a "100% win rate on a 13-day $100 simulated wallet" is statistically meaningless. Survivorship bias is endemic. **Assume any specific online return figure is false until reproduced in our own purged, post-cost harness.**

5. **The strongest *real-money* ML evidence in the space is Numerai — but it is structurally non-replicable by us.** [PARTLY-VERIFIED, leaning credible] Numerai posted +25.45% net / Sharpe 2.75 in 2024 and drew a JPMorgan **$500M capacity commitment** (Aug 2025) — hard-to-fake institutional due-diligence signal. **But:** it trades *liquid global equities* via an ensemble of **1,200+ uncorrelated external models**; the diversification *is* the edge. A single operator with correlated signals on 5m low-cap crypto cannot expect anything like Sharpe 2.75.

6. **Qlib / RD-Agent: serious research infrastructure, backtest-only evidence.** [PARTLY-VERIFIED] RD-Agent (NeurIPS 2025) hit 14.21% annualized vs 6.80% LightGBM baseline — but on **CSI300 Chinese large-caps, with no slippage/market-impact modeled**. Qlib's own benchmarks are backtests on a single market. The broad replication literature shows 65–82% of published quant anomalies fail out-of-sample. Do **not** treat Qlib numbers as evidence of live edge.

### What is suggestive but UNVERIFIED (do NOT trade on these)

- **"Meta-labeling turned −44% into +53% on Bitcoin."** [COMMUNITY / UNVERIFIED] This Medium result (Bollinger + LightGBM, purged-embargo CV, Jan–Jun 2022) is structurally identical to our plan and *directionally encouraging*, but it omits transaction costs, uses only two 6-month windows, and the single test period coincides with a crash (regime timing may explain it). Indicative, not audited.
- **Hudson & Thames "accuracy 20%→77%."** [COMMUNITY / UNVERIFIED] Published by the *mlfinlab maintainers* (conflict of interest), only two strategies tested, out-of-sample precision moved a modest 0.17→0.20. Use the *library*, discount the *marketing*.
- **GP "discovers profitable rules."** [PARTLY-VERIFIED, historically a red flag] Positive results exist (Warm-Start GP: Sharpe ~1.06 vs vanilla ~0.22) but only on Chinese equities, daily bars, with enhanced (non-vanilla) GP, never independently replicated, never live-audited. Vanilla gplearn/DEAP is the *weak* baseline.
- **"1.2% daily ROI", "22% higher Sharpe", "2,143% since 2020" (SaintQuant, Stoic, etc.).** [COMMUNITY / UNVERIFIED — treat as marketing] No primary source traceable for any of them.

### The honest bottom line on edge

- Realistic outcome distribution: **most likely the ML filter yields a *small* precision improvement over the 776 subrules at equal good-recall, which may or may not clear costs; a meaningful chance of no durable out-of-sample edge; a smaller chance the proprietary indicator + volatility-gate combination yields a genuinely useful, modest edge.** [REASONED]
- **Slippage is the thesis-killer at our resolution.** [VERIFIED concern] At 5m on coins with \$50K–\$500K daily volume, a 0.4–1% round-trip cost can exceed the entire modeled alpha. Qlib, RD-Agent, and most papers *exclude* this. A filter that "drops bad trades" is worthless if survivors don't clear costs. Model real MEXC fees + slippage **early** (E04/E10), before tuning any model.
- **The volatility-gate (E05) is, on priors, the most likely single source of robust improvement** — not because it's clever ML, but because "don't trade dead instruments" is a low-degrees-of-freedom, economically-motivated regime conditioner that is hard to overfit. The verified HMM evidence (QuantStart: drawdown 35.7%→24%, Sharpe 0.37→0.48 just from a 2-state regime filter) supports the *shape* of this bet. The owner's "biggest lever" intuition aligns with what durable edges actually look like.
- **The project's value is not "we will make money." It is "we will find out honestly, cheaply, and fast whether this data has an edge."** The harness (E04) is worth building even if every model fails.

---

## 4. Recommended concrete stack (mapped to phases/epics)

Maturity/versions below are from the research bundle (verified where noted) and should be re-confirmed at install time. **Two hard procurement facts up front:** (1) **mlfinlab is now commercial (~£100/month per user)** — we use it as a *reference for the formulas*, not a runtime dependency, and assemble the open-source equivalents; (2) **the original `pandas-ta` PyPI package is compromised** (2025 maintainer-transfer / wiped PyPI history) — use the `pandas-ta-classic` fork instead.

| Phase / Epic | Concrete tools (verified-maturity) | Why |
|---|---|---|
| **E01 — Data foundation** | **DuckDB** 1.2+ + **Parquet** + **Polars** 1.x; **pandas** only where ecosystem demands | Benchmarked production-grade at 100M–1B rows. DuckDB wins decisively on *memory* for big Parquet scans (1.3 GB vs Polars 17 GB on a 140 GB file); **partitioning by coin+date matters more than the engine choice** (8× memory reduction). DuckDB has native `REGR_SLOPE` and `ASOF JOIN` for aligning the 5 indicator streams. Caveat: Polars `rolling_skew` is documented-slow (issue #17339) — route skewness through DuckDB. |
| **E01 — feature discovery (offline only)** | **tsfresh** (Blue Yonder) for one-shot exhaustive discovery + **FRESH** selection; **tsfel** for fractal/entropy features TA-Lib lacks; then **re-implement only the survivors** in Polars/DuckDB for the live path | tsfresh extracts 794 features but is far too slow (5–15 min / 10k obs) for inference; ~50% are correlated FFT coefficients. Correct pattern: discover offline, FRESH-select to 30–80 features, hand-port to Polars. |
| **E01 — indicator primitives** | **TA-Lib** 0.6.x (Statistic Functions: STDDEV, VAR, LINEARREG_SLOPE map directly to our derived features); **pandas-ta-classic** (NOT original `pandas-ta`) | TA-Lib is the fastest bulk compute; the 0.6.x branch ships pre-built wheels (verify wheel for your Python/arch in CI). |
| **E02 — Labeling** | **Triple-barrier + MFE/MAE** in Polars; cross-check boundary logic against **mlfinlab** docs (reference only); store labels with a **version/param hash** | These are the exact named methods for "available-upside" labels. The hand-labels ≈ a manual triple-barrier. |
| **E03 — Entry-filter model** | **CatBoost** *first* (ordered boosting, documented advantage on <40k & imbalanced sets) → **LightGBM** as fast baseline → **XGBoost** as a check; **SHAP** (TreeSHAP); **imodels** (RuleFit/FIGS) to turn the model into IF-THEN rules; **Optuna** for joint threshold+hyperparameter search; optimize **AUC-PR / F-beta**, never accuracy | The research's defensible ranking for our sample size. Note **FreqAI dropped CatBoost in 2025.12** — if we ever route through FreqAI, that path is LightGBM/XGBoost only. Calibrate probabilities (Platt/isotonic) before thresholding *and* before any CBPE drift monitoring. |
| **E04 — Validation harness** | **timeseriescv** (`CombPurgedKFoldCV` — free CPCV with purge+embargo) and/or **skfolio** (CPCV); **pypbo** for PBO + Deflated Sharpe; cost-realistic backtest via **NautilusTrader** (event-driven, Rust core) for low-cap fills, **vectorbt** (OSS, v1.0 Apr 2026) only for fast parameter sweeps; **mlfinlab** as paid gold-standard *if* budget allows | This epic is the spine. CPCV + PBO + DSR are the verified honesty trio. **vectorbt is vectorized, not event-driven** — it cannot model partial fills/market impact, so its low-cap results are optimistically biased; use NautilusTrader for the realism gate. Budget the **MinBTL trial count** before searching. |
| **E05 — Volatility gating** | **hmmlearn** GaussianHMM (2–4 states) for the regime signal — **use `predict()` forward-filtered states, NEVER smoothed/Viterbi** (lookahead); **ruptures** (PELT, offline) for per-coin calibration of dead-period length; realized-vol / ATR / range% / Choppiness Index / ADX as simple gates | Verified: HMM regime filter cut drawdown ~31% on equities. Keep it low-DOF. Anchor state meaning by mean-volatility after each retrain (state indices swap). Pair the vol gate with a breakout re-activation so we don't gate out a coin right before a pump. |
| **E06 — Rule discovery** | **Optuna** (TPE/Bayesian) for param search within a hypothesis; **gplearn** (`SymbolicTransformer` for new features) and/or **DEAP** (profit-based fitness, **strongly-typed GP** to forbid nonsensical expressions) for new strategies — **only behind E04's gate**; **imodels** to make winners readable; feed trial count into PBO/DSR | Verified caveat: vanilla GP is the weak baseline; durable results need grammar constraints + warm-start + diversity selection. Treat every winner with maximum suspicion; "nothing found" is a success. |
| **E07 — Coin discovery** | Same vol primitives as E05 over the full 8,360-symbol universe via **DuckDB** scans; **ruptures** offline for persistence checks | Intake side of the same gate; no new ML. Require volatility to *persist* across the window (survivorship/one-spike guard). |
| **E08 — Orchestration & drift** | **MLflow** (experiment tracking + model registry, `transition_model_version_stage()` for promote/demote); **NannyML** (CBPE — performance estimation *without* labels, the right answer to delayed trade outcomes); **Evidently** (batch PSI/KS/Wasserstein drift reports); **river** (streaming drift) optional; Laravel scheduler + queued jobs invoke Python | Verified MLOps pattern: drift → train challenger → evaluate on recent OOS window → promote only if ALL gates pass. CBPE needs *calibrated* probabilities (see E03). Add a **minimum retrain interval (~7d)** + require drift to persist, to avoid retrain-cascade on volatile crypto. |
| **E09 — Exit/sizing** | Backtest exits in **NautilusTrader**/vectorbt; sizing via simple risk-fraction first (avoid Kelly-on-noisy-edge early); calibrated M2 probability → bet-size multiplier (JFDS Spring 2023 calibration paper) | Exits are easier (owner's read) but need the same purged, post-cost validation. |
| **E10 — Execution** | **CCXT** for MEXC (battle-tested signing/transport) wrapped in a Laravel state machine; `SSL_VERIFYPEER=true`, encrypted keys | Don't hand-roll exchange signing. **Verify CCXT's current MEXC coverage** (order types, thin-orderbook edge cases, partial fills, mid-trade delisting) — CCXT abstracts rate limits/fees that 5m low-cap trading will stress. |
| **E11 — Client UI** | Laravel 12 + Livewire 3 + Tailwind (workmyagent pattern) — already decided | Control plane only; **never run ML in PHP.** |

**Stack one-liner:** *DuckDB/Polars (+ tsfresh offline) for data/features; CatBoost→LightGBM + SHAP + imodels for modeling; triple-barrier/meta-labeling + timeseriescv-CPCV + pypbo (PBO/DSR) for honesty; Optuna (+ gated, strongly-typed gplearn/DEAP) for search; hmmlearn for regime gating; NautilusTrader for cost-realistic backtests; MLflow + NannyML + Evidently for lineage and drift; CCXT for execution.*

---

## 5. What genuinely differentiates OUR situation

The algorithm is commodity. These four things are not, and they are where the realistic upside lives. [REASONED, grounded in verified context]

1. **A hand-labeled ground-truth dataset (15,262 trades; 948 good / 710 medium / 2,503 bad, plus 10,356 to auto-label).** Genuinely valuable and rare. The verifier independently noted that **using human-labeled trade quality as the supervised target — rather than raw price direction — has sparse open-source prior art and meaningfully reduces label noise.** Most retail crypto ML regresses on raw forward returns and drowns in noise; we have a human's accumulated judgment encoded as labels. **This is the single biggest asset.** Caveat (E02 exists for it): the labels are somewhat inconsistent (a "+0.5% good" trade), and they were assigned in *specific regimes* — so they are not stationary ground truth. Calibrate a coded triple-barrier/MFE definition against them, keep the disagreement list as a permanent artifact, and review a sample before training.

2. **An existing, working-in-practice rule system (8 base rules, 776 subrules) firing on real trades.** This is the precondition meta-labeling *assumes*, and the verifier confirmed it is exactly the case where meta-labeling is valid (rule-based primary, not ML-on-ML). We're solving the narrower, more tractable problem of *filtering an existing signal* — not cold-starting one from raw OHLCV. The baselines (776 subrules + "buy everything") are concrete and already implemented, so we measure against a real bar, not a strawman. That raises our prior versus a cold-start bot.

3. **The volatility-gating insight ("dead coins keep buying and bleed").** The differentiator I'd bet on most. It is an economically-grounded, low-DOF regime conditioner that is hard to overfit — and the verified HMM/regime literature shows this *family* of intervention delivers real drawdown reduction (QuantStart: 35.7%→24%). The owner found it by hand over real trades — exactly the kind of domain insight ML usually *can't* discover but *can* be handed. Encoding it as E05/E07 is the highest expected-value, lowest-risk work in the roadmap.

4. **Solving the *narrow niche where the public evidence is thinnest*, with the discipline the niche demands.** All the strong verified evidence (Numerai, Qlib/RD-Agent, GP alpha-mining) is on *liquid equities at daily timeframes*. 5m low-cap meme/AI crypto is where edges decay fastest and slippage bites hardest — there is no roadmap to copy. That is a risk, but our combination of (curated labels + live primary signal + vol gate + a ruthlessly honest harness) is a *coherent, defensible* way to attack exactly the niche others can't cite numbers for.

### Realistic prior on success

- **P(the harness pays for itself by telling us the truth cheaply): high.** Even total model failure is a valuable, cheap answer. Build E01/E02/E04 regardless.
- **P(volatility-gate (E05) produces a robust, post-cost improvement): moderate-to-good.** Lowest-overfit, economically-motivated, owner-validated, and backed by the verified regime-filter literature.
- **P(meta-label entry filter (E03) beats the 776 subrules out-of-sample, after costs, durably): low-to-moderate.** The genuine open question. Plausible given the proprietary indicator + curated labels; the base rate for "ML beats a tuned baseline net of costs" is unforgiving, and slippage at 5m low-cap is the specific threat.
- **P(autonomous strategy discovery (E06) finds durable new edge): low, and dangerous if not gated.** Verified: vanilla GP is the weak baseline; only enhanced GP on liquid equities shows positive results, none live-audited.
- **P("we have a unique algorithm nobody else has"): ~zero.** We don't, and we shouldn't plan as if we do.

**The honest framing for the owner:** *You don't have a secret algorithm — nobody building this honestly does, and the verification pass confirmed the method is textbook. What you have is a curated label set (rarer than the algorithm), a live rule-based primary signal that is exactly the precondition meta-labeling needs, and one strong hand-found regime insight that the literature says is the durable kind. The plan correctly spends its effort on validation discipline. If there's money here, the harness will find it without fooling you; if there isn't, the harness saves you from bleeding while believing there is. The biggest real risk isn't the ML — it's that 5m low-cap slippage eats the edge before the model ever matters, so prove the cost model early.*

---

## 6. Sources

Real, citable references behind the named methods, the verified evidence, and the recommended tooling. Community/marketing return-figures are deliberately excluded from the "evidence" tier and flagged inline above as [COMMUNITY / UNVERIFIED].

**Methodology (the spine):**
- López de Prado, M. (2018). *Advances in Financial Machine Learning*. Wiley — meta-labeling (Ch. 3), triple-barrier, purged CV (Ch. 7). https://www.wiley.com/en-us/Advances+in+Financial+Machine+Learning-p-9781119482086
- Meta-Labeling — Wikipedia. https://en.wikipedia.org/wiki/Meta-Labeling
- Joubert, J. (2022). *Meta-Labeling: Theory and Framework*. JFDS. https://jfds.pm-research.com/content/early/2022/06/23/jfds.2022.1.098
- Bailey, Borwein, López de Prado, Zhu (2014/2016). *The Probability of Backtest Overfitting*. J. Computational Finance. https://www.davidhbailey.com/dhbpapers/backtest-prob.pdf
- Bailey, López de Prado (2014). *The Deflated Sharpe Ratio*. J. Portfolio Management. https://www.davidhbailey.com/dhbpapers/deflated-sharpe.pdf
- López de Prado (2018). *Combinatorial Purged Cross-Validation*. SSRN 4778909. https://papers.ssrn.com/sol3/papers.cfm?abstract_id=4778909
- López de Prado. *What to Look for in a Backtest* (MinBTL). SSRN 2308682. https://papers.ssrn.com/sol3/papers.cfm?abstract_id=2308682
- Arian, Norouzi, Seco (2024). *Backtest Overfitting in the ML Era* (CPCV vs WF). Knowledge-Based Systems. https://www.sciencedirect.com/science/article/abs/pii/S0950705124011110
- Lundberg, Lee (2017). *A Unified Approach to Interpreting Model Predictions* (SHAP). NeurIPS. https://arxiv.org/abs/1705.07874
- Allen, Karjalainen (1999). *Using genetic algorithms to find technical trading rules*. J. Financial Economics. https://doi.org/10.1016/S0304-405X(98)00052-X

**Verified evidence on achievable edge:**
- Numerai — JPMorgan \$500M capacity. https://blog.numer.ai/jpmorgan-secures-500m-capacity/
- RD-Agent (NeurIPS 2025) analysis. https://saulius.io/blog/automated-quant-research-ai-agents-rd-agent · Qlib benchmarks. https://github.com/microsoft/qlib/blob/main/examples/benchmarks/README.md
- ML crypto backtesting study (peer-reviewed, RF/GBM Sharpe 1.35–1.40 hourly). https://pmc.ncbi.nlm.nih.gov/articles/PMC12571449/
- Technical Analysis meets ML — Bitcoin (arXiv 2511.00665). https://arxiv.org/html/2511.00665v1
- QuantStart — HMM regime detection in QSTrader (drawdown 35.7%→24%). https://www.quantstart.com/articles/market-regime-detection-using-hidden-markov-models-in-qstrader/
- Walk-forward window sensitivity on Bitcoin intraday (arXiv 2602.10785). https://arxiv.org/abs/2602.10785
- Vectorial GP for trading — honest OOS result (arXiv 2504.05418). https://arxiv.org/html/2504.05418v1
- Warm-Start GP alpha mining (arXiv 2412.00896). https://arxiv.org/abs/2412.00896
- SEC charges fake crypto trading platforms (2025). https://www.sec.gov/newsroom/press-releases/2025-144-sec-charges-three-purported-crypto-asset-trading-platforms-four-investment-clubs-scheme-targeted
- Are AI crypto bots profitable? (survivorship-bias analysis). https://www.altrady.com/blog/crypto-bots/are-ai-crypto-trading-bots-profitable-2026

**Tooling (real project URLs):**
- DuckDB — https://duckdb.org · Polars — https://github.com/pola-rs/polars · DuckDB vs Polars benchmark — https://www.codecentric.de/en/knowledge-hub/blog/duckdb-vs-polars-performance-and-memory-with-massive-parquet-data
- TA-Lib — https://github.com/TA-Lib/ta-lib-python · pandas-ta-classic — https://github.com/xgboosted/pandas-ta-classic · tsfresh — https://github.com/blue-yonder/tsfresh · tsfel — https://github.com/fraunhoferportugal/tsfel
- LightGBM — https://github.com/microsoft/LightGBM · CatBoost — https://github.com/catboost/catboost · XGBoost — https://github.com/dmlc/xgboost · SHAP — https://github.com/shap/shap · imodels — https://github.com/csinva/imodels
- Optuna — https://github.com/optuna/optuna · gplearn — https://github.com/trevorstephens/gplearn · DEAP — https://github.com/DEAP/deap
- timeseriescv — https://pypi.org/project/timeseriescv/ · pypbo — https://github.com/esvhd/pypbo · skfolio — https://arxiv.org/pdf/2507.04176 · mlfinlab (commercial) — https://hudsonthames.org/mlfinlab/
- vectorbt — https://github.com/polakowo/vectorbt · NautilusTrader — https://github.com/nautechsystems/nautilus_trader
- hmmlearn — https://hmmlearn.readthedocs.io/ · ruptures — https://centre-borelli.github.io/ruptures-docs/
- MLflow — https://mlflow.org/ · NannyML — https://nannyml.readthedocs.io/ · Evidently — https://www.evidentlyai.com/ · river — https://riverml.xyz/
- CCXT — https://docs.ccxt.com/ · FreqTrade/FreqAI (reference architecture, GPL-3.0) — https://www.freqtrade.io/en/stable/freqai/

---

## Open verification debts (must close before trading real money)

1. **Library maintenance/licensing at install time** — confirm `timeseriescv` (last release 2018, math correct but unmaintained) and `pypbo` (low maintenance since ~2020) run on Python 3.11+; budget for **mlfinlab £100/mo** *or* accept assembling the open-source CPCV/PBO/DSR stack manually; verify the original `pandas-ta` is avoided in favor of `pandas-ta-classic`. [UNVERIFIED — maintenance]
2. **CCXT's current MEXC coverage** for the specific order types, thin-orderbook edge cases, and partial-fill behavior at 5m low-cap. [UNVERIFIED — coverage]
3. **The actual achievable post-cost edge on DOGEAI 5m** — the empirical question E03/E04 exist to answer. All §5 priors are priors, not results. [REASONED]
4. **Whether MEXC fees + slippage on these low-cap pairs leave room for a 5-minute edge at all** — the verified thesis-killer. Model with real exchange data in E04/E10 *before* heavy model tuning. [VERIFIED concern, UNVERIFIED magnitude]
