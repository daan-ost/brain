# EPIC 04: Validation & backtest harness

**Phase:** 1 — Prove edge
**Status:** Planned
**Depends on:** E01 (data); used by E03, E05, E06, E07, E08

## Goal

Build the honesty engine every other epic reports through: leak-free, walk-forward, baseline-anchored, profit-based evaluation. If a result doesn't survive this harness, it doesn't count.

## Rationale

Backtest overfitting is the central enemy of trading ML. A model that looks great is usually a model that cheated (leakage) or got lucky on one split. The harness exists to make that impossible to hide.

## Scope

1. **Walk-forward splits.** Train on past, test on later time, roll forward. No random row shuffling across time.
2. **Purge & embargo.** Around each split boundary, drop overlapping/adjacent candles so a test trade can't share information with a neighboring train trade.
3. **Unseen holdouts.** Evaluate on coins and periods the model never trained on, not just held-out rows from the same day.
4. **Leakage guard (shared with E01).** Automated check that no feature used data at/after entry time; fails the run if violated.
5. **Baselines.** (a) the current 776 subrules; (b) "buy everything the strategy fires." Every model is reported against both.
6. **Profit-based fitness function.** Primary metric = net P&L per trade after fees and slippage, weighted by outcome — not accuracy or raw precision/recall. Also report precision-recall AUC and the good-recall / bad-rejection trade-off curve.
7. **Overfit signals.** Report train-vs-test degradation and a complexity penalty hook (used by E06's search to prefer simple robust rules).
8. **Reusable API.** A single entry point (`evaluate(candidate, slice)`) that E03/E05/E06/E07/E08 all call, returning a standard scorecard.

## Acceptance criteria

- [ ] `evaluate()` runs walk-forward with purge/embargo and returns a standard scorecard (P&L, PR-AUC, good-recall/bad-rejection, train-vs-test gap).
- [ ] The two baselines are computed automatically for every evaluation.
- [ ] The leakage guard fails the run on any look-ahead feature.
- [ ] Fees and slippage are configurable and applied to the P&L metric.
- [ ] The harness runs on the DOGEAI slice and is coin/period-parameterized for scaling.

## Out of scope

- Live/paper trading execution (E10). This is offline evaluation only.

## Recommended tooling & prior art (from research)

> Provenance: verified research bundle + adversarial verification pass (supersedes the earlier empty-payload note). This epic is the project's spine — the reason any later number can be believed.

**The right names — all VERIFIED peer-reviewed**
- Walk-forward with purge/embargo = **Purged K-Fold**, and stronger, **Combinatorial Purged Cross-Validation (CPCV)** (*AFML* Ch. 7; SSRN 4778909). CPCV generates multiple non-redundant backtest paths (e.g. 6 groups, 2 test → C(6,4)=15 splits → 5 paths) instead of one cherry-pickable walk-forward path. **Verified finding (Arian, Norouzi, Seco, *Knowledge-Based Systems* 2024): CPCV achieves the lowest PBO and best DSR of {walk-forward, purged k-fold, CPCV}** for ML model selection. Use **walk-forward for live-deployment simulation; CPCV for choosing among candidates.**
- Report the profit metric alongside the **Deflated Sharpe Ratio (DSR)** (Bailey & López de Prado 2014) and **Probability of Backtest Overfitting (PBO)** (Bailey, Borwein, López de Prado, Zhu 2014/2016) — both **verified peer-reviewed**. They discount a metric for the number of configurations tried, essential once E06 searches.
- **MinBTL (verified):** after **~7 independent trials** you can expect a 2-year Sharpe>1 at zero true alpha. **Budget the trial count before the search begins** (SSRN 2308682).

**Tooling — open-source CPCV/PBO stack (mlfinlab is now paid)** [VERIFIED maturity]
- **timeseriescv** (https://pypi.org/project/timeseriescv/) — sklearn-compatible `PurgedWalkForwardCV` and `CombPurgedKFoldCV` (CPCV with purge+embargo), **free**. Last release 2018, minimal/unmaintained but the math is correct; test on Python 3.11+.
- **skfolio** (https://arxiv.org/pdf/2507.04176) — actively-developed, implements CPCV; free alternative for the CV layer.
- **pypbo** (https://github.com/esvhd/pypbo) — PBO (CSCV) + DSR + probabilistic Sharpe. Low maintenance since ~2020 but self-contained; verify on Python 3.11+.
- **mlfinlab** (https://hudsonthames.org/mlfinlab/) — gold-standard full toolkit, but **commercial (~£100/month per user)**. Use only if budgeted; otherwise assemble the three free libs above.

**Backtest engine — realism matters for low-cap** [VERIFIED caveat]
- **NautilusTrader** (https://github.com/nautechsystems/nautilus_trader) — Rust-core, event-driven, tick/L2 capable; same code backtest→live. **Use this for the cost-realistic fill gate.** On thin low-cap orderbooks where market impact is significant, this is worth the setup overhead.
- **vectorbt** (https://github.com/polakowo/vectorbt, OSS v1.0 Apr 2026) — fully vectorized; great for fast parameter sweeps. **But it is NOT event-driven** — it cannot model partial fills or market impact, so its low-cap results are **optimistically biased**. Use it for sweeps, never as the final realism check. (vectorbtpro is invite-only commercial.)

**Mandatory, not optional** [VERIFIED concern]
- Fitness = **net P&L per trade after fees AND slippage** with a real MEXC cost model. At 5m on $50K–$500K-volume coins, a 0.4–1% round-trip can exceed the entire modeled edge — the verified thesis-killer. A gross-profitable filter that loses net is the most common way these projects die.
- Compute the two baselines (776 subrules + buy-everything) for **every** evaluation automatically.
- **CPCV fold sizing:** target ~100–300 trades per fold; with a small labeled core, fold sizing must be deliberate (too few folds = no path diversity; too many = folds too thin).

**References**
- *AFML* Ch. 7 — https://www.wiley.com/en-us/Advances+in+Financial+Machine+Learning-p-9781119482086 · PBO — https://www.davidhbailey.com/dhbpapers/backtest-prob.pdf · Deflated Sharpe — https://www.davidhbailey.com/dhbpapers/deflated-sharpe.pdf · CPCV vs WF (2024) — https://www.sciencedirect.com/science/article/abs/pii/S0950705124011110 · MinBTL — https://papers.ssrn.com/sol3/papers.cfm?abstract_id=2308682

## Notes

- The legacy dead `if(1==2)` 8-line profit scoring in `showEffect.php:577` is the spiritual ancestor of the fitness function — review it for ideas, don't reuse the code.
