---
name: brain-routines
description: The automation/routine framework — the daily rule-optimization + auto-apply routines, how the runner and run-journal work, and how to ADD a new routine to the chain. Use when adding or changing routines, or reading the /routines screen.
---

How the brain project automates rule improvement: scheduled **Local** Claude Code routines run
`engine/src/routines.py` on the Mac, which reaches the local MAMP `brain` DB. (Cloud routines can't —
the DB is local. So routines must be **Local**: they only run while the Mac is awake + MAMP up.)

## Sets

A **set** is a named ordered chain of routines with a shared goal. The current set is
**`rule-precision`** ("eliminate existing bad trades from the rules") — its `SET_KEY`/`SET_NAME` live
in `routines.py` and are written onto every `routine_runs` row (`set_key`/`set_name`), shown per run
on `/routines`. As more goals appear, add a new set (its own REGISTRY + name, scheduled separately).

The rule-precision set covers BOTH ways to eliminate bad without finding new trades: **tighten existing
rules** (add a subrule — done, `rule-optimization`+`auto-apply`) and **outlier-split into a new rule**
(2b — coming, an over-wide band caused by one outlier good trade → move that good to its own rule).

## The runner (`engine/src/routines.py`)

One ordered chain, one journaled run. `REGISTRY = [(key, fn), ...]` runs each routine in sequence and
writes a `routine_runs` header (with the set) + `routine_run_log` lines (shown on `/routines`). Today:

1. **`rule-optimization`** — `daily_optimization.run_optimization()`: refire both coins → rebuild the
   `indicator_metrics` cache → `rq1_tighten.py all` → diff SAFE candidates vs already-applied. Logs the
   per-rule ratios + the new safe candidates as **proposals** (applies nothing).
2. **`auto-apply`** — only acts with `--apply`; see the gate below.

Flags: `--no-rebuild` (skip the refire/cache rebuild → fast, preview only), `--apply` (actually apply),
`--trigger routine|manual|api`, `--date YYYY-MM-DD`.

## The apply gate (`engine/src/auto_apply.py`)

Per rule, take the **strongest** new SAFE rq1 candidate, insert it (`source='auto-applied'`), refire
BOTH coins over the full history, and **KEEP only if total executed GOOD is preserved (0 opportunity
lost) AND total executed SLECHT strictly drops** — else revert. Total, not per-rule: a tightening can
reshuffle bad onto another rule via the single-position dedup, so only the total counts. At most one
subrule per rule per run (principle 1). Every kept change is logged (`level=change`, "rule X: slecht
A→B, totaal slecht …, goede behouden") and recorded in `rules_history` (source `auto-applied`).

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
