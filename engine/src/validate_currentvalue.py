#!/usr/bin/env python3
"""
Step-1 validation probe — settle the as-of alignment empirically.

The owner's pain point: for a candidate datetime, do you include the value AT that
datetime, or only values strictly before it? Instead of guessing, we compute
`currentvalue` for rule 21's subrules at a known datetime and diff against the
legacy's own stored values in wp_trading_simulation_trades_indicator.

READ-ONLY on bot_signals. Tests two alignments: value at (datetime <= T) vs (datetime < T).
"""
import pymysql

SYM = 2525
RULE = 21
T = "2025-02-14 12:17:31"

db = pymysql.connect(host="127.0.0.1", port=8889, user="root", password="root",
                     database="bot_signals", cursorclass=pymysql.cursors.DictCursor)


def q(sql, args=None):
    with db.cursor() as c:
        c.execute(sql, args or ())
        return c.fetchall()


# rule 21 currentvalue subrules
subrules = q(
    "SELECT ID, indicator, b_min, b_max FROM wp_trading_rules "
    "WHERE rule_number=%s AND active=1 AND subrulename='currentvalue' ORDER BY sort",
    (RULE,),
)

# legacy stored values at T (the oracle)
stored = {r["rule_ID"]: r for r in q(
    "SELECT rule_ID, value, result_ok FROM wp_trading_simulation_trades_indicator "
    "WHERE trading_symbol_ID=%s AND rule_number=%s AND datetime=%s",
    (SYM, RULE, T),
)}


def last_value(indicator, op):
    rows = q(
        f"SELECT value FROM wp_trading_indicator "
        f"WHERE trading_symbol_id=%s AND indicator=%s AND datetime {op} %s AND value IS NOT NULL "
        f"ORDER BY datetime DESC LIMIT 1",
        (SYM, indicator, T),
    )
    return float(rows[0]["value"]) if rows else None


print(f"Validating currentvalue @ {T}  (symbol {SYM}, rule {RULE})")
print(f"{'subrule':>8} {'indicator':<14}{'stored':>12}{'<=T':>12}{'<T':>12}  match")
print("-" * 70)
for s in subrules:
    sid = s["ID"]
    st = stored.get(sid)
    sv = float(st["value"]) if st else None
    v_le = last_value(s["indicator"], "<=")
    v_lt = last_value(s["indicator"], "<")
    def close(a, b):
        return a is not None and b is not None and abs(a - b) < 1e-6
    tag = "<=T" if close(sv, v_le) else ("<T" if close(sv, v_lt) else "NEITHER")
    print(f"{sid:>8} {s['indicator']:<14}{('' if sv is None else f'{sv:.4f}'):>12}"
          f"{('' if v_le is None else f'{v_le:.4f}'):>12}{('' if v_lt is None else f'{v_lt:.4f}'):>12}  {tag}")
db.close()
