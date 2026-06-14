#!/usr/bin/env python3
"""
The tuned precision subrules we ADDED to the main rules (source='tuned-precision'), version-
controlled and idempotent so a legacy re-seed never loses them. Each was validated out-of-sample
(validate_subrules.py: 100% of held-out good trades kept) and its threshold sits at the BAD EDGE
(brain-rule-tuning principle 2). One subrule per rule (principle 1).

Result (best_upside class, executed): rule 21 ratio 0.66->0.72, rule 22 1.01->1.14, rule 23
0.89->1.07 — 0 good trades lost. Writes brain.rules only.
Usage: add_tuned_subrules.py
"""
from db import brain

# (rule, indicator, subrulename, lookback, b_min, b_max)
TUNED = [
    (22, "obv-x-value", "volatility", 13, None, 0.1295),                    # voorkomt ~14 slechte
    (21, "mfi", "last_value", 13, None, 61.6),                              # voorkomt ~11 slechte
    (23, "vzo", "sum_average_positive_percentage", 16, None, 20.61),        # voorkomt ~7 slechte
]

b = brain()
with b.cursor() as c:
    c.execute("DELETE FROM rules WHERE source='tuned-precision'")
    for rule, ind, name, lb, bmin, bmax in TUNED:
        c.execute("SELECT COALESCE(MAX(sort),0)+1 s FROM rules WHERE rule_number=%s", (rule,))
        sort = c.fetchone()["s"]
        c.execute("INSERT INTO rules (rule_number, sort, indicator, subrulename, def1_value, b_min, b_max, "
                  "active, source, created_at, updated_at) VALUES (%s,%s,%s,%s,%s,%s,%s,1,'tuned-precision',NOW(),NOW())",
                  (rule, sort, ind, name, lb, bmin, bmax))
    b.commit()
    c.execute("SELECT rule_number, subrulename, indicator, def1_value, b_max FROM rules "
              "WHERE source='tuned-precision' ORDER BY rule_number")
    for r in c.fetchall():
        print(f"  rule {r['rule_number']}: {r['subrulename']} van {r['indicator']} lb{int(r['def1_value'])} <= {r['b_max']}")
b.close()
