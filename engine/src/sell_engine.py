#!/usr/bin/env python3
"""
Our sell-engine, reading ONLY brain. Given a buy (datetime, price, rule) it walks the
volumeud price forward (max FORWARD_MINUTES = the 1-hour hold) and applies the stop-loss:
CHECK 1 absolute floor (min_sl1*buy), CHECK 2 age/profit ladder, CHECK 3 trailing breach,
else a stop from the min_sl floor combined with rule-101 sell signals. Faithful to
docs/methodology/selling-process.md (the ~87% version), now sourced from brain.

Reads brain.indicators (price), brain.strategies (SL settings), brain.coins (multiplier),
brain.rules (rule 101). bot_signals is not touched.
"""
import bisect
import json
from datetime import timedelta

from db import brain
from config import FORWARD_MINUTES
from sell_rule101 import rule_engine_101

AGE_LADDER = [(5, -0.4), (7, -0.1), (8, 0.0), (20, 0.5)]


def parse_sl(raw):
    s = json.loads(raw) if raw else {}
    g = lambda *ks, d=None: next((float(s[k]) for k in ks if k in s and s[k] not in (None, "")), d)
    return {
        "min_sl1": g("min_sl1", "min_sl", d=0.988),
        "min1": g("minutes_in_trade1", "minutes_in_trade", d=6),
        "min_sl2": g("min_sl2", d=g("min_sl1", "min_sl", d=0.99)),
        "min2": g("minutes_in_trade2", d=15),
        "minimal_profit": g("minimal_profit", d=0.8),
    }


class SellEngine:
    def __init__(self, symbol, conn=None):
        self.symbol = symbol
        self._own = conn is None
        self.conn = conn or brain()
        with self.conn.cursor() as c:
            c.execute("SELECT datetime, price, value FROM indicators WHERE trading_symbol_id=%s "
                      "AND indicator='volumeud' AND price IS NOT NULL ORDER BY datetime", (symbol,))
            rows = c.fetchall()
            self.DT = [r["datetime"] for r in rows]
            self.PX = [float(r["price"]) for r in rows]
            self.VV = [float(r["value"]) if r["value"] is not None else 0.0 for r in rows]
            c.execute("SELECT stoploss_multiplier, roundingup FROM coins WHERE id=%s", (symbol,))
            coin = c.fetchone() or {}
            self.SELL_MULT = float(coin.get("stoploss_multiplier") or 0.9996)
            self.ROUNDING = int(coin.get("roundingup") or 16)
            c.execute("SELECT rule_number, sl_settings FROM strategies")
            self.sl_by_rule = {int(r["rule_number"]): parse_sl(r["sl_settings"]) for r in c.fetchall()}
            c.execute("SELECT id AS ID, sort, subrulename, def1_value, b_min, b_max, operator, "
                      "value_condition, condition_rule FROM rules WHERE rule_number=101 AND active=1 ORDER BY sort, id")
            self.SUBRULES_101 = c.fetchall()

    def close(self):
        if self._own:
            self.conn.close()

    def _lock_floor(self, profit, minutes, buy, market, sl):
        if market < buy * sl["min_sl1"]:
            return market * 0.9999
        if minutes < sl["min1"] and profit < sl["minimal_profit"]:
            return buy * sl["min_sl1"]
        if minutes < sl["min2"] and profit < sl["minimal_profit"]:
            return buy * sl["min_sl2"]
        return buy * sl["min_sl1"]

    def _determine_stop(self, buy, market, minutes, profit, stop_prev, sl, i, buy_dt, max_price):
        R, MULT = self.ROUNDING, self.SELL_MULT
        floor_price = round(buy * sl["min_sl1"], R)
        if market < floor_price:                                            # CHECK 1
            return market, round(market * MULT, R), "sell"
        if minutes >= 5:                                                    # CHECK 2
            for m, tp in AGE_LADDER:
                if minutes > m and profit < tp:
                    return market, round(market * MULT, R), "sell"
        if stop_prev is not None and stop_prev > market:                   # CHECK 3
            return market, round(market * MULT, R), "sell"

        lock = self._lock_floor(profit, minutes, buy, market, sl)
        os101, mult = rule_engine_101(self.DT, self.PX, self.VV, i, buy_dt, buy, market, self.SUBRULES_101, max_price)
        new_stop = (mult * market) if mult != "" else lock
        if mult != "" and lock > mult * market and os101 != "overrule":
            new_stop = lock
        new_stop = round(new_stop, R)
        orderstatus = "sell" if os101 == "sell" else "hold"
        if new_stop > market:
            orderstatus, new_stop = "sell", market
        if new_stop < floor_price:
            return floor_price, round(floor_price * MULT, R), orderstatus
        return new_stop, round(new_stop * MULT, R), orderstatus

    def sell(self, buy_dt, buy, rule):
        """Return dict(selling_price, selling_date, profit_loss, hi, lo) or None (no sell in window)."""
        sl = self.sl_by_rule.get(int(rule), parse_sl(None))
        i = bisect.bisect_right(self.DT, buy_dt)
        stop_prev, hi, lo, max_price = None, 0.0, 0.0, buy
        while i < len(self.DT) and (self.DT[i] - buy_dt).total_seconds() <= FORWARD_MINUTES * 60:
            T, market = self.DT[i], self.PX[i]
            minutes = (T - buy_dt).total_seconds() / 60.0
            profit = round((market - buy) / buy * 100, 2)
            hi = max(hi, profit); lo = min(lo, profit)
            max_price = max(max_price, market)
            breach = stop_prev is not None and market < stop_prev
            stop, selling_price, orderstatus = self._determine_stop(
                buy, market, minutes, profit, stop_prev, sl, i, buy_dt, max_price)
            if orderstatus == "sell" or breach:
                if breach:
                    stop = market if stop > market else stop_prev
                    selling_price = stop
                pl = round((selling_price - buy) / buy * 100, 3)
                return dict(selling_price=selling_price, selling_date=T, profit_loss=pl, hi=hi, lo=lo)
            stop_prev = stop
            i += 1
        # force-exit at the horizon (max 1-hour hold) at the last seen price
        if i > bisect.bisect_right(self.DT, buy_dt):
            T, market = self.DT[i - 1], self.PX[i - 1]
            pl = round((market * self.SELL_MULT - buy) / buy * 100, 3)
            return dict(selling_price=round(market * self.SELL_MULT, self.ROUNDING), selling_date=T,
                        profit_loss=pl, hi=hi, lo=lo)
        return None
