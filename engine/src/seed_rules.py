#!/usr/bin/env python3
"""
Seed our brain.rules from the legacy wp_trading_rules (rules 20/21/22/23 + sell rule 101) as a
STARTING POINT. From here the rules are owned and tuned by us in brain; the engine reads them
from brain, not bot_signals. Also copies the per-coin min_volume into coin_rule_settings.

Idempotent: clears the seeded rules/settings first. Reads bot_signals (definitions only), writes brain.
Usage: seed_rules.py
"""
import pymysql

RULES = (20, 21, 22, 23, 101)
SYMS = (2525, 244)

conn = pymysql.connect(host="127.0.0.1", port=8889, user="root", password="root",
                       database="brain", cursorclass=pymysql.cursors.DictCursor, autocommit=True)
rule_list = ",".join(str(r) for r in RULES)
sym_list = ",".join(str(s) for s in SYMS)

with conn.cursor() as c:
    # only re-seed the legacy-seeded rows; keep our tuned-precision subrules (add_tuned_subrules.py)
    c.execute(f"DELETE FROM rules WHERE rule_number IN ({rule_list}) AND source='legacy-seed'")
    c.execute("DELETE FROM coin_rule_settings")

    # subrule definitions
    c.execute(f"""
        INSERT INTO rules (rule_number, sort, indicator, subrulename, def1_value, b_min, b_max,
                           value_condition, operator, condition_rule, active, source, legacy_id, created_at, updated_at)
        SELECT rule_number, sort, indicator, subrulename, def1_value, b_min, b_max,
               value_condition, operator, condition_rule, active, 'legacy-seed', ID, NOW(), NOW()
        FROM bot_signals.wp_trading_rules
        WHERE rule_number IN ({rule_list}) AND active=1
    """)
    print(f"subrules seeded: {c.rowcount}")

    # per-coin min_volume
    c.execute(f"""
        INSERT INTO coin_rule_settings (trading_symbol_id, rule_number, min_volume, created_at, updated_at)
        SELECT sr.trading_symbol_id, sr.rule_id,
               JSON_EXTRACT(sr.settings, '$.min_volume'), NOW(), NOW()
        FROM bot_signals.wp_trading_symbols_rule sr
        WHERE sr.trading_symbol_id IN ({sym_list}) AND sr.rule_id IN ({rule_list})
    """)
    print(f"coin_rule_settings seeded: {c.rowcount}")

    c.execute(f"SELECT rule_number, COUNT(*) n FROM rules WHERE rule_number IN ({rule_list}) GROUP BY rule_number ORDER BY rule_number")
    for r in c.fetchall():
        print(f"  rule {r['rule_number']}: {r['n']} subrules")
conn.close()
