"""
Shared sell-side trailing-floor logic — the lock_profit ratchet, ported byte-for-byte from
legacy functions_br.php:4744 (lock_profit). Pure functions, no DB, so BOTH the oracle validator
(validate_sell.py, reads bot_signals) and the production engine (sell_engine.py, reads brain) use
the SAME arithmetic. See docs/methodology/selling-process.md §2.4.

The ratchet trails the stop up as the trade's PEAK profit (highest_profit_loss, in percent) grows.
This is the piece the old 87% version left inert; turning it on lifted win/loss agreement 80%->95%.
All knobs read from the strategy's SL_settings JSON with legacy defaults, so they are configurable.
"""
import json


def parse_sl(raw):
    """Parse a strategy's SL_settings JSON into the sell-engine knobs (with legacy defaults)."""
    s = json.loads(raw) if raw else {}
    g = lambda *ks, d=None: next((float(s[k]) for k in ks if k in s and s[k] not in (None, "")), d)
    ap = s.get("array_profit")
    return {
        "min_sl1": g("min_sl1", "min_sl", d=0.988),
        "min1": g("minutes_in_trade1", "minutes_in_trade", d=6),
        "min_sl2": g("min_sl2", d=g("min_sl1", "min_sl", d=0.99)),
        "min2": g("minutes_in_trade2", d=15),
        "minimal_profit": g("minimal_profit", d=0.8),
        # highest-profit ratchet (lock_profit). Defaults = legacy functions_br.php:4779-4787.
        "hp1": g("hp_setting1", d=-0.003), "hp2": g("hp_setting2", d=-0.002),
        "hp3": g("hp_setting3", d=-0.0015), "hp4": g("hp_setting4", d=0.001),
        "hp5": g("hp_setting5", d=0.001), "hp6": g("hp_setting6", d=4.0),
        "hp7": g("hp_setting7", d=15.0),    # legacy hard-overrides JSON to 15; default keeps that
        # CHECK-2 age/profit ladder (configurable; default = legacy hardcoded array)
        "array_profit": [[float(m), float(t)] for m, t in ap] if ap else [[5, -0.4], [7, -0.1], [8, 0.0], [20, 0.5]],
    }


def lock_profit(profit, minutes, hi, buy, market, sl):
    """The trailing floor (legacy lock_profit). hi = highest_profit_loss in PERCENT, incl. the
    current tick. First match wins; the ratchet trails the stop up as the trade's peak grows."""
    if market < buy * sl["min_sl1"]:                                   # gate: below hard floor
        return market * 0.9999
    if minutes < sl["min1"] and profit < sl["minimal_profit"]:         # young + not-yet-in-profit
        return buy * sl["min_sl1"]
    if minutes < sl["min2"] and profit < sl["minimal_profit"]:         # mid + not-yet-in-profit
        return buy * sl["min_sl2"]
    if hi >= 0.15:                                                     # HIGHEST-PROFIT RATCHET
        if hi < 0.21:  return buy + sl["hp1"] * buy
        if hi < 0.30:  return buy + sl["hp2"] * buy
        if hi < 0.40:  return buy + sl["hp3"] * buy
        if hi < 0.50:  return buy + sl["hp4"] * buy
        if hi < 0.70:  return buy + sl["hp5"] * buy
        if hi < 5:     return buy + ((hi / sl["hp6"]) / 100) * buy     # save ~25% of peak
        return buy + ((hi - sl["hp7"]) / 100) * buy                    # save ~50% of peak
    return buy * sl["min_sl1"]                                         # fallback (hi<0.15, past gates)
