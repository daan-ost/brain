# EPIC 08: Daily autonomous orchestration

**Phase:** 3 — Autonomy
**Status:** Planned (north-star capstone)
**Depends on:** E04, E05, E06, E07

## Goal

The capstone: scheduled routines that run the whole loop with little human time — pull the latest indicators, search for and evaluate new rules, refresh active coins and rules, and report. The end state extends to the system proposing its own code changes, human-gated first.

## Rationale

Daan has little time. The point of the project is a system that searches, evaluates, and extends itself rather than needing manual rule-tuning sessions. This won't be day 1, but it's the explicit direction — recorded here so we always build toward it.

## Scope

1. **Daily data refresh.** Scheduled job pulls the latest indicator data into the analytical store (the source keeps receiving the TradingView webhook; we read it read-only).
2. **Scheduled discovery.** Run E06's loop on a cadence; surface newly discovered, validated rules.
3. **Active-set maintenance.** Run E05 (deactivate quiet coins) and E07 (activate new volatile ones) on schedule.
4. **Re-evaluation & drift monitoring.** Re-score active rules through E04; alarm when live results diverge from backtest (regime drift).
5. **Reporting.** A daily digest: what was discovered, promoted, deactivated, and how the active rules are performing.
6. **Human-in-the-loop → autonomous.** Promotions and coin changes are approved by Daan first; an auto-approve toggle opens as trust grows.
7. **Self-extension (north-star end state).** Staged: the system proposes code/feature/rule changes (e.g. new feature functions, new search spaces) as reviewable diffs, human-gated, moving toward more autonomy over time. Explicitly a later stage with guardrails — recorded now so the architecture leaves room for it.

## Acceptance criteria

- [ ] Scheduled jobs refresh data, run discovery, and maintain the active coin/rule set without manual steps.
- [ ] A daily digest reports discoveries, promotions, deactivations, and performance.
- [ ] Drift monitoring alarms when live diverges from backtest.
- [ ] Promotions/changes are human-gated with a scoped path to auto-approval.
- [ ] The architecture documents how staged self-extension (proposed code changes as reviewable diffs) will plug in.

## Out of scope

- Live order execution (E10). Orchestration here is research/decision automation, not trading money yet.

## Recommended tooling & prior art (from research)

> Provenance: verified research bundle + adversarial verification pass (supersedes the earlier empty-payload note).

**Orchestration** [ESTABLISHED]
- **Laravel scheduler + queued jobs** invoking the Python engine (artisan→Python / queue) — matches the E11 control-plane/compute split. Keep all ML in Python; Laravel only schedules, gates, and renders. For heavier DAG scheduling, **Airflow/Prefect** are the named options.

**Drift monitoring — the delayed-label problem is the crux** [VERIFIED]
- Trade-outcome labels arrive **hours to days** after the decision, so statistical drift tests that need labels (KS on residuals) **cannot fire in real time.** The right answer is **NannyML** (https://nannyml.readthedocs.io/) with **CBPE (Confidence-Based Performance Estimation)** / DLE — it estimates F1/precision/recall **without ground truth** by exploiting calibrated prediction confidence. **Requirement:** CBPE needs **well-calibrated probabilities** — if the E03 model outputs uncalibrated tree probabilities, apply Platt/isotonic first or CBPE estimates will be wrong.
- **Evidently** (https://www.evidentlyai.com/) for batch feature-drift reports (PSI, KS, Wasserstein); **PSI** thresholds: <0.1 no shift, 0.1–0.25 moderate, >0.25 significant. **river** (https://riverml.xyz/) for streaming drift (ADWIN, Page-Hinkley) if a real-time architecture is wanted.
- This matters: low-cap crypto is highly non-stationary, so a static filter decays — the alarm-on-divergence requirement is well-founded.

**Experiment & model lineage / promotion** [VERIFIED pattern]
- **MLflow** (https://mlflow.org/) for run tracking, model registry, and the audit trail behind every promotion (`transition_model_version_stage()` for promote/demote/archive). The **verified MLOps retraining pattern** (arXiv 2512.11541): drift triggers a **challenger** train → evaluate on a held-out **recent OOS window** → promote **only if ALL quality gates pass**, archive the old model.
- **Guardrail against retrain cascades:** on volatile crypto, market move → drift alarm → retrain on a bad period → worse model → more alarms. Add a **minimum retrain interval (~7 days)** and require drift to **persist across multiple windows** before triggering.

**Walk-forward cadence** [VERIFIED caveat]
- The 2026 Bitcoin study (arXiv 2602.10785) tested 81 window-size combinations (1–28 day windows, 1m–60m bars) and found performance **highly sensitive to window length with no universally optimal window.** Validate the retrain window empirically per coin/strategy; track **Walk-Forward Efficiency (OOS profit / in-sample profit) — target >0.5, reject <0.3.**

**Self-extension (north-star)** [REASONED]
- Staged, human-reviewed code diffs is an agentic ambition, **not** a source of trading edge — keep it firewalled from the trading thesis. The closest published analog, **Microsoft RD-Agent** (NeurIPS 2025, `verified`: 14.21% vs 6.80% baseline on CSI300, **no slippage modeled, equities only**), shows autonomous discovery loops can beat a baseline on backtests but have **no audited live results.** Mandatory guardrails: every proposed change is a reviewable diff, never unattended shipping early, full audit.

**References**
- NannyML CBPE — https://nannyml.readthedocs.io/en/stable/ · Evidently — https://www.evidentlyai.com/ · MLflow — https://mlflow.org/ · Multi-criteria automated retraining (arXiv 2512.11541) — https://arxiv.org/pdf/2512.11541 · Walk-forward window sensitivity (arXiv 2602.10785) — https://arxiv.org/abs/2602.10785 · RD-Agent — https://saulius.io/blog/automated-quant-research-ai-agents-rd-agent

## Notes

- Self-extension is ambitious and staged: human-reviewed diffs first, never unattended code shipping early. Guardrails and audit trails are mandatory.
