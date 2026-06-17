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

from db import brain
from config import FORWARD_MINUTES
from sell_rule101 import rule_engine_101
from sell_lock import parse_sl, lock_profit


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

    def best_sell_in_window(self, buy_dt, buy, until_dt=None, minutes=None):
        """The highest reachable sell for THIS trade — may lie after our own exit, but is cut off at
        the NEXT buy (until_dt): a rally after a dip+rebuy belongs to the next trade, not this one.
        Window = [buy, min(buy+minutes, until_dt)). Returns dict(price, datetime, profit_pct) or None.
        NB: this is the first-order "highest before the next trade"; the hard-drop refinement (sell
        before a sharp drop within the window) is for the tuning/analysis mode."""
        minutes = FORWARD_MINUTES if minutes is None else minutes
        i = bisect.bisect_right(self.DT, buy_dt)
        best_px, best_dt = None, None
        while i < len(self.DT) and (self.DT[i] - buy_dt).total_seconds() <= minutes * 60:
            if until_dt is not None and self.DT[i] >= until_dt:
                break               # next buy reached — its move belongs to the next trade
            if best_px is None or self.PX[i] > best_px:
                best_px, best_dt = self.PX[i], self.DT[i]
            i += 1
        if best_px is None:
            return None
        return dict(price=best_px, datetime=best_dt, profit_pct=round((best_px - buy) / buy * 100, 3))

    def _determine_stop(self, buy, market, minutes, profit, hi, stop_prev, sl, i, buy_dt, max_price):
        """Per-tick stop decision → dict(stop, selling_price, orderstatus, floor, lock, mult).
        floor = absolute minimum (min_sl1*buy); lock = lock_profit ratchet output (None if a CHECK
        fired first); mult = rule-101 multiplier (None if empty / not reached)."""
        R, MULT = self.ROUNDING, self.SELL_MULT
        floor_price = round(buy * sl["min_sl1"], R)

        def check_sell():           # a CHECK-driven sell: lock/rule-101 were not reached
            return dict(stop=market, selling_price=round(market * MULT, R), orderstatus="sell",
                        floor=floor_price, lock=None, mult=None)

        if market < floor_price:                                            # CHECK 1 — absolute floor
            return check_sell()
        if minutes >= 5:                                                    # CHECK 2 — age/profit ladder
            for m, tp in sl["array_profit"]:
                if minutes > m and profit < tp:
                    return check_sell()
        if stop_prev is not None and stop_prev > market:                   # CHECK 3 — trailing breach
            return check_sell()

        lock = lock_profit(profit, minutes, hi, buy, market, sl)           # lock ratchet
        os101, mult = rule_engine_101(self.DT, self.PX, self.VV, i, buy_dt, buy, market, self.SUBRULES_101, max_price)
        new_stop = (mult * market) if mult != "" else lock
        if mult != "" and lock > mult * market and os101 != "overrule":    # lock wins unless overrule
            new_stop = lock
        new_stop = round(new_stop, R)
        orderstatus = "sell" if os101 == "sell" else "hold"
        if new_stop > market:                                              # implied stop above price → exit
            orderstatus, new_stop = "sell", market
        if new_stop < floor_price:                                         # floor clamp
            new_stop = floor_price
        return dict(stop=new_stop, selling_price=round(new_stop * MULT, R), orderstatus=orderstatus,
                    floor=floor_price, lock=lock, mult=(None if mult == "" else mult))

    def sell(self, buy_dt, buy, rule, trace=False):
        """Return dict(selling_price, selling_date, profit_loss, hi, lo, hi_price, hi_dt[, ticks]) or None.
        hi_price/hi_dt = the best price (and its time) reachable WITHIN our hold [buy, our sell] —
        the favorable excursion the sell-engine left on the table. trace=True adds 'ticks': the full
        per-minute trail (one dict per tick) for storage in coin_sell_ticks / analysis."""
        sl = self.sl_by_rule.get(int(rule), parse_sl(None))
        i0 = i = bisect.bisect_right(self.DT, buy_dt)
        stop_prev, hi, lo, max_price, hi_dt = None, 0.0, 0.0, buy, buy_dt
        ticks = [] if trace else None
        while i < len(self.DT) and (self.DT[i] - buy_dt).total_seconds() <= FORWARD_MINUTES * 60:
            T, market = self.DT[i], self.PX[i]
            minutes = (T - buy_dt).total_seconds() / 60.0
            profit = round((market - buy) / buy * 100, 2)
            hi = max(hi, profit); lo = min(lo, profit)
            if market > max_price:
                max_price, hi_dt = market, T
            breach = stop_prev is not None and market < stop_prev
            d = self._determine_stop(buy, market, minutes, profit, hi, stop_prev, sl, i, buy_dt, max_price)
            stop, selling_price, orderstatus = d["stop"], d["selling_price"], d["orderstatus"]
            sold = orderstatus == "sell" or breach
            if breach:                                          # prior-stop breach: sell at the trailed stop
                stop = market if stop > market else stop_prev
                selling_price = stop
            if trace:
                ticks.append(dict(tick_dt=T, minutes=round(minutes, 2), marketprice=market, profit=profit,
                                  highest_profit=hi, minimum_price=d["floor"], lock_price=d["lock"],
                                  rule101_mult=d["mult"], stoploss_price=stop, selling_price=selling_price,
                                  orderstatus=("sell" if sold else "hold")))
            if sold:
                pl = round((selling_price - buy) / buy * 100, 3)
                out = dict(selling_price=selling_price, selling_date=T, profit_loss=pl, hi=hi, lo=lo,
                           hi_price=max_price, hi_dt=hi_dt)
                if trace:
                    out["ticks"] = ticks
                return out
            stop_prev = stop
            i += 1
        # force-exit at the horizon (max 1-hour hold) at the last seen price
        if i > i0:
            T, market = self.DT[i - 1], self.PX[i - 1]
            pl = round((market * self.SELL_MULT - buy) / buy * 100, 3)
            out = dict(selling_price=round(market * self.SELL_MULT, self.ROUNDING), selling_date=T,
                       profit_loss=pl, hi=hi, lo=lo, hi_price=max_price, hi_dt=hi_dt)
            if trace:
                out["ticks"] = ticks
            return out
        return None
