#!/usr/bin/env python3
"""
The tuned precision subrules we ADDED to the main rules (source='tuned-precision'), version-
controlled and idempotent so a legacy re-seed never loses them. Each was validated out-of-sample
(validate_subrules.py / combo_subrules.py: 100% of held-out good trades kept) and its threshold
sits at the BAD EDGE (brain-rule-tuning principle 2). Prefer one subrule per rule (principle 1);
rule 20 needed a PAIR because no single safe condition existed (combo_subrules.py).

Result (best_upside class, executed), cumulative over three rounds — 0 good trades lost throughout
(round 3 even +1 good: a good shadow promotes to executed as a rule fires less):
  rule 20  1.42->1.68->1.80   rule 21  0.66->0.72->0.77->0.83
  rule 22  1.01->1.14->1.19   rule 23  0.89->1.07->1.24
Round 3 = the report's RQ1 set (docs/optimization/2026-06-14-rule-set-optimization.md), engine-
validated over the full history of both coins. Net 17 executed bad removed (cross-rule dedup makes
the per-rule isolated counts non-additive). Writes brain.rules only.
Usage: add_tuned_subrules.py
"""
from db import brain

# (rule, indicator, subrulename, lookback, b_min, b_max)
TUNED = [
    (22, "obv-x-value", "volatility", 13, None, 0.1295),                    # voorkomt ~14 slechte
    (21, "mfi", "last_value", 13, None, 61.6),                              # voorkomt ~11 slechte
    (23, "vzo", "sum_average_positive_percentage", 16, None, 20.61),        # voorkomt ~7 slechte
    # tweede ronde: rule 21 een tweede veilige subrule; rule 20 een veilig PAAR
    # (geen enkelvoudige veilige conditie -> combinatie van twee, beide bad-edge, 0 goede verloren)
    (21, "mfi", "diff_previous_number", 4, None, 14.4),                     # rule 21 #2: dropt ~10 slechte
    (20, "vzo", "skewness", 13, None, 1.4173),                             # rule 20 paar-a: dropt ~5 slechte
    (20, "mfi", "diff_number_prev_min", 17, -22.3, None),                  # rule 20 paar-b: dropt ~4 slechte
    # derde ronde: de RQ1-set uit het optimalisatie-rapport (docs/optimization/2026-06-14-...).
    # Engine-gevalideerd over de VOLLEDIGE periode van beide coins (rq2_refire_check.py <rule> rq1):
    # 0 goede executed trades verloren, slecht weg per rule hieronder. ALLE volumeud-features hier zijn
    # PERCENTAGE/ratio-metrics (schaal-invariant) -> cache == engine, geen scale-mismatch. Zie de
    # kritieke-correctie in het rapport: volumeud LEVEL-metrics (median_value etc.) zijn verboden.
    (20, "vzo", "range_percentage", 17, -44.30233, None),                  # RQ1: dropt 5 slecht (4+1)
    (21, "volumeud", "diff_percentage_prev_max", 9, 158.83697, None),      # RQ1: dropt 8 slecht (6+2)
    (22, "volumeud", "range_percentage", 5, 15.17012, None),               # RQ1: dropt 6 slecht (3+3), herzien
    (23, "vzo", "diff_number_prev_min", 20, None, -1.2),                   # RQ1: dropt 5 slecht (3+2)
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

# record this rule-set state in the append-only history (snapshot + diff + per-rule toelichting)
import rules_history as _h

_TOELICHTING = {
    20: "Precisie: vzo/skewness lb13 + mfi/diff_number_prev_min lb17 (veilig paar) + "
        "RQ1 vzo/range_percentage lb17 >= -44.30. Ratio 1.42 -> 1.80, 0 goede verloren.",
    21: "Precisie: mfi/last_value lb13 + mfi/diff_previous_number lb4 + RQ1 "
        "volumeud/diff_percentage_prev_max lb9 >= 158.84 (schaal-invariant). Ratio 0.66 -> 0.83.",
    22: "Precisie: obv-x-value/volatility lb13 + RQ1 volumeud/range_percentage lb5 >= 15.17 "
        "(herzien; median_value verworpen wegens volumeud scale-mismatch). Ratio 1.01 -> 1.19.",
    23: "Precisie: vzo/sum_average_positive_percentage lb16 + RQ1 vzo/diff_number_prev_min lb20 "
        "<= -1.2. Ratio 0.89 -> 1.24, 0 goede verloren.",
}
_h.record(_TOELICHTING, source="tuned-precision", author="claude")
