#!/usr/bin/env python3
"""
Append-only history of the `rules` table. Call record() after ANY mutation of brain.rules; it bumps
a monotonic version and writes, per CHANGED rule, one row with a full snapshot of that rule's
subrules + the diff vs the previous version + a per-rule toelichting (why). Unchanged rules are not
duplicated — "rule R at version N" = latest row for R with version <= N.

Design: snapshot AND diff (the rule-set is tiny, so full snapshots are free and make point-in-time
reconstruction + diffing trivial; the diff gives a human-readable changelog). See the migration
2026_06_15_000000_create_rules_history_table.php.

Usage as a library:
    import rules_history as h
    h.record({20: "rule 20 aangescherpt met vzo/range_percentage", ...}, source="rq1-report")
CLI:
    rules_history.py show [rule]      — print the changelog (optionally one rule)
    rules_history.py snapshot         — record the CURRENT rules as a new version (manual checkpoint)
"""
import json
import sys
from datetime import datetime

from db import brain

# the columns that define a subrule's identity + value. `sort` (eval order) and timestamps are
# excluded on purpose: for a flat AND, order is cosmetic, and add_tuned_subrules re-inserts with a
# fresh sort every run — including it would flag a spurious change on every identical re-run.
FIELDS = ["indicator", "subrulename", "def1_value", "b_min", "b_max",
          "value_condition", "operator", "condition_rule", "active", "source"]


def _key(sr):
    """Identity of a subrule within a rule: (indicator, subrulename, def1_value)."""
    return (sr.get("indicator"), sr.get("subrulename"), sr.get("def1_value"))


def current_rules():
    """{rule_number: [subrule dict, ...]} from brain.rules, sorted by sort then identity."""
    conn = brain()
    with conn.cursor() as c:
        c.execute(f"SELECT rule_number, {', '.join(FIELDS)} FROM rules ORDER BY rule_number, sort")
        rows = c.fetchall()
    conn.close()
    out = {}
    for r in rows:
        rn = r["rule_number"]
        sr = {k: r[k] for k in FIELDS}
        out.setdefault(rn, []).append(sr)
    return out


def _last(conn, rule_number):
    """(version, snapshot list) of the most recent row for a rule, or (0, None)."""
    with conn.cursor() as c:
        c.execute("SELECT version, snapshot FROM rules_history WHERE rule_number=%s "
                  "ORDER BY version DESC LIMIT 1", (rule_number,))
        r = c.fetchone()
    if not r:
        return 0, None
    return r["version"], json.loads(r["snapshot"])


def _diff(old, new):
    """added / removed / modified subrules between two snapshot lists (by identity key)."""
    om = {_key(s): s for s in (old or [])}
    nm = {_key(s): s for s in new}
    added = [nm[k] for k in nm if k not in om]
    removed = [om[k] for k in om if k not in nm]
    modified = []
    for k in nm:
        if k in om and any(str(om[k].get(f)) != str(nm[k].get(f)) for f in FIELDS):
            modified.append({"key": list(k), "from": om[k], "to": nm[k]})
    return {"added": added, "removed": removed, "modified": modified}


def _change_type(d, had_previous):
    if not had_previous:
        return "initial"
    kinds = [k for k, v in (("add_subrule", d["added"]), ("remove_subrule", d["removed"]),
                            ("modify_subrule", d["modified"])) if v]
    return kinds[0] if len(kinds) == 1 else "mixed"


def record(toelichting_by_rule, source=None, author="claude", when=None):
    """Snapshot the current rules into a new version. Writes a row per rule that CHANGED vs its last
    snapshot (or every rule if no history yet). toelichting_by_rule: {rule_number: text}. Returns the
    new version int, or the existing max version if nothing changed (no-op)."""
    rules = current_rules()
    conn = brain()
    stamp = when or datetime.now()
    with conn.cursor() as c:
        c.execute("SELECT COALESCE(MAX(version), 0) v FROM rules_history")
        maxv = c.fetchone()["v"]
    version = maxv + 1
    wrote = 0
    with conn.cursor() as c:
        for rn in sorted(rules):
            prev_v, prev_snap = _last(conn, rn)
            snap = rules[rn]
            d = _diff(prev_snap, snap)
            if prev_snap is not None and not (d["added"] or d["removed"] or d["modified"]):
                continue  # rule unchanged this version — don't duplicate
            c.execute(
                "INSERT INTO rules_history (version, changed_at, rule_number, change_type, snapshot, "
                "diff, toelichting, source, author, created_at, updated_at) "
                "VALUES (%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s)",
                (version, stamp, rn, _change_type(d, prev_snap is not None),
                 json.dumps(snap, default=str), json.dumps(d, default=str),
                 toelichting_by_rule.get(rn), source, author, stamp, stamp))
            wrote += 1
    conn.commit()
    conn.close()
    if wrote == 0:
        print(f"rules_history: niets veranderd — geen nieuwe versie (blijft v{maxv})")
        return maxv
    print(f"rules_history: versie {version} weggeschreven ({wrote} gewijzigde rule(s), source={source})")
    return version


def show(rule=None):
    conn = brain()
    with conn.cursor() as c:
        if rule:
            c.execute("SELECT * FROM rules_history WHERE rule_number=%s ORDER BY version, rule_number", (rule,))
        else:
            c.execute("SELECT * FROM rules_history ORDER BY version, rule_number")
        rows = c.fetchall()
    conn.close()
    for r in rows:
        d = json.loads(r["diff"]) if r["diff"] else {}
        n_add, n_rem, n_mod = len(d.get("added", [])), len(d.get("removed", [])), len(d.get("modified", []))
        snap = json.loads(r["snapshot"])
        print(f"v{r['version']} · {r['changed_at']} · rule {r['rule_number']} · {r['change_type']} "
              f"(+{n_add}/-{n_rem}/~{n_mod}) · {len(snap)} subrules · {r['source']}/{r['author']}")
        if r["toelichting"]:
            print(f"      {r['toelichting']}")
        for a in d.get("added", []):
            print(f"      + {a['indicator']}/{a['subrulename']}/lb{a['def1_value']} [{a['b_min']},{a['b_max']}]")
        for rm in d.get("removed", []):
            print(f"      - {rm['indicator']}/{rm['subrulename']}/lb{rm['def1_value']}")


if __name__ == "__main__":
    cmd = sys.argv[1] if len(sys.argv) > 1 else "show"
    if cmd == "show":
        show(int(sys.argv[2]) if len(sys.argv) > 2 else None)
    elif cmd == "snapshot":
        record({}, source="manual-checkpoint")
    else:
        sys.exit("usage: rules_history.py [show [rule] | snapshot]")
