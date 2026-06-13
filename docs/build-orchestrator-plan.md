# Build orchestrator — autonomously shipping the roadmap

**Date:** 2026-06-13
**Status:** Plan. Design only — nothing wired up yet.
**Companion to:** `docs/roadmap.md` (the *product* roadmap) and `docs/epics/`.

This document describes the **development orchestrator**: a Claude Code routine that
builds the epics in `docs/roadmap.md` mostly on its own — picks the next epic, plans it,
builds it in isolation, tests it, reviews it, and merges it — and pulls Daan in **only**
at decision gates. The goal is *less manual prompting*: Daan moves from operator to editor.

---

## Two orchestrators — do not confuse them

| | **E08 — runtime orchestrator** (product) | **Build orchestrator** (this doc) |
|---|---|---|
| Lives in | the shipped product | dev tooling / CI / cron |
| Loops over | market data, rules, coins | the epic backlog (E01→E11) |
| Decides | which coins/rules to trade | which epic to build next, and whether a change is safe to merge |
| Already specced? | yes (epic-08) | no — this is new |

E08 is a *feature we build*. The build orchestrator is the *machine that builds it*.

---

## The core loop

```
Backlog (docs/epics/, build-order) 
   → Orchestrator picks the next ready epic
   → Plan & scope (agent writes/refines the build plan)
   → Build in an isolated git worktree
   → Test & CI (layer-appropriate gate, see below)
   → Adversarial multi-agent review
   → Gate:
        green + low-risk  → auto-merge (once unlocked, see trust ladder)
        green + high-risk → open PR, notify Daan
        red               → retry (max 3), then escalate
        ambiguous         → escalate to Daan
   → on merge: record learnings (ce-compound), update backlog, loop
```

The machine runs the whole loop. Daan only appears at the amber points below.

---

## Two codebases, two gates

"Tested" means something different per layer. The orchestrator must apply the right gate.

### 1. Laravel shell — `www/`
Strong, trustworthy baseline already exists.
- **Unit/Feature:** `vendor/bin/pest` (196 test files inherited from basewebsite).
- **Format:** `vendor/bin/pint --test`.
- **E2E / visual:** Browserflow against `.env.review` (DB `brain_review`) — the project's
  standard visual-review pattern. The orchestrator seeds, drives the screen, screenshots.
- **Static analysis (to add):** larastan/phpstan is **not** installed yet — add it; it raises
  the floor of what "green" guarantees before any auto-merge is trusted here.

### 2. Python research core — new, greenfield
This is where the real danger lives. A passing unit test does **not** mean the change is
safe to trade. The domain gate is the honesty engine from the roadmap:
- **Unit:** `pytest` (harness established by E01).
- **Leakage guard:** non-negotiable (roadmap principle #1). A feature may use only data
  before the entry timestamp. The orchestrator runs this as a blocking check.
- **Validation harness (E04):** walk-forward, purge/embargo, unseen-coin/period holdout,
  **must beat both baselines out-of-sample**. For any model/rule/feature change, *this is the
  gate* — not the unit tests. Until E04 exists, ML changes cannot be auto-merged. Period.

---

## Trust ladder — how "auto-merge on green" unlocks safely

Daan chose **auto-merge on green**. That is the destination, not the starting line:
auto-merge is exactly as safe as "green" is, and right now there is no CI to be green.
We climb this ladder one rung at a time; each rung is earned.

| Rung | State | Auto-merge policy |
|---|---|---|
| **0 — now** | No CI, no validation harness | **PR-gate everything.** Orchestrator opens PRs; Daan merges. |
| **0.5 — prerequisite** | Build CI (`.github/workflows`) + branch protection on `main` | Still PR-gated, but "green" now means something. |
| **1 — chores** | CI green | Auto-merge **low-risk categories only**: docs, tests, Pint formatting, dependency bumps with green CI. |
| **2 — features** | E04 validation harness exists and gates ML changes | Auto-merge data/feature/ML epics when CI **and** E04 pass out-of-sample. |
| **never** | — | **Execution & money never auto-merge:** E09 (exit/sizing), E10 (MEXC execution), any code touching exchange API keys, order placement, or a live-trading toggle. Human-gated forever, regardless of green. |

Rule of thumb: **the closer a change is to placing a real order, the more human it stays.**

---

## Automating feature-building — the autonomy ladder

The trust ladder above governs **whether code merges**. Building *new features* on a routine
adds two more gates, opened in the same step-by-step spirit — validate, then release more:

- **Idea gate** — *what* gets built. The frontier risk: a system inventing its own features is
  far less reliable than one building a spec you already approved. Open this gate last, and
  only for narrow categories.
- **Deploy gate** — *what reaches production*. For a money-handling bot, merged ≠ live. Even a
  green, merged change waits behind a separate release gate.

### The ladder — release one rung at a time, per category

| Stage | Routine does | You do | Unlocks when |
|---|---|---|---|
| **A — Propose** | Scans for gaps (legacy-parity checklist, issues, TODOs) and writes feature proposals into a backlog file. Builds nothing. | Read, pick, build via chat. | Now. |
| **B — Build, PR-gated** | Takes a feature *you approved*, builds it in a worktree, tests, opens a PR. | Approve the spec; merge the PR. | CI exists (rung 0.5). |
| **C — Build + auto-merge** | Pulls the next approved backlog item and auto-merges on green, for safe categories only. | Approve *what* goes on the backlog. | Stage B clean over a track record (below). |
| **D — Propose + build** | Proposes the feature *and* builds it end-to-end; you only bless the idea. | One-click yes/no on the *idea*. | Stage C trustworthy + E04 validation harness exists. |
| **E — Self-direct (narrow)** | For tightly-bounded, harness-judged categories (e.g. "add a derived feature", "add a validation report") proposes, builds, validates, merges — no human. | Nothing; audit the log. | A category has an objective judge (E04) and a contained blast radius. |

**Money and execution (E09/E10, exchange keys, live-trading toggles) never climb past Stage B.**
No track record buys autonomy where a bug spends real money.

### Why routines make this *safe*, not just possible

A `/schedule` routine is the right tool precisely because each run is:

- **Discrete & logged** — one auditable unit of work, not a continuous black box.
- **Permission-bounded** — you grant a routine exactly as much scope as it has earned
  (propose-only → PR-only → auto-merge-safe-categories). Same routine, widening mandate.
- **Reversible** — worktree isolation + PR + branch protection mean nothing lands un-gated,
  and every merge is a revertable commit.

So "validate first, release more later" isn't a hope — it is literally how you configure the
routine's permissions, one rung at a time.

### How a category earns the next rung (promotion criteria)

Advance a *category* (not the whole system) only on evidence. Set exact thresholds when wiring
the routine; the principle is *track record per category*, never a blanket flip:

- **B → C:** e.g. 10 consecutive PRs in that category merged with zero post-merge reverts and
  no human edits before merge.
- **C → D:** the harness/CI caught every regression in those builds; no escaped defect reached `main`.
- **D → E:** only for categories with an objective judge (E04) and a blast radius that cannot
  touch money or production data.

---

## When the human is pulled in (the amber gate)

The orchestrator escalates to Daan — and only then — when:

1. **Direction.** A phase finished; which epic/phase is next is a product call.
2. **Ambiguity.** Requirements admit more than one reasonable interpretation.
3. **Repeated failure.** A build went red 3× — don't burn tokens guessing; ask.
4. **The harness says no, but the search wants in.** E06 proposes a rule E04 rejects, or
   a metric is borderline — a judgment call about edge, not a mechanical pass/fail.
5. **Money, keys, security.** Anything in the "never auto-merge" row, plus any change that
   touches secrets, auth, or the read-only `bot_signals` source (which must never be written).

Escalation = open a PR with a written summary + a `PushNotification`. Daan replies yes/no.

---

## Mechanics in this stack

Everything needed already exists in the toolchain:

- **Heartbeat:** a `/schedule` cloud routine (e.g. nightly) is the orchestrator's clock —
  "pick the next ready epic and run the loop."
- **Isolation:** each epic builds in its own **git worktree** (base
  `/Users/daanvantongeren/Documents/Sites/brain-builds`, per the AI Factory config) so
  parallel/failed work never touches `main`.
- **Existing skills do the stages:** `build-epic` (AI Factory) builds, `review-epic` reviews
  + merges + queues the next epic, `critical-eye` / `review-security` harden the gate,
  `ce-compound` writes each lesson back as reusable knowledge so the system compounds.
- **Notifications:** `PushNotification` for the amber gate only — Daan is not pinged for
  routine green merges.
- **Orchestrator state:** a single tracked file (e.g. `docs/build-queue.md`) holding the
  ordered backlog, each epic's status (ready / building / in-review / merged / blocked), and
  retry counts. This is the orchestrator's memory between runs.

---

## Prerequisites before flipping it on (honest blockers)

The loop cannot safely run today. In order:

1. **CI does not exist.** Build `.github/workflows` running Pest + Pint (and later pytest +
   leakage guard). No green, no auto-merge.
2. **Branch protection** on `main` — auto-merge must go through a checked PR, never a direct push.
3. **Python harness does not exist.** E01 must establish the `pytest` + leakage-guard scaffold
   before any ML change can be gated at all.
4. **E04 is the linchpin** for ML auto-merge. Treat it as the gate that unlocks rung 2.
5. **A test for E08's own claims.** The runtime orchestrator that trades must itself be the
   most heavily gated thing in the repo — its tests are a product feature, not an afterthought.

---

## Recommended first three moves

1. **Build CI + branch protection** (rung 0.5). Smallest change that makes "green" real.
   This is itself a good first autonomous task for the orchestrator to dry-run on, PR-gated.
2. **Stand up the orchestrator scaffold:** the `/schedule` routine + `docs/build-queue.md`
   seeded with E01→E11 in build order, running in **PR-gate mode** (rung 0). Watch it open a
   PR for E01 without merging — prove the mechanism once, end to end.
3. **Build E01 + E02** (Phase 0) through the loop, PR-gated. Phase 0 establishes the pytest +
   leakage-guard harness, which is the precondition for ever climbing to rung 2.

Auto-merge gets switched on **per category, after** these are in place and the validation
harness exists — not before.
