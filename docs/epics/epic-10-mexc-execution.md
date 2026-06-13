# EPIC 10: MEXC execution rewrite (later)

**Phase:** 4 — Execution
**Status:** Planned (later)
**Depends on:** E03 (signals), E09 (exit/sizing)

## Goal

Turn approved signals into real orders on MEXC, rebuilt cleanly — the legacy `mexc.php` / `bot_process_*` as reference only.

## Rationale

The legacy execution code is reportedly the more readable, reusable part, but it's still legacy. Execution touches real money, so it needs the strictest engineering: explicit states, idempotency, hardened transport.

## Scope (high level — to detail when we reach this phase)

1. **Trade lifecycle as a state machine.** Explicit states (signal → ordered → filled → holding → exiting → closed), no implicit flags.
2. **Idempotent queued jobs.** Every action retry-safe; no double-buys/sells on retries.
3. **One hardened `MexcClient`.** `SSL_VERIFYPEER=true`, keys via Laravel encrypted storage, centralized auth/signing.
4. **Reconciliation.** Sync local order state with the exchange; detect and resolve drift.
5. **Kill switch & limits.** Hard caps and a manual stop.

## Acceptance criteria (to refine)

- [ ] Trade lifecycle is an explicit, audited state machine.
- [ ] Order jobs are idempotent and retry-safe.
- [ ] A single hardened MexcClient with secure key handling and `SSL_VERIFYPEER=true`.
- [ ] Reconciliation and a kill switch exist.

## Out of scope

- Strategy/filter logic (E03/E06), exit policy (E09) — this epic only executes their decisions.

## Recommended tooling & prior art (from research)

> Provenance: verified research bundle + adversarial verification pass (supersedes the earlier empty-payload note).

**Exchange client** [VERIFIED — with a coverage caveat]
- **CCXT** (https://docs.ccxt.com/) — the industry-standard unified exchange layer (~34k stars, 105+ exchanges, REST + WebSocket) underlying FreqTrade, Jesse, and OctoBot. Prefer wrapping CCXT inside the Laravel state machine over hand-rolling MEXC signing.
- **Verify CCXT's MEXC coverage handles your specifics before committing:** CCXT **abstracts away exchange-specific rate limits, order types, and fee structures**, and the verified research warns that **5m low-cap/meme trading hits edge cases CCXT does not handle — thin orderbooks, partial fills, mid-trade delisting.** These must be built on top. If coverage is insufficient, fall back to a thin hardened native client.

**Hardening (already correct in the epic)** [ESTABLISHED]
- `SSL_VERIFYPEER=true`, keys via Laravel encrypted storage, centralized auth/signing. Trade lifecycle as an explicit, audited **state machine** (signal → ordered → filled → holding → exiting → closed); **idempotent, retry-safe queued jobs** to prevent double-buys/sells; reconciliation against the exchange; kill switch + hard caps.

**The thesis-critical technique note** [VERIFIED concern]
- Model **real MEXC fees AND slippage** on these low-cap 5m pairs and feed them into E04's cost model **early**. The verified research is blunt: at 5m on $50K–$500K-daily-volume coins, a **0.4–1% round-trip cost can exceed the entire modeled alpha**, and a signal firing 50–100×/day on meaningful notional moves the price against itself. **If MEXC's fees/slippage don't leave room for the edge, that finding should kill or reshape the thesis before heavy execution work.** This is the single most likely way the whole project fails quietly.

**Reference frameworks (architecture, not drop-in)** [ESTABLISHED]
- **FreqTrade + FreqAI** (https://www.freqtrade.io/en/stable/freqai/) is **verified** as a real, actively-maintained (monthly releases through 2026) framework whose ML module mirrors our architecture — useful as a **reference for the execution/order-management and exchange-abstraction patterns**, and for its `lookahead-analysis` tooling. **But it is GPL-3.0** (copyleft — incompatible with a closed runtime), Python-only, and tightly coupled to its own exchange/order infra. Use as a reference architecture, not as drop-in code. **NautilusTrader** (https://github.com/nautechsystems/nautilus_trader) is the production-grade alternative whose backtest and live code paths are identical.

**References**
- CCXT — https://docs.ccxt.com/ · FreqTrade (reference, GPL-3.0) — https://www.freqtrade.io/en/stable/freqai/ · NautilusTrader — https://github.com/nautechsystems/nautilus_trader · MEXC API docs (confirm current version at build time).

## Notes

- Reference the legacy MEXC integration for endpoint/signing details; do not port the code wholesale.
