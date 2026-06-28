#!/usr/bin/env python3
"""
regime — de "is deze munt op dit moment actief?"-helper (Epic H, Feature 2/3). Eén bron voor:
 - de FILTER die inactieve-periode-trades wegfiltert in de optimalisatie-loaders en de schermen;
 - de regime-VERSIE die in elke cache/fingerprint downstream van load_trades moet (beslissing #8),
   zodat een oude cache het filter niet maskeert.

Filter-semantiek: een trade telt mee TENZIJ hij in een als 'inactive' gemarkeerd interval valt. Een
munt zónder coin_regime-rijen (net ingeladen, regime nog niet berekend) telt dus VOLLEDIG mee
(default actief) — anders zou een verse munt 0 trades aan de optimalisatie geven. We filteren dus
"weg wat expliciet dood is", niet "alleen wat expliciet actief is".

De intervalgrens: period_to is de ZONDAG (een DATE). Een trade-datetime op die zondag ná middernacht
moet nog in het interval vallen, dus we vergelijken `dt >= period_from AND dt < period_to + 1 dag`
(period_from 00:00 inclusief t/m period_to 23:59:59 inclusief) — niet BETWEEN (dat zou de zondag-uren
van de laatste dag afkappen).
"""
from db import brain

_INACTIVE_EXISTS = (
    "EXISTS (SELECT 1 FROM coin_regime cr WHERE cr.trading_symbol_id={sym} "
    "AND cr.state='inactive' AND {dt} >= cr.period_from AND {dt} < cr.period_to + INTERVAL 1 DAY)"
)


def active_sql_clause(sym_col="coin_fires.trading_symbol_id", dt_col="coin_fires.datetime"):
    """Herbruikbare WHERE-snippet (zonder leidend AND): waar voor trades die NIET in een inactief
    interval vallen. Gebruik: f\"... WHERE ... AND {active_sql_clause('cf.trading_symbol_id','cf.datetime')}\".

    LET OP — sym_col en dt_col MOETEN volledig gekwalificeerd zijn naar de OUTER query (tabel.kolom of
    alias.kolom). `coin_regime` heeft ZELF een kolom `trading_symbol_id`, dus een ongekwalificeerde naam
    in de gecorreleerde subquery bindt aan coin_regime (cr) i.p.v. de outer tabel -> de correlatie breekt
    (cr.trading_symbol_id = cr.trading_symbol_id is altijd waar) en dan filtert de clause op "is ENIGE munt
    op dat moment inactief", wat bijna alles wegfiltert. De default mikt op de gangbare outer `FROM coin_fires`.
    sym_col/dt_col zijn vaste kolomnamen uit de aanroepende code (geen user-input) -> geen injectie-risico."""
    return "NOT " + _INACTIVE_EXISTS.format(sym=sym_col, dt=dt_col)


def is_active(sym, dt, conn=None):
    """True tenzij (sym, dt) in een inactief interval valt. dt = datetime of 'YYYY-MM-DD HH:MM:SS'.
    Een munt zonder rijen -> geen inactief interval -> True (default actief)."""
    own = conn is None
    conn = conn or brain()
    try:
        with conn.cursor() as c:
            c.execute("SELECT 1 FROM coin_regime WHERE trading_symbol_id=%s AND state='inactive' "
                      "AND %s >= period_from AND %s < period_to + INTERVAL 1 DAY LIMIT 1", (sym, dt, dt))
            return c.fetchone() is None
    finally:
        if own:
            conn.close()


def _inactive_intervals(conn=None):
    """{sym: [(period_from, period_to), ...]} van de inactieve intervallen — voor de pandas-filter."""
    own = conn is None
    conn = conn or brain()
    try:
        with conn.cursor() as c:
            c.execute("SELECT trading_symbol_id sym, period_from, period_to FROM coin_regime "
                      "WHERE state='inactive'")
            out = {}
            for r in c.fetchall():
                out.setdefault(r["sym"], []).append((r["period_from"], r["period_to"]))
            return out
    finally:
        if own:
            conn.close()


def active_filter(df, sym_col="sym", dt_col="datetime", conn=None):
    """Filter een pandas DataFrame: hou alleen rijen die NIET in een inactief interval vallen.
    Zelfde semantiek als active_sql_clause. Geeft een nieuwe (gefilterde) DataFrame."""
    import pandas as pd
    if df.empty:
        return df
    intervals = _inactive_intervals(conn)
    if not intervals:
        return df
    dt = pd.to_datetime(df[dt_col])
    drop = pd.Series(False, index=df.index)
    for sym, ivs in intervals.items():
        m = df[sym_col] == sym
        if not m.any():
            continue
        for frm, to in ivs:
            to_excl = pd.Timestamp(to) + pd.Timedelta(days=1)   # period_to inclusief de hele zondag
            drop |= m & (dt >= pd.Timestamp(frm)) & (dt < to_excl)
    return df[~drop]


# --- Cache-versie (beslissing #8) -------------------------------------------------------------------
# De regime-versie die in elke cache/fingerprint downstream van load_trades moet. INVARIANT onder een
# idempotente herberekening met dezelfde uitkomst (GEEN computed_at!) -> een wekelijkse re-run met
# ongewijzigde intervallen triggert geen nutteloze cache-invalidatie of keten-rerun; alleen een ECHTE
# interval-wijziging doet dat. Zelfde filosofie als input_fingerprint zelf (invariant onder de
# idempotente DELETE+INSERT-refire). CRC32-SUM is orde-onafhankelijk (SUM, niet XOR — XOR cancelt
# een ruil tussen twee gelijk-lange strings, zie test_opt_lib).

def regime_ver(sym, conn=None):
    """Per-munt checksum van de intervallen (period_from|period_to|state). Voor _long_fingerprint."""
    own = conn is None
    conn = conn or brain()
    try:
        with conn.cursor() as c:
            c.execute("SELECT COALESCE(SUM(CRC32(CONCAT(period_from,'|',period_to,'|',state))),0) v "
                      "FROM coin_regime WHERE trading_symbol_id=%s", (sym,))
            return int(c.fetchone()["v"])
    finally:
        if own:
            conn.close()


def regime_ver_global(conn=None):
    """(count, checksum) over alle intervallen. Voor input_fingerprint (de data-veranderd-gate)."""
    own = conn is None
    conn = conn or brain()
    try:
        with conn.cursor() as c:
            c.execute("SELECT COUNT(*) n, COALESCE(SUM(CRC32(CONCAT(trading_symbol_id,'|',period_from,"
                      "'|',period_to,'|',state))),0) cx FROM coin_regime")
            r = c.fetchone()
            return int(r["n"]), int(r["cx"])
    finally:
        if own:
            conn.close()
