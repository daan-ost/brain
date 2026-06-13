# EPIC 05: Coin volatility gating

**Phase:** 2 — Robustness
**Status:** Planned
**Depends on:** E01 (price/indicator series), E04 (to prove the gate helps net P&L)

## Goal

Add a coin-level eligibility layer, separate from per-trade filtering: stop trading a coin once it goes quiet, because dead coins keep triggering buys and almost always bleed. Reactivate when volatility returns.

## Rationale

Daan's biggest-lever insight: "if a coin stops moving, it still buys, but price rises too little — almost always a loss." This is not a per-trade decision; it's about whether the coin is worth trading at all right now. He noted he could find too little on this. A simple, robust rule — "after ~2 days of dead volatility, stop trading this coin" — likely removes a whole class of losers cheaply.

## Scope

1. **Volatility measure per coin.** Compute recent realized volatility / true range over a rolling window (e.g. last 2 days) from the price series. Pick a measure that's robust for low-cap meme/AI coins.
2. **Quiet detection & deactivation.** When a coin's recent volatility falls below a threshold (range/std over the window < X), mark it inactive for trading. Defaults configurable; calibrate against history (which inactive periods would have avoided losing trades).
3. **Reactivation.** When volatility returns above a threshold, re-enable the coin (or hand it to E07's intake).
4. **Backtest the gate.** Through E04: show net P&L of trades with the gate vs without — confirm it removes more loss than opportunity.
5. **State & audit.** Track per-coin active/inactive state with timestamps and reasons, in the new results DB (never in `bot_signals`).

## Acceptance criteria

- [ ] A per-coin rolling volatility metric is computed from the price series.
- [ ] A coin is auto-deactivated after a configurable quiet window (default ~2 days) and reactivated when volatility returns.
- [ ] Backtest shows the gate improves net P&L on historical data (removes more loss than upside).
- [ ] Per-coin gating state is stored with timestamps and reasons, outside the read-only source.

## Out of scope

- Discovering *new* coins to add (E07) — this epic only gates coins already in the set.

## Recommended tooling & prior art (from research)

> Provenance: verified research bundle + adversarial verification pass (supersedes the earlier empty-payload note). This is, on priors, the highest expected-value, lowest-overfit epic — the regime literature backs that.

**The right name** [VERIFIED]
- This is **regime filtering / volatility-regime conditioning / tradability gating** — trade only when the instrument is in a tradable regime. **Verified evidence it works:** QuantStart's reproducible 2-state GaussianHMM filter cut max drawdown **35.7% → 24%** and raised Sharpe **0.37 → 0.48** on a trend-following equity strategy (https://www.quantstart.com/articles/market-regime-detection-using-hidden-markov-models-in-qstrader/). Low degrees of freedom + economically motivated = hard to overfit.

**Volatility measures (start simple)** [ESTABLISHED]
- Compute **realized volatility** (std of log returns), **ATR**, and **range %** over a rolling window in **Polars**; let calibration pick. For low-cap meme/AI coins, prefer a **robust** measure (median-based / winsorized) so one spike doesn't flip the gate. TradingView-native equivalents you can read straight from the webhook feed rather than recompute: **Choppiness Index** (>61.8 = dead/sideways, <38.2 = trending) and **ADX** (<20 = no trend).

**HMM regime detection** [VERIFIED — with a critical pitfall]
- **hmmlearn** (https://hmmlearn.readthedocs.io/) `GaussianHMM`, 2–4 states. For low-cap 5m, a **3-state** model (trending / mean-reverting / dead) is likely more appropriate than 2-state. Key hyperparameters: `n_components` 2–4, `covariance_type='full'`, `n_iter≥1000`.
- **CRITICAL LOOKAHEAD PITFALL:** in production use only the **forward-filtered `predict()`** state. **Never use the smoothed/Viterbi assignment** — it incorporates future data. This is the single most common error in HMM gating.
- **State-label instability:** after each retrain, state indices may swap meaning — **anchor states by their mean-volatility value, not by index.**
- **Dead-coin false negative:** low realized vol can precede a pump on low-caps. A pure vol gate deactivates exactly the coins about to move — **pair it with a breakout condition that re-activates a gated coin if vol suddenly spikes.**

**Change-point detection (offline calibration only)** [VERIFIED — offline]
- **ruptures** (https://centre-borelli.github.io/ruptures-docs/) PELT (O(n), cost fns `rbf` for distributional shifts, `l2` for mean/variance) to **calibrate historically how long dead periods last per coin** and set thresholds. **ruptures is offline-only** — it needs the full series and cannot run live gating. Mistaking it for an online method is a common error.

**Why this is the bet to make** [REASONED, backed by verified regime evidence]
- Validate the gate through E04 on **net P&L** (removes more loss than upside). Keep the parameter count tiny (window length + one threshold). The owner's 'biggest lever' intuition aligns with the durable, low-overfit regime-conditioning the literature rewards.

**References**
- QuantStart HMM in QSTrader — https://www.quantstart.com/articles/market-regime-detection-using-hidden-markov-models-in-qstrader/ · ruptures (Truong, Oudre, Vayatis 2020) — https://arxiv.org/pdf/1801.00826 · Ensemble-HMM voting framework — https://www.aimspress.com/article/id/69045d2fba35de34708adb5d · Choppiness vs ADX — https://choppinessindex.com/choppiness-index-vs-adx/

## Open questions (for Daan)

- Which volatility measure matches your intuition (range %, ATR, std of returns)?
- The quiet-window length (2 days?) and the threshold — any numbers from experience?
