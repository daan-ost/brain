# Epics — nobrainersbot trading system

Build specs for the rewrite. See `../roadmap.md` for the north star, principles, and phasing.
New here? Read [../functional-overview.md](../functional-overview.md) (what it does) and [../technical-overview.md](../technical-overview.md) (how it's built) first.
Hard rule: `bot_signals` is a **read-only source** — never written to.

## Active build epics (now)

| Epic | Title | Refines |
|---|---|---|
| [A](epic-A-good-trade-periods.md) | Good trade-period discovery + per-datetime outcome store + explorer (screens + graph) | E02 + feature-store |
| [B](epic-B-lookback-store.md) | Per-rule lookback feature store (fast-queryable) | E01 |
| [S](epic-S-sell-precision.md) | Sell-engine precision (87% → higher) — **parked**, dependency of A | E09 (faithful, not optimized) |
| [R](epic-R-rule-tuning-discovery.md) | Rule tuning, scoring & automated discovery (goal: per coin/rule #bad ≤ #good) | E06 + E03/E05 |
| [L](epic-L-promising-labeler.md) | Promising labeler — per-moment buy-quality labeling + classification tuning (imports legacy yes/no labels) | A + E02 |

A and B are the concrete, buildable versions of the foundation. R is the path to ~legacy level (interpretable rules, before ML). S is a parked dependency. The numbered E01–E11 below remain the grand plan.

## Grand plan

| Epic | Phase | Title | Build-first? |
|---|---|---|---|
| [E01](epic-01-data-foundation.md) | 0 Foundation | Data foundation & leak-free feature store | ✅ start here |
| [E02](epic-02-labeling.md) | 0 Foundation | Labeling & entry-quality definition | ✅ |
| [E03](epic-03-entry-filter-poc.md) | 1 Prove edge | Entry-filter model PoC | ✅ |
| [E04](epic-04-validation-harness.md) | 1 Prove edge | Validation & backtest harness | ✅ |
| [E05](epic-05-coin-volatility-gating.md) | 2 Robustness | Coin volatility gating | |
| [E06](epic-06-autonomous-rule-discovery.md) | 2 Robustness | Autonomous rule-discovery loop | |
| [E07](epic-07-volatile-coin-discovery.md) | 3 Autonomy | New volatile-coin discovery | |
| [E08](epic-08-daily-autonomous-orchestration.md) | 3 Autonomy | Daily autonomous orchestration (capstone) | |
| [E09](epic-09-exit-policy-sizing.md) | 4 Execution | Exit policy & position sizing | later |
| [E10](epic-10-mexc-execution.md) | 4 Execution | MEXC execution rewrite | later |
| [E11](epic-11-client-ui-multitenant.md) | 4 Execution | Client UI & multi-tenant SaaS (workmyagent pattern) | later |

**Current status:** planning complete. Awaiting go to build Phase 0 (E01 + E02), starting from the DOGEAI 5m / 25 Feb 2025 slice.
