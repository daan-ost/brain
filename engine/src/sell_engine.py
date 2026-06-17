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

from db import brain
from config import FORWARD_MINUTES
from sell_rule101 import rule_engine_101
from sell_lock import parse_sl, lock_profit


def merge_sl(raw_by_rule, ovr_by_rule):
    """Merge de per-coin override-laag (coin_strategies) over de globale strategy-JSON heen en parse.
    raw_by_rule/ovr_by_rule = {rule: sl_settings JSON-string of None}. Per-coin wint mits NOT NULL en
    erft de rest van de globale rule (merge op JSON-niveau vóór parse_sl). Lege override = byte-identiek
    aan de globale rule (faithful). Pure functie zonder DB — daarom los testbaar."""
    out = {}
    for rule, raw in raw_by_rule.items():
        ov = ovr_by_rule.get(rule)
        if ov:
            base = json.loads(raw) if raw else {}
            base.update(json.loads(ov))
            raw = json.dumps(base)
        out[rule] = parse_sl(raw)
    for rule, ov in ovr_by_rule.items():       # override op een rule zonder globale rij
        if rule not in out:
            out[rule] = parse_sl(ov)
    return out


class SellEngine:
    def __init__(self, symbol, conn=None):
        self.symbol = symbol
        self._own = conn is None
        self.conn = conn or brain()
        with self.conn.cursor() as c:
            c.execute("SELECT datetime, price, value FROM indicators WHERE trading_symbol_id=%s "
                      "AND indicator='volumeud' AND price IS NOT NULL ORDER BY datetime", (symbol,))
            rows = c.fetchall()
            # Dedup op datetime — sommige coins (NOS: 1397 stuks) hebben dubbele volumeud-rijen met
            # identieke prijs maar verschillende value. Een dubbele tick zou de natural-key in
            # coin_sell_ticks breken (datetime+tick_datetime). Eerste wint, latere wordt genegeerd.
            seen, self.DT, self.PX, self.VV = set(), [], [], []
            for r in rows:
                if r["datetime"] in seen:
                    continue
                seen.add(r["datetime"])
                self.DT.append(r["datetime"])
                self.PX.append(float(r["price"]))
                self.VV.append(float(r["value"]) if r["value"] is not None else 0.0)
            c.execute("SELECT stoploss_multiplier, roundingup FROM coins WHERE id=%s", (symbol,))
            coin = c.fetchone() or {}
            self.SELL_MULT = float(coin.get("stoploss_multiplier") or 0.9996)
            self.ROUNDING = int(coin.get("roundingup") or 16)
            c.execute("SELECT rule_number, sl_settings FROM strategies")
            raw_by_rule = {int(r["rule_number"]): r["sl_settings"] for r in c.fetchall()}
            # Per-coin override-laag (coin_strategies) bovenop de globale strategies — DOGEAI snel, NOS
            # traag hebben andere instelknoppen nodig (de tuning-routine). Merge op JSON-niveau VÓÓR
            # parse_sl: een override die maar een paar knobs zet erft de rest van de globale rule.
            # Per-coin wint mits NOT NULL. Lege coin_strategies = geen override = byte-identiek (faithful).
            c.execute("SELECT rule_number, sl_settings FROM coin_strategies WHERE trading_symbol_id=%s", (symbol,))
            ovr_by_rule = {int(r["rule_number"]): r["sl_settings"] for r in c.fetchall() if r["sl_settings"]}
            self.sl_by_rule = merge_sl(raw_by_rule, ovr_by_rule)
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

    def sell(self, buy_dt, buy, rule, trace=False, hard_sell_dt=None):
        """Return dict(selling_price, selling_date, profit_loss, hi, lo, hi_price, hi_dt[, ticks]) or None.
        hard_sell_dt = forced-sell-by-this-moment (handmatige override): de engine verkoopt op die
        datum (eerste tick op/na), OF eerder als een normale trigger eerst vuurt — laten lopen kan niet."""
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
            hard_hit = hard_sell_dt is not None and T >= hard_sell_dt
            if hard_hit and not sold:                           # harde verkoopdatum bereikt: forceer sell
                sold, stop, selling_price = True, market, round(market * self.SELL_MULT, self.ROUNDING)
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
