# EPIC 11: Client UI & multi-tenant SaaS

**Phase:** 4 — Execution (product layer; design influences earlier phases)
**Status:** Planned
**Depends on:** E10 (execution) for live trading; can start the shell earlier

## Goal

The customer-facing product: a multi-tenant web app where a customer connects an exchange, sets a trade budget, and the system trades automatically with the shared rules. The whole UX is "connect + set amount = done."

## Rationale

Today it's Daan's personal tool, but the direction is SaaS — many customers, same shared rules, fully automatic. Building on the basewebsite stack (like workmyagent) gives auth, organizations, payments, and the sidebar for free, so we don't rebuild plumbing.

## Confirmed home & structure (2026-06-13)

We build **in `sites/brain`** (the basewebsite child / nobrainersbot). Multi-tenant scaffolding stays intact but we run **single-user for now**: one organization + one admin user, who is both the "client" (sees own trades) and the engine operator. Everything trade-related is scoped by `organization_id` from day 1; **rules are global/shared**, money and execution are per-org.

**Two areas**, both inside the existing `['auth','two_factor']` group, copying the `demo-items` Livewire pattern and the `app.blade.php` + `navigation.blade.php` sidebar:

- **`/client/...`** — trader view: `/client/dashboard` (positions + P&L), exchange connection, budget, pause/resume. The "connect + set amount = done" surface; a customer sees only their own.
- **`/engine/...`** — rule-analysis cockpit, **admin-only** (`is_admin`): `/engine/dashboard` (rules 11/12/20/21/23 overview, per-rule good/bad breakdown, model/PoC results, coin volatility status, discovery runs). Named `/engine`, not `manageengine`.

**Laravel vs Python boundary (do not do ML in PHP):**
- `brain/www` (Laravel) = control plane: both dashboards, orchestration, scheduling, the results DB, and a **read-only second DB connection to `bot_signals`**.
- `brain/engine/` (Python) = compute: features, training, backtests, discovery loop. Invoked by Laravel via queued jobs / artisan→Python; writes results back into brain's own tables for the `/engine` dashboard to render.
- The `bot` project remains the read-only legacy reference and the `bot_signals` source.

**First screen:** `/engine/dashboard` showing rule 20 on DOGEAI (31 good / 128 bad + base hypothesis) read from `bot_signals` — useful with no ML yet — then surface the rule-20 Python PoC result beside the rule's own result.

## Scope

1. **Home: `brain` (basewebsite child).** Confirmed. Auth, organizations, payments, sidebar already present. Python engine in `brain/engine/`, plugged in via queue/CLI.
2. **Convention = workmyagent.** Laravel 12 + Livewire 3 + Tailwind. Authenticated route group (`['auth','two_factor']`), Livewire page components (`Index`/`Form`/`Show`), ULID model binding, sidebar via `layouts/app.blade.php` + `layouts/navigation.blade.php`. Areas: `/client/...` and `/engine/...`.
3. **Customer screens (minimal by design).**
   - **Onboarding:** connect an exchange (API key + secret, stored encrypted) and set a trade budget. That's the whole setup.
   - **Dashboard:** current positions, P&L, active/paused state.
   - **History:** past trades and performance.
   - **Settings:** budget, exchange connection, pause/resume.
4. **Multi-tenancy.** Per-organization: exchange connection, budget, positions/orders/P&L — scoped by `organization_id`. The **rules are shared/global**; only money and execution are per-customer.
5. **Admin (Daan) screens.** Rule/discovery oversight, coin active/inactive state, system health — can reuse the engine's reporting from E08.
6. **Internal engine screens (optional, for Daan).** Research/analysis views over the slice and model results — lower priority, can stay in the Python/notebook layer.

## Acceptance criteria

- [ ] A customer can sign up, connect an exchange (encrypted keys), set a budget, and reach an active state in a few clicks.
- [ ] All customer pages follow the workmyagent pattern (sidebar, Livewire, `/client/...` routes, ULID binding).
- [ ] Positions, orders, and P&L are scoped per organization; rules are shared/global.
- [ ] Pause/resume and budget changes work without touching engine internals.

## Out of scope

- The trading engine itself (E01–E08) and execution (E10) — this epic is the product surface over them.

## Recommended tooling & prior art (from research)

> Provenance: fresh research/verification payloads were empty; items tagged [ESTABLISHED]/[REASONED]. This epic is the product surface, not where edge lives — keep it thin.

**Stack (already decided, confirmed sound)** [ESTABLISHED]
- **Laravel 12 + Livewire 3 + Tailwind** following the **workmyagent** pattern: `['auth','two_factor']` group, Livewire `Index`/`Form`/`Show` page components, ULID model binding, sidebar via `layouts/app.blade.php` + `layouts/navigation.blade.php`, `/client/...` and `/engine/...` areas.

**The hard architectural rule** [ESTABLISHED]
- **Never run ML in PHP.** `brain/www` (Laravel) = control plane (dashboards, orchestration, scheduling, results DB, read-only second connection to `bot_signals`). `brain/engine/` (Python) = compute (features, training, backtests, discovery), invoked via queue/CLI, writing results back into brain's tables for `/engine` to render. This separation is correct and load-bearing — do not blur it.

**Multi-tenancy** [REASONED]
- Scope exchange connection, budget, positions/orders/P&L by `organization_id` from day 1 even while single-user; keep **rules global/shared**, money + execution per-org. Encrypted API keys (Laravel encrypted storage). This matches the cross-project basewebsite conventions and the SaaS direction.

**Honest note** [REASONED]
- The "connect + set amount = done" UX is fine as a product north-star, but it presumes a *proven* edge from E03–E08. Don't ship a customer-facing trading product until the harness (E04) has demonstrated post-cost edge; otherwise the simplest UX in the world is selling losses.

**References**
- Internal: workmyagent project conventions · basewebsite API/UI conventions (`basewebsite/docs/api/conventions.md`).

## Open questions (for Daan)

- Confirm: is the `nobrainersbot` / `brain` basewebsite app the intended customer shell for this engine?
- Exchanges to support at launch (MEXC first, others later)?
