# Roadmap — nobrainersbot trading system

**Date:** 2026-06-13
**Status:** Planning. Supersedes nothing; builds on `docs/analysis/legacy-analyse-en-rewrite-plan.md`.
**Hard constraint:** the `bot_signals` database is a **read-only source**. We never write to it. All new work lives in a separate database/slice and in the new codebase.

---

## North star (the direction, not day 1)

A **self-running trading system** that needs almost no human time:

1. **Pulls** the latest indicator data daily (the TradingView webhook feed already lands in `wp_trading_indicator`).
2. **Searches** for new entry rules and filters that keep good trades and drop bad ones.
3. **Evaluates** every candidate honestly (leak-free, profit-based, out-of-sample) and **rejects** what doesn't generalize.
4. **Decides which coins to trade** — activating new volatile coins, deactivating coins that have gone quiet.
5. **Extends itself** — over time, proposes and ships its own code/feature/rule changes, human-gated at first, more autonomous as trust grows.

Day 1 is a human-in-the-loop proof of edge. Every epic is a step toward removing the human from the loop. We record this so we never lose the direction.

---

## Product vision (where this is heading as a product)

Today it's Daan's personal tool. The direction is a **multi-tenant SaaS**: many customers, the same shared learned rules, fully automatic. The entire customer experience is:

> **Connect an exchange, set how much money you want to trade. That's it.** The system trades automatically with the shared rules; the customer never tunes anything.

This shapes the architecture from the start (even while day-1 is single-user):

- **Customer-facing app = basewebsite stack.** Built like the **workmyagent** project: Laravel 12 + Livewire 3 + Tailwind, authenticated route group (`['auth','two_factor']`), Livewire page components (`Index`/`Form`/`Show`), ULID model binding, sidebar via `layouts/app.blade.php` + `layouts/navigation.blade.php`, routes under a `/client/...` area sectioned by domain.
- **Likely home: the `nobrainersbot` (basewebsite child, the `brain` project) app** is the customer shell — it already provides auth, organizations (multi-tenant), payments, and the sidebar. The `bot` engine (Python ML + rules + execution) plugs in behind it.
- **Multi-tenancy is cross-cutting.** Per-customer: exchange connection (API keys, encrypted), trade budget, and their own positions/orders/P&L — all scoped by organization. The *rules* are shared/global; the *money and execution* are per-customer.
- Keep the engine (rules, discovery, signals) tenant-agnostic; only execution and reporting are per-customer.

## What makes this succeed (principles that outrank any single feature)

The indicator formulas (skewness, volatility, reversal count — the legacy "Test type" library) are **ingredients, ~20% of the problem**. The other 80%:

1. **No look-ahead leakage.** A feature may use only data that existed *before* the entry timestamp. The legacy harness has traps ("Future price", "Profit change compared to current") — those are exit/label material, never entry features. Leakage gives a beautiful backtest and a losing bot. A leakage guard is non-negotiable.
2. **Labels are the foundation.** The model can't beat its labels. Label **entry quality** by the *available upside* after entry (the price path / `highest_profit_loss`), not the realized `profit_loss` (which is contaminated by exit timing). Formalize the "good moment" definition as code, calibrate it against the hand-made labels.
3. **Validation is the real battle.** Walk-forward in time, purge/embargo around splits, test on unseen coins *and* periods, always against an honest baseline (the current 776 subrules + "buy everything the strategy fires"). If we don't beat the baseline out-of-sample, we have nothing — and we must be able to see that.
4. **Optimize money, not accuracy.** The fitness function is net profit per trade after fees/slippage, weighted by outcome — not raw precision/recall. Dropping one +68% trade to avoid five −1% trades is a bad deal. (The legacy dead `if(1==2)` 8-line scoring was reaching for exactly this.)
5. **Markets drift — especially low-cap meme/AI coins.** Continuous retraining plus drift monitoring that alarms when live diverges from backtest. A static filter ages out.
6. **The search must be able to say "no."** The legacy discovery (`removeExtremes` + envelope) always produces a rule, so it always overfits. A real search proposes, tests leak-free out-of-sample, and rejects. A complexity penalty favors simple robust rules over fitted curves.
7. **Explainability turns the model into a rule discoverer.** SHAP per decision ("filtered because vzo low and volume-skew negative") yields the new, human-readable rules — automating the manual work you used to do by eye.
8. **Entry is one lever; exit and sizing matter as much.** Keep entry-filter, exit-policy, and position-sizing as separate, composable modules so we can improve each without untangling a monolith (the legacy's core failure).

---

## Domain facts the system is built on (verified)

- **Three data layers:** raw feed `wp_trading_indicator` (~101M rows, month-partitioned) → per-subrule match `wp_trading_simulation_trades_indicator` (~11M) → found trades with manual label `wp_trading_simulation` (15,262 rows).
- **Labels** (`wp_trading_simulation.result`, manual): 1=good (948, avg +5.97%), 2=medium (710), 3=bad (2,503, avg −0.53%), NULL=unlabeled (10,356). This curated ground truth is the project's most valuable asset.
- **Base indicators** (from TradingView via webhook, black-box, TV settings out of scope): `vzo`, `phobos`, `obv-x-value`, `mfi`, `volumeud`. We consume their values; we do **not** recompute them.
- **Derived features** (we compute these in Python over windows of the base values — the legacy "Test type" library): skewness, std deviation, volatility, range %, reversal count, consecutive increases/decreases, median, sideways, etc.
- **Strategy structure:** each `rule_number` (8 active: 10,11,12,18,20,21,22,23) is a "base hypothesis" — usually a volume check + one anchor indicator regime (e.g. phobos between −55 and −30). 776 active subrules refine these. The same rule fires on both good and bad moments → that's why a finer filter is needed.
- **PoC slice:** DOGEAI 5m (`trading_symbol_id=2525`), 2025-02-25 — 5 good vs 20 bad trades, ~4,656 indicator rows. Showcase day for "keep the good, drop the bad."
- **DB access (read-only):** `/Applications/MAMP/Library/bin/mysql80/bin/mysql -u root -proot -P 8889 -h 127.0.0.1 bot_signals`.

---

## Phased plan

| Phase | Goal | Epics |
|---|---|---|
| **0 — Foundation** | Clean, leak-free data + defensible labels | E01 Data foundation & feature store · E02 Labeling |
| **1 — Prove edge** | Show ML beats the 776 subrules, honestly | E03 Entry-filter PoC · E04 Validation harness |
| **2 — Robustness** | Don't trade dead coins; search that can say no | E05 Coin volatility gating · E06 Autonomous rule-discovery loop |
| **3 — Autonomy** | Remove the human from the loop | E07 New volatile-coin discovery · E08 Daily autonomous orchestration |
| **4 — Execution (later)** | Turn signals into trades | E09 Exit policy & sizing · E10 MEXC execution rewrite |

We stay in the loop between phases — read results, decide the next move. Each phase is independently valuable.

---

## Epic index

Detailed specs live in `docs/epics/`. One-paragraph scope per epic:

- **E01 — Data foundation & leak-free feature store.** Export a manageable slice (start: DOGEAI 5m, 25 Feb 2025) from the read-only source into a fast analytical store (DuckDB/Parquet). Build a feature pipeline that computes the derived features from the base indicator series with a strict point-in-time contract (features see only data before the entry timestamp). This is the substrate everything else stands on.

- **E02 — Labeling & entry-quality definition.** Formalize "good entry moment" as code based on the price path after entry (max drawdown, available upside within a window) — your "<1% drop then >5% upside within X min". Auto-label the 10,356 NULL trades, and calibrate the rule against your 1/2/3 hand-labels so the automatic definition matches your judgment. Separate entry quality from exit execution.

- **E03 — Entry-filter model PoC.** Train a gradient-boosting classifier (LightGBM/XGBoost) on the labeled trades using the features from E01, target good (1) vs bad (3). Prove on the DOGEAI 25 Feb showcase: at equal recall on good trades (≥90%, per the conservative choice), the model drops more bad trades than the current 776 subrules. Profit-weighted evaluation, SHAP explanations.

- **E04 — Validation & backtest harness.** The honesty engine: walk-forward time splits with purge/embargo, unseen-coin/period holdouts, the leakage guard, the two baselines (current subrules + buy-everything), and a profit-based fitness function (net P&L per trade after fees/slippage). Every later epic reports through this harness.

- **E05 — Coin volatility gating.** A coin-level eligibility layer, separate from per-trade filtering. Measure recent realized volatility per coin; when a coin goes quiet (e.g. <X% range over ~2 days), stop trading it — because dead coins still trigger buys and almost always bleed. Reactivate when volatility returns. Your "biggest lever" insight, made into a gate.

- **E06 — Autonomous rule-discovery loop.** A search (Bayesian optimization / genetic programming) that proposes new subrules *and* new strategies (per your choice), tests each leak-free out-of-sample through E04, and **rejects** what doesn't generalize, with a complexity penalty. SHAP turns winners into human-readable rules. Full audit trail. Human-approves first, auto-promotes later.

- **E07 — New volatile-coin discovery.** Scan the universe for coins entering a tradable volatility regime and propose them for activation — the intake side of E05's gate. Feeds the active-coin set the rest of the system trades.

- **E08 — Daily autonomous orchestration.** The capstone: scheduled routines that pull the latest indicators, run discovery (E06), re-evaluate active rules and coins (E04/E05/E07), and report. The end state extends to the system proposing its own code/feature changes — staged, human-gated first, more autonomous as trust grows. This is the north star made operational.

- **E09 — Exit policy & position sizing (later).** The other half of a trade. Model/optimize the sell moment (you noted it's generally easier than the buy) and position size, as separate modules. Includes the ≤1%-below-buy protective check ("Future price") cleanly on the exit side.

- **E10 — MEXC execution rewrite (later).** Turn approved signals into real orders: trade lifecycle as an explicit state machine, idempotent queued jobs, one hardened `MexcClient` (`SSL_VERIFYPEER=true`, keys via encrypted storage). The legacy `mexc.php` / `bot_process_*` as reference only.

---

## Tech direction

- **Python** for the ML/quant core (pandas/Polars + DuckDB, LightGBM/XGBoost, SHAP, Optuna for search). This is where features, models, validation, and the discovery loop live.
- **Laravel** for orchestration, scheduling, the API, label/rule management UI, and (later) execution. New tables/models are free; the old PHP is inspiration only.
- **Separation:** the read-only `bot_signals` source → an analytical slice/store → the Python research layer → a results/rules database the Laravel app manages. The source is never touched.
