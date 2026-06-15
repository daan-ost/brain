#!/usr/bin/env python3
"""
Routine RUNNER — runs an ordered chain of automation routines and journals every run to the
brain DB (routine_runs + routine_run_log), which the /routines screen shows. This is routine #1
(rule-optimization); add more by appending to REGISTRY — they run after each other, one journal per
chain execution.

Designed to be the body of a LOCAL Claude Code routine (or a plain cron/launchd job): it runs on
the Mac, so it reaches the local MAMP `brain` DB. It PROPOSES rule changes (logs them); it does not
apply anything unless you wire an apply-routine in explicitly.

Usage: routines.py [--no-rebuild] [--date YYYY-MM-DD] [--trigger routine|manual|api]
"""
import datetime
import hashlib
import json
import sys

from db import brain
import daily_optimization as opt

NO_REBUILD = "--no-rebuild" in sys.argv
APPLY = "--apply" in sys.argv          # actually apply safe candidates; without it, propose-only
FORCE = "--force" in sys.argv          # bypass the data-changed gate (manual preview / testing)
RUN_DATE = sys.argv[sys.argv.index("--date") + 1] if "--date" in sys.argv else None
TRIGGER = sys.argv[sys.argv.index("--trigger") + 1] if "--trigger" in sys.argv else "manual"


def input_fingerprint():
    """Signature of everything that determines the analysis outcome: the raw `indicators` per coin
    (count + latest datetime) AND the active `rules` (count + latest change). New data OR a rule
    change (auto-applied last run, or a manual edit) bumps it; a converged run with no new data
    leaves it stable → the gate skips. coin_periods/coin_fires are derived, so not needed here."""
    conn = brain()
    with conn.cursor() as c:
        c.execute("SELECT trading_symbol_id s, COUNT(*) n, MAX(datetime) mx FROM indicators GROUP BY trading_symbol_id ORDER BY s")
        ind = c.fetchall()
        c.execute("SELECT COUNT(*) n, MAX(updated_at) mx FROM rules WHERE active=1")
        rules = c.fetchone()
    conn.close()
    sig = "|".join(f"{r['s']}:{r['n']}:{r['mx']}" for r in ind) + f"#rules:{rules['n']}:{rules['mx']}"
    return hashlib.md5(sig.encode()).hexdigest()


class Journal:
    """Collects log lines for one routine, then the runner persists them in order."""
    def __init__(self, key):
        self.key = key
        self.lines = []

    def add(self, message, level="info", rule=None, data=None):
        self.lines.append({"level": level, "rule_number": rule, "message": message, "data": data})


# --------------------------------------------------------------------------- routines
def routine_rule_optimization(j):
    """Daily rule-precision scan: ratios per rule + any NEW safe tightening (proposal, not applied)."""
    res = opt.run_optimization(rebuild=not NO_REBUILD)
    ratios, new = res["ratios"], res["new"]

    parts = []
    for rule in sorted(ratios):
        g, s = ratios[rule]
        parts.append(f"r{rule} {g/s:.2f} ({g}/{s})" if s else f"r{rule} {g}/0")
    j.add("Ratio per rule (executed): " + ", ".join(parts), level="result",
          data={"ratios": {str(k): v for k, v in ratios.items()}})

    if not new:
        j.add("Geen nieuwe veilige aanscherpingen — rules stabiel.", level="finding")
        return f"stabiel · {', '.join(parts)}"

    by_rule = {}
    for c in new:
        by_rule.setdefault(c["rule"], []).append(c)
    for rule in sorted(by_rule):
        cs = sorted(by_rule[rule], key=lambda x: -x["drop_insample"])
        top = cs[0]
        bnd = "≥" if top["bound"] == "lower" else "≤"
        j.add(f"rule {rule}: {len(cs)} nieuwe veilige kandida(a)t(en). Sterkste: "
              f"{top['indicator']}/{top['calc']}/lb{top['lookback']} {bnd} {round(top['threshold'], 5)} "
              f"— dropt ~{top['drop_insample']} slecht (in-sample), out-of-sample SAFE. "
              f"VOORSTEL — niet toegepast.", level="finding", rule=rule,
              data={"candidates": cs[:10]})
    return f"{len(new)} nieuwe kandidaten · {', '.join(parts)}"


def routine_auto_apply(j):
    """Apply the strongest new safe candidate per rule (engine-refire gated). Only acts with --apply;
    otherwise it stays propose-only so the on-screen 'Nu draaien' button never mutates the rules."""
    if not APPLY:
        j.add("Auto-apply: uit (geen --apply) — kandidaten alleen voorgesteld, niets toegepast.", level="info")
        return "apply uit"
    import auto_apply
    return auto_apply.apply_safe(lambda m, level="change", rule=None, data=None: j.add(m, level, rule, data))


# A SET is a named chain of routines with a shared goal. This set = eliminate existing bad trades
# from the rules (tighten existing rules now; outlier-split into new rules = 2b, coming). Append
# routines below; they run after each other in one journaled run, under this set's name.
SET_KEY = "rule-precision"
SET_NAME = "Rule-precisie — bestaande slechte trades elimineren"
REGISTRY = [
    ("rule-optimization", routine_rule_optimization),   # sweep all calcs×lookbacks → tighten existing rules
    ("auto-apply", routine_auto_apply),                 # apply the strongest safe tightening (engine-gated)
    # ("outlier-split", routine_outlier_split),          # 2b: pull an outlier good trade into a new rule
]


# --------------------------------------------------------------------------- runner
def _state(conn, key):
    with conn.cursor() as c:
        c.execute("SELECT fingerprint FROM routine_state WHERE set_key=%s", (key,))
        return c.fetchone()


def _save_state(conn, key, fp, now, ran, outcome):
    with conn.cursor() as c:
        c.execute(
            "INSERT INTO routine_state (set_key, fingerprint, last_checked_at, last_ran_at, last_outcome, "
            "created_at, updated_at) VALUES (%s,%s,%s,%s,%s,%s,%s) ON DUPLICATE KEY UPDATE "
            "fingerprint=VALUES(fingerprint), last_checked_at=VALUES(last_checked_at), "
            "last_ran_at=COALESCE(VALUES(last_ran_at), routine_state.last_ran_at), "
            "last_outcome=VALUES(last_outcome), updated_at=VALUES(updated_at)",
            (key, fp, now, ran, outcome, now, now))
    conn.commit()


def main():
    now = datetime.datetime.now()
    run_date = RUN_DATE or now.date().isoformat()
    conn = brain()

    # DATA-CHANGED GATE: skip the (expensive) chain if nothing that affects the outcome changed.
    fp = input_fingerprint()
    prev = _state(conn, SET_KEY)
    if prev and prev["fingerprint"] == fp and not FORCE:
        _save_state(conn, SET_KEY, fp, now, None, "geen wijziging — overgeslagen")
        conn.close()
        print(f"[{run_date}] geen data- of rule-wijziging sinds laatste run — overgeslagen (gebruik --force om toch te draaien).")
        return

    with conn.cursor() as c:
        c.execute("INSERT INTO routine_runs (set_key, set_name, run_date, started_at, status, `trigger`, "
                  "created_at, updated_at) VALUES (%s,%s,%s,%s,'running',%s,%s,%s)",
                  (SET_KEY, SET_NAME, run_date, now, TRIGGER, now, now))
        run_id = c.lastrowid
    conn.commit()

    seq = 0
    summaries = []
    status = "success"
    try:
        for key, fn in REGISTRY:
            j = Journal(key)
            try:
                summary = fn(j)
            except Exception as e:  # one routine failing must not lose the journal
                j.add(f"FOUT in routine {key}: {e}", level="error")
                summary = f"FOUT: {e}"
                status = "failed"
            with conn.cursor() as c:
                for line in j.lines:
                    seq += 1
                    c.execute(
                        "INSERT INTO routine_run_log (routine_run_id, routine_key, seq, level, "
                        "rule_number, message, data, created_at) VALUES (%s,%s,%s,%s,%s,%s,%s,%s)",
                        (run_id, key, seq, line["level"], line["rule_number"], line["message"],
                         json.dumps(line["data"], default=str) if line["data"] is not None else None,
                         datetime.datetime.now()))
            conn.commit()
            summaries.append(f"{key}: {summary}")
    finally:
        end = datetime.datetime.now()
        with conn.cursor() as c:
            c.execute("UPDATE routine_runs SET finished_at=%s, status=%s, n_routines=%s, summary=%s, "
                      "updated_at=%s WHERE id=%s",
                      (end, status, len(REGISTRY), " | ".join(summaries), end, run_id))
        conn.commit()
        # store the START fingerprint of this executed run: if a routine changed the rules, the next
        # run's fingerprint differs (→ re-runs, compounding); if nothing changed, it matches (→ skips).
        _save_state(conn, SET_KEY, fp, end, end, " | ".join(summaries)[:160])
        conn.close()

    print(f"routine-run #{run_id} [{status}] {run_date}")
    for s in summaries:
        print("  " + s)


if __name__ == "__main__":
    main()
