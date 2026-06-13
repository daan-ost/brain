# Epics — nobrainersbot trading system

Build specs for the rewrite. See `../roadmap.md` for the north star, principles, and phasing.
Hard rule: `bot_signals` is a **read-only source** — never written to.

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
