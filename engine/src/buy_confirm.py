#!/usr/bin/env python3
"""
Koop-bevestiging (futureprice / futureprice_x_rows) — bootst de legacy live-uitvoering na.

Een buy-signaal vuurt op het signaalmoment, maar live koopt de bot NIET meteen: hij wacht maximaal
`window_min` minuten en koopt PAS als de prijs BOVEN de signaalprijs komt. Komt hij er niet boven
(of zakt hij eerst te ver) → de trade wordt afgeblazen en is nooit echt gestart. Dit weert de
spooktrades (signalen die alleen maar dalen).

In een backtest kun je die 3 minuten vooruitkijken (we hebben de historische data), dus hier passen
we het toe om de ECHT uitgevoerde trades te reproduceren — terwijl het signaal-genereren (rule_engine)
de futureprice juist overslaat (op het signaalmoment kun je niet vooruitkijken). Pure functie zonder
DB → los testbaar.
"""
import bisect
import datetime as _dt


def has_forward_data(DT, signal_dt, window_min=3.0):
    """True als er minstens één tick in (signal_dt, signal_dt+window_min] zit. Zo niet, dan kan de
    koop-bevestiging NIET draaien door datagebrek (staartstuk van de reeks / een datagat groter dan
    het venster) — dat is iets anders dan 'afgeblazen omdat de prijs niet kruiste'. De aanroeper
    gooit zo'n trade weg ZONDER 'm als afgeblazen te tellen (we kunnen 'm simpelweg niet beoordelen).
    Spiegelt exact de lo>=hi-grens in confirm_buy zodat beide dezelfde definitie van 'leeg venster'
    gebruiken."""
    lo = bisect.bisect_right(DT, signal_dt)
    hi = bisect.bisect_right(DT, signal_dt + _dt.timedelta(minutes=window_min))
    return lo < hi


def confirm_buy(DT, PX, signal_dt, signal_price, fp_bmin=None, window_min=3.0, xrows=None):
    """Geeft (buy_dt, buy_price) als de koop bevestigd wordt, anders None (afgeblazen).

    - window_min : max wachttijd in minuten na het signaal.
    - fp_bmin    : futureprice b_min (%). Zakt de prijs in het venster eerst ONDER
                   signaal*(1+fp_bmin/100) → afblazen (te ver gezakt vóór bevestiging).
    - xrows      : (n_tics, drempel%) van futureprice_x_rows. Zakt de prijs binnen de eerste n_tics
                   met ≤ drempel% → afblazen.
    Geeft het BEVESTIGINGSPUNT terug (de eerste tick boven de signaalprijs = de "kruising"). De
    aanroeper gebruikt dit als ja/nee-filter; de INSTAP zelf is de signaalprijs/-tijd (zo doet legacy
    het), niet de kruisprijs. None = afgeblazen.
    """
    lo = bisect.bisect_right(DT, signal_dt)
    hi = bisect.bisect_right(DT, signal_dt + _dt.timedelta(minutes=window_min))
    if lo >= hi:
        return None                                  # geen forward-data → geen bevestiging

    if xrows:                                        # futureprice_x_rows: snelle vroege drop → afblazen
        nrows, thr = xrows
        for k in range(lo, min(lo + nrows, hi)):
            if (PX[k] - signal_price) / signal_price * 100 <= thr:
                return None

    abort = signal_price * (1 + fp_bmin / 100.0) if fp_bmin is not None else None
    for k in range(lo, hi):
        if abort is not None and PX[k] < abort:
            return None                              # eerst te ver onder signaal → afblazen
        if PX[k] > signal_price:
            return DT[k], PX[k]                       # boven signaal → koop hier (kruisprijs)
    return None                                      # binnen het venster nooit boven signaal → afblazen


def params_by_rule(conn):
    """Laad de futureprice/x_rows-parameters per rule uit brain.rules. {rule: dict(bmin, window, xrows)}.
    Rules zonder futureprice komen niet voor → die kopen direct op de signaalprijs."""
    out = {}
    with conn.cursor() as c:
        c.execute("SELECT rule_number, subrulename, def1_value, b_min FROM rules "
                  "WHERE subrulename IN ('futureprice','futureprice_x_rows') AND active=1")
        for r in c.fetchall():
            d = out.setdefault(int(r["rule_number"]), {"bmin": None, "window": 3.0, "xrows": None})
            if r["subrulename"] == "futureprice":
                d["bmin"] = float(r["b_min"]) if r["b_min"] is not None else None
                d["window"] = float(r["def1_value"]) if r["def1_value"] else 3.0
            else:
                d["xrows"] = (int(r["def1_value"]), float(r["b_min"])) if r["b_min"] is not None else None
    return out
