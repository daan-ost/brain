---
name: brain-routines
description: The automation/routine framework — the daily rule-optimization + auto-apply routines, how the runner and run-journal work, and how to ADD a new routine to the chain. Use when adding or changing routines, or reading the /routines screen.
---

How the brain project automates rule improvement: scheduled **Local** Claude Code routines run
`engine/src/routines.py` on the Mac, which reaches the local MAMP `brain` DB. (Cloud routines can't —
the DB is local. So routines must be **Local**: they only run while the Mac is awake + MAMP up.)

## Sets

A **set** is a named ordered chain of routines with a shared goal. Each set's key/name is written onto
every `routine_runs` row (`set_key`/`set_name`), shown per run on `/routines`. `routines.py` now holds a
`SETS` dict and a `--set <key>` selector (default `rule-precision`, so the existing scheduled prompt is
unchanged). Two sets exist:
- **`rule-precision`** ("eliminate existing bad trades from the rules") — gated by the data-changed
  fingerprint. The tighten/loosen chain below.
- **`data-integriteit`** ("consistency & safe cache-fix") — periodic read-only health checks; NOT
  fingerprint-gated (a health check must run even when nothing changed) and it YIELDS to an active
  rule-precision run (concurrency guard). See its own section below.
- **`recall-triage`** ("catch promising groups — proposals") — fires when new promising LABELS come in
  (`input_fingerprint(with_labels=True)` adds the ok-labels to the fingerprint for this set only). Runs
  `recall_worklist.py` (re-fill the dossier) + `recall_loop.py --write` (propose bounded tweaks for the
  feature-missed groups). **PROPOSE-ONLY: it never touches `brain.rules`** — recall tweaks are in-sample
  / overfit-risky (need holdout), so the human (or a later validated routine) decides on applying. It
  journals per coin: recall% · proposed_catch · needs_new_rule · no_candidate (the engine/ingestion
  blocker, ~80% of NOS misses — not rule-fixable) + where needs_new_rule homes (rule-21 child-variant
  candidates). Schedule: a Local routine `routines.py --set recall-triage --trigger routine` (no `--apply`).

As more goals appear, add another set to `SETS` (its own REGISTRY + name, scheduled separately).

The rule-precision set improves the good/bad ratio both ways: **tighten** (rq1 — fewer slecht, `auto-apply`)
and **loosen** (rq2 — more good, `auto-loosen`). Both behind a full-history + portfolio engine gate.
Still to come: **outlier-split into a new rule** (2b — PARKED, see the roadmap memory; its companion-rule
cost is not cache-measurable, needs a real rule_number-24 engine re-fire).

## The runner (`engine/src/routines.py`)

One ordered chain, one journaled run. `REGISTRY = [(key, fn), ...]` runs each routine in sequence and
writes a `routine_runs` header (with the set) + `routine_run_log` lines (shown on `/routines`). Today:

1. **`rule-optimization`** — `daily_optimization.run_optimization()`: refire both coins → rebuild the
   `indicator_metrics` cache → `rq1_tighten.py all` → diff SAFE candidates vs already-applied. Logs the
   per-rule ratios + the new safe candidates as **proposals** (applies nothing).
2. **`auto-apply`** — rq1 TIGHTEN: add the strongest safe subrule per rule. Only with `--apply`; gate below.
3. **`auto-loosen`** — rq2 LOOSEN (`auto_loosen.py`): widen an existing band to admit MORE good (raise
   the numerator). A loosening ADDS fires so it's riskier — gated by (1) a DiagEngine full-HISTORY
   re-fire on BOTH coins (reject if ANY new slecht fire; the cheap per-coin in-sample "0 new bad" is
   misleading — the report disqualified 3/11 that way) THEN (2) a persist portfolio confirm (keep iff
   total good RISES and total slecht does NOT rise, else revert the band). Keeps the subrule's source;
   the band change lands in rules_history. Only with `--apply`; max one loosening per rule per run.

Flags: `--no-rebuild` (skip the refire/cache rebuild → fast, preview only), `--apply` (actually apply),
`--trigger routine|manual|api`, `--date YYYY-MM-DD`.

## The apply gate (`engine/src/auto_apply.py`)

Per rule, take the **strongest** new SAFE rq1 candidate, insert it (`source='auto-applied'`), refire
BOTH coins over the full history, and **KEEP only if total executed GOOD is preserved (0 opportunity
lost) AND total executed SLECHT strictly drops** — else revert. Total, not per-rule: a tightening can
reshuffle bad onto another rule via the single-position dedup, so only the total counts. At most one
subrule per rule per run (principle 1). Every kept change is logged (`level=change`, "rule X: slecht
A→B, totaal slecht …, goede behouden") and recorded in `rules_history` (source `auto-applied`).

## The data-changed gate (`routine_state`)

Before the expensive chain runs, the runner fingerprints the INPUT — per-coin `indicators`
(count + max datetime) **and** the active `rules` (count + max `updated_at`) — and compares it to
`routine_state.fingerprint` (one row per set). Same fingerprint and no `--force` → **skip** (update
`last_checked_at` only, no run). Because the fingerprint includes `rules`, an applied/manual rule
change bumps it → the next run proceeds (this is what lets one tightening unlock the next, the
compounding effect). A converged run with no new data leaves it stable → it skips. It stores the
**start** fingerprint of the run that executed. `--force` bypasses the gate (the "Nu draaien" button
uses it so the preview always works). So you can schedule it as often as you like — it only does real
work when data or rules actually changed.

## The data-integriteit set (`integrity.py` + `routine_integrity`)

A SECOND set that periodically checks whether the brain data is consistent and correct. The check
logic lives in **`engine/src/integrity.py`** — a **read-only** module (SELECT only; mutates NOTHING).
Each check returns a `Result(status ∈ ok|warn|fail, summary, details, fixable, fix_coins)`;
`run_all(conn, quick=)` runs them all, `worst()` folds the overall status. Run standalone for a report:
`integrity.py [--quick]` (exit 1 on any FAIL). **Scope = the brain coins only** (`coins` table = the
coins with brain indicators, today DOGEAI 2525 + NOS 244) — legacy labels exist for ~74 coins but
laag-2 is only ever BUILT for the brain coins, so scoping there avoids false FAILs.

The 11 checks:
1. **laag2_coverage** (the seed) — every trade (`coin_fires`) + every ok-moment (`coin_moment_labels`
   `decision='yes'`, snapped to the volumeud tick like `build_indicator_metrics`) has its laag-2 rows.
   *fixable* (cache rebuild).
2. **fires_drift** — reproduce the rule-engine fires in-memory (`RuleEngine.fires`, read-only) and
   compare the `(rule, datetime)` set to `coin_fires`; mismatch = stale fires (e.g. aborted run). HEAVY
   (~45 s both coins) — skipped with `--quick`.
3. **executed_nulls** — no NULL `best_upside` / `selling_price` / `selling_datetime` / `profit_loss` on
   executed trades.
4. **labels** — label datetimes sit on a real volumeud tick; FAIL only on a demonstrable un-applied
   −5s align (`dt+5s` is a tick, `dt` isn't → re-run `import_legacy_labels`); orphans in a genuine
   volumeud gap / sub-60 s jitter are WARN (unavoidable, so the routine isn't permanently red).
5. **promising_periods** — `period_from <= period_to` and inside the coin's indicator date-range.
6. **rules_provenance** — the latest `rules_history` snapshot per rule == current `brain.rules`
   (drift = a rule changed without `rules_history.record()`).
7. **cache_freshness** — the `indicator_metrics` datetime-set == the current scope (trades + promising
   ticks + ok ticks), BOTH directions (missing for new trades AND stale rows after a re-fire removed
   trades). *fixable* (cache rebuild).
8. **indicators** — volumeud present per coin, no NULL prices, large time-gaps reported (WARN).
9. **rule_settings** — `min_volume` present per coin × active buy-rule (20-23).
10. **routine_state** — fingerprint plausible (32-hex) + no `running` `routine_runs` older than 30 min
    (stuck/abandoned).
11. **sell_record** — every executed buy has a valid sell: `selling_price` + `selling_datetime`
    present, sell AFTER the buy and within `FORWARD_MINUTES` (60), `profit_loss` computed.

`routine_integrity(j)` journals one PASS/FAIL line per check (`ok→result`, `warn→finding`,
`fail→error`, with the full `Result` in `data`). **Auto-fix = cache rebuild ONLY, behind `--fix`**:
without it, it just logs "veilige auto-fix beschikbaar"; with it, it runs `build_indicator_metrics.py`
for the affected coins (idempotent per symbol — only the CACHE table, never real data) and re-verifies
the two cache checks. **Concurrency:** the set is NOT fingerprint-gated, but `main()` aborts it if any
OTHER `routine_runs` row is `running` and started within 30 min (`_active_recent_run`) — so a `--fix`
cache rebuild never races the hourly rule-precision chain (we saw a 1412 + snapshot-drift on overlap).
`--force` bypasses both the skip and the guard.

Run it (Local routine, separate schedule from rule-precision):
```
cd engine/src && ../.venv/bin/python routines.py --set data-integriteit --trigger routine        # report-only
cd engine/src && ../.venv/bin/python routines.py --set data-integriteit --trigger routine --fix   # + safe cache rebuild
```
Add `--quick` for a fast preview (skips the heavy drift reproduce).

## The `/routines` screen

`www` Livewire `Routines/Index` (route `/routines`, admin, trading layout). Lists recent runs with
their journal lines, colour-coded by level (`result`/`finding`/`change`/`error`/`info`). The **"Nu
draaien"** button runs `routines.py --no-rebuild` (analysis-only, **no `--apply`** → never mutates) so
it's a safe preview. Models: `RoutineRun` (`logs()`), `RoutineRunLog` (`data` cast to array). Tables
from migration `2026_06_15_010000_create_routine_runs_tables`.

## Adding a NEW routine (this is the extension point)

1. Write `def routine_xyz(j):` in `routines.py`. Do the work; emit log lines with
   `j.add(message, level=..., rule=..., data=...)`. Levels: `info|finding|change|result|error`.
   Return a one-line summary string.
2. Append `("xyz", routine_xyz)` to `REGISTRY`. It now runs **after** the others in the same journaled
   run — no screen change, no migration. Keep it propose-only unless it has its own validated gate.
3. If it mutates rules, gate it like `auto_apply` (engine-refire: 0 good lost) and record `rules_history`.
   Guard any apply behind a flag so the on-screen preview button never mutates.

The scheduled Local routine's prompt just calls the runner; new routines are picked up automatically:
```
cd engine/src && ../.venv/bin/python routines.py --trigger routine --apply
```

## Setup (one-time, in Claude Code)

Routines → New routine → **Local** → folder = the brain project → daily at a time the Mac is awake +
MAMP running (e.g. ~12:00) → prompt = run the command above autonomously and report briefly.

Related: [[brain-rule-tuning]] (what the optimization proposes), [[brain-engine]] (the engine the
routine drives), [[brain-indicator-metrics]] (the cache it rebuilds).
