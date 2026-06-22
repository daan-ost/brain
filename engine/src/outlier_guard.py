#!/usr/bin/env python3
"""
Outlier-guard voor prijs-ticks in brain.indicators.

Eén corrupte feed-tick (een decimaal-/schaal-glitch — bv. price=23044 waar de echte prijs
~0,02304 is) veroorzaakt downstream absurde winst: de sell-engine scant ~60 min vooruit naar
de hoogste verkoopprijs en "verkoopt" elk koop-moment in dat venster tegen die ene rotte tick.
Resultaat: profit_loss van miljoenen procenten. De engine doet niets fout (garbage in, garbage
out); dit hoort bij de datakwaliteit.

Deze guard markeert een tick als ongeldig als zijn price meer dan een factor OUTLIER_FACTOR
afwijkt van de mediaan van zijn directe buren. De mediaan-van-buren is robuust tegen zowel één
losse uitschieter (de outlier zelf telt niet mee) ALS tegen een legitieme koers-trend over de
hele reeks (een wereldwijde mediaan zou een coin die echt 10x stijgt ten onrechte snoeien).

Defense-in-depth — op twee plekken gebruikt:
 1. bij ingest (import_indicators.py): zet price=NULL voor outlier-ticks in brain.indicators,
    zodat ALLES downstream (sell-engine, best_upside, promising) automatisch correct is. Een
    re-import kopieert de tick opnieuw uit legacy, maar de guard verwijdert hem meteen weer —
    daarom is ingest de LEIDENDE plek (zie skill brain-sell-engine).
 2. in SellEngine.__init__: laat outlier-ticks weg uit de prijsreeks (PX), voor het geval een
    tick toch ongezuiverd in de DB staat.

bot_signals (legacy) wordt NOOIT aangeraakt; we corrigeren alleen de brain-kopie.
"""
import pymysql

# Een price-tick die meer dan deze factor boven/onder de mediaan van zijn buren ligt is een
# feed-glitch, geen echte koersbeweging. 10x zit ruim boven elke realistische tick-op-tick
# beweging (zelfs een heftige pump verdubbelt hooguit), maar pakt de schaal-glitch (~1e6x) zeker.
OUTLIER_FACTOR = 10.0
# Aantal buren aan elke kant voor de robuuste lokale mediaan (de tick zelf telt niet mee).
OUTLIER_WINDOW = 5


def _local_median(prices, i, window):
    """Mediaan van de geldige (positieve, niet-None) buren van prices[i], exclusief i zelf."""
    lo = max(0, i - window)
    hi = min(len(prices), i + window + 1)
    nb = sorted(prices[j] for j in range(lo, hi)
                if j != i and prices[j] is not None and prices[j] > 0)
    if not nb:
        return None
    return nb[len(nb) // 2]


def is_price_outlier(prices, i, window=OUTLIER_WINDOW, factor=OUTLIER_FACTOR):
    """True als prices[i] meer dan `factor`x afwijkt van de mediaan van zijn buren."""
    p = prices[i]
    if p is None or p <= 0:
        return False
    m = _local_median(prices, i, window)
    if not m or m <= 0:
        return False
    return p > m * factor or p < m / factor


def outlier_indices(prices, window=OUTLIER_WINDOW, factor=OUTLIER_FACTOR):
    """Indexen in `prices` die volgens de guard een feed-glitch zijn."""
    return [i for i in range(len(prices)) if is_price_outlier(prices, i, window, factor)]


def filter_outliers(DT, PX, VV, window=OUTLIER_WINDOW, factor=OUTLIER_FACTOR):
    """Verwijder outlier-ticks uit de drie parallelle reeksen (datetime/price/value).
    Returnt (DT, PX, VV, n_dropped). Gebruikt door SellEngine als laatste vangnet."""
    bad = set(outlier_indices(PX, window, factor))
    if not bad:
        return DT, PX, VV, 0
    keep = [i for i in range(len(PX)) if i not in bad]
    return [DT[i] for i in keep], [PX[i] for i in keep], [VV[i] for i in keep], len(bad)


def null_price_outliers(conn, symbols, indicators, window=OUTLIER_WINDOW, factor=OUTLIER_FACTOR):
    """Zet price=NULL voor outlier-ticks per (symbol, indicator) in brain.indicators.

    Leest+schrijft ALLEEN de brain-connectie `conn` (nooit bot_signals). Idempotent: een tweede
    run vindt niets meer omdat de outliers al NULL zijn (en NULL telt niet mee als buur-mediaan).
    Returnt {(symbol, indicator): n_nulled}.
    """
    out = {}
    # Expliciet tuple-cursor: import_indicators gebruikt een default (tuple) cursor, dus we mogen
    # niet op dict-keys leunen.
    with conn.cursor(pymysql.cursors.Cursor) as c:
        for sym in symbols:
            for ind in indicators:
                c.execute("SELECT id, price FROM indicators "
                          "WHERE trading_symbol_id=%s AND indicator=%s AND price IS NOT NULL "
                          "ORDER BY datetime, id", (sym, ind))
                rows = c.fetchall()                      # [(id, price), ...]
                prices = [float(p) for (_id, p) in rows]
                bad = outlier_indices(prices, window, factor)
                if not bad:
                    continue
                ids = [rows[i][0] for i in bad]
                fmt = ",".join(["%s"] * len(ids))
                c.execute(f"UPDATE indicators SET price=NULL WHERE id IN ({fmt})", ids)
                out[(sym, ind)] = len(ids)
    return out
