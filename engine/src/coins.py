#!/usr/bin/env python3
"""
coins.py — centrale bron voor het muntenuniverse. Eén plek, zodat optimize/sell/discovery automatisch
meegaan zodra een coin via `import_indicators.py` wordt ingeladen — geen code-edit meer per nieuwe munt.

`active_coins()` levert de coins uit `brain.coins` die OOK indicator-data hebben (anders heeft optimize
geen werk te doen). Volgorde: chronologische onboarding (id asc op `coins.created_at`-fallback, dan id).

Caching: één query per proces (lichtgewicht, ~tien rijen); call `refresh()` om een nieuwe coin op te
pikken zonder herstart (zelden nodig).
"""
from db import brain

_cache = None


def active_coins():
    """Lijst [(symbol_id, name), ...] van alle coins met indicator-data in brain.indicators.
    Eén plek, gedeeld door opt_lib / sell_apply / sell_tuning / discovery."""
    global _cache
    if _cache is not None:
        return _cache
    with brain().cursor() as c:
        # alleen coins met daadwerkelijke indicator-data; nieuwe coin verschijnt automatisch
        # zodra import_indicators.py is gedraaid (geen code-edit).
        c.execute("SELECT c.id, c.symbol FROM coins c "
                  "WHERE EXISTS (SELECT 1 FROM indicators i WHERE i.trading_symbol_id=c.id) "
                  "ORDER BY COALESCE(c.created_at, NOW()), c.id")
        _cache = [(r["id"], r["symbol"]) for r in c.fetchall()]
    return _cache


def active_coin_ids():
    return [s for s, _ in active_coins()]


def optimize_coin_ids():
    """De coins die de rule-precision OPTIMIZE-keten gebruikt (zoekruimte + LOO cross-coin validatie).
    Default: DOGEAI + NOS (snelle dagelijkse run; LOO over 4 coins maakte rq1_tighten onpraktisch traag,
    5u zonder resultaat). Override via env var OPTIMIZE_COINS=2525,244,2735,8427 voor een diepe sweep.
    Discovery/sell-tuning blijven ongelimiteerd via active_coin_ids() — de bottleneck zat alleen in de
    rule-precision LOO."""
    import os
    env = os.environ.get("OPTIMIZE_COINS")
    if env:
        return [int(x) for x in env.split(",") if x.strip().isdigit()]
    return [2525, 244]


def refresh():
    """Forceer een verse query (na het inladen van een nieuwe coin in dezelfde proces-levensduur)."""
    global _cache
    _cache = None


# B3: coin-age-class — onderscheid statische (historische) coins van actieve (groeiende) coins. Drijft
# routines die per coin beslissen of een dure stap (refire/cache) überhaupt zin heeft. A1's
# fingerprint-skip vangt de "static + ongewijzigde rules" gevallen al impliciet; deze helper geeft de
# semantische context: bij een rule-wijziging die ALLE coins triggert kun je op static coins andere
# keuzes maken (bv. lichtere refire, of een sneller pad zonder sell_promising).
_STATIC_DAYS = 30


def coin_age_class(symbol_id):
    """Geef 'static' (>30 dagen geen nieuwe indicator-tick), 'active' (recente data) of 'unknown'."""
    with brain().cursor() as c:
        c.execute("SELECT TIMESTAMPDIFF(DAY, MAX(datetime), NOW()) days_since "
                  "FROM indicators WHERE trading_symbol_id=%s", (symbol_id,))
        r = c.fetchone()
    if not r or r.get("days_since") is None:
        return "unknown"
    return "static" if int(r["days_since"]) > _STATIC_DAYS else "active"


def active_market_coin_ids():
    """De coins die nog 'leven' (data groeit). Voor routines die alleen op verse coins moeten draaien."""
    return [s for s in active_coin_ids() if coin_age_class(s) == "active"]


if __name__ == "__main__":
    for s, n in active_coins():
        print(f"  {s:>5d}  {n}")
