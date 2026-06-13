#!/bin/bash
# Export a READ-ONLY slice of bot_signals for the rule-filter PoC.
# bot_signals is NEVER written to — SELECT only. (See brain CLAUDE.md / roadmap.)
#
# Usage: ./export_slice.sh [symbol_id] [rules_csv]
#   defaults: 2525 (DOGEAI 5m), "20,21"
set -euo pipefail

M="/Applications/MAMP/Library/bin/mysql80/bin/mysql"
DB=(-u root -proot -P 8889 -h 127.0.0.1 bot_signals --batch)
OUT="/Users/daanvantongeren/Documents/Sites/brain/engine/data"
SYM="${1:-2525}"
RULES="${2:-20,21}"
mkdir -p "$OUT"

echo "Exporting labeled trades (symbol=$SYM, rules=$RULES, result in 1,3)..."
"$M" "${DB[@]}" -e "SELECT ID, datetime, rule, result, price, profit_loss \
  FROM wp_trading_simulation \
  WHERE trading_symbol_id=$SYM AND rule IN ($RULES) AND result IN (1,3) \
  ORDER BY datetime" 2>/dev/null > "$OUT/trades.tsv"

echo "Exporting indicator series (symbol=$SYM, trade span + 6h pre-margin for lookback)..."
"$M" "${DB[@]}" -e "SELECT i.datetime, i.indicator, i.value \
  FROM wp_trading_indicator i \
  JOIN (SELECT MIN(datetime) mn, MAX(datetime) mx FROM wp_trading_simulation \
        WHERE trading_symbol_id=$SYM AND rule IN ($RULES) AND result IN (1,3)) s \
    ON i.datetime BETWEEN DATE_SUB(s.mn, INTERVAL 6 HOUR) AND s.mx \
  WHERE i.trading_symbol_id=$SYM \
  ORDER BY i.datetime" 2>/dev/null > "$OUT/indicators.tsv"

echo "Done:"
wc -l "$OUT/trades.tsv" "$OUT/indicators.tsv"
