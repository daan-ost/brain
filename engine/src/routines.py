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
import json
import sys

from db import brain
import daily_optimization as opt

NO_REBUILD = "--no-rebuild" in sys.argv
RUN_DATE = sys.argv[sys.argv.index("--date") + 1] if "--date" in sys.argv else None
TRIGGER = sys.argv[sys.argv.index("--trigger") + 1] if "--trigger" in sys.argv else "manual"


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


# Ordered chain. Append future routines here; they run after each other in one journaled run.
REGISTRY = [
    ("rule-optimization", routine_rule_optimization),
]


# --------------------------------------------------------------------------- runner
def main():
    now = datetime.datetime.now()
    run_date = RUN_DATE or now.date().isoformat()
    conn = brain()
    with conn.cursor() as c:
        c.execute("INSERT INTO routine_runs (run_date, started_at, status, `trigger`, created_at, updated_at) "
                  "VALUES (%s,%s,'running',%s,%s,%s)", (run_date, now, TRIGGER, now, now))
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
        with conn.cursor() as c:
            c.execute("UPDATE routine_runs SET finished_at=%s, status=%s, n_routines=%s, summary=%s, "
                      "updated_at=%s WHERE id=%s",
                      (datetime.datetime.now(), status, len(REGISTRY), " | ".join(summaries),
                       datetime.datetime.now(), run_id))
        conn.commit()
        conn.close()

    print(f"routine-run #{run_id} [{status}] {run_date}")
    for s in summaries:
        print("  " + s)


if __name__ == "__main__":
    main()
