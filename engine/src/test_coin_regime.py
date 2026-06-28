#!/usr/bin/env python3
"""
test_coin_regime — bewijst dat coin_regime.py BIT-GELIJK is aan de UI-streep (Weekly.php::applyGate).
Twee niveaus, op de 4 huidige munten:
 1. ALGORITME — apply_gate (Python) geeft per week dezelfde on/off/pre als Weekly.applyGate (PHP).
    De PHP-uitkomst komt via een reflection-brug: tinker roept de private Weekly::perCoin() aan.
 2. ROUNDTRIP — de naar `coin_regime` weggeschreven intervallen, teruggeprojecteerd op de weken-as,
    geven per week weer diezelfde regime -> de interval-groepering + DB-write verliezen niets.
Samen: coin_regime (DB) == Weekly.applyGate per week. Plain asserts; draai met de venv-python.
"""
import json
import os
import subprocess
from datetime import datetime

import coin_regime as cr
from db import brain

PHP = "/Applications/MAMP/bin/php/php8.4.17/bin/php"
WWW = os.path.join(cr.HERE, "..", "..", "www")
STATE_MAP = {"active": "on", "inactive": "off", "pre": "pre"}   # Python-regime -> UI-regime


def _php_weekly_regime():
    """Roep Weekly::perCoin() via reflection aan -> {sym(int): {iso_monday(date): 'on'/'off'/'pre'}}."""
    script = (
        '$w=new \\App\\Livewire\\Coins\\Weekly();'
        '$m=new \\ReflectionMethod($w,"perCoin");$m->setAccessible(true);$coins=$m->invoke($w);'
        '$out=[];foreach($coins as $c){foreach($c["weeks"] as $wk){$out[$c["id"]][$wk["start"]]=$wk["regime"];}}'
        'echo "J_START".json_encode($out)."J_END";'
    )
    res = subprocess.run([PHP, "artisan", "tinker", "--execute", script],
                         cwd=WWW, capture_output=True, text=True, timeout=120)
    if "J_START" not in res.stdout:
        raise AssertionError(f"PHP-brug faalde:\nstdout={res.stdout[-500:]}\nstderr={res.stderr[-500:]}")
    body = res.stdout.split("J_START", 1)[1].split("J_END", 1)[0]
    parsed = json.loads(body)
    return {int(sym): {datetime.strptime(d, "%d-%m-%Y").date(): reg for d, reg in weeks.items()}
            for sym, weeks in parsed.items()}


def _python_weekly_regime(conn, sym):
    """apply_gate per week -> {iso_monday(date): 'on'/'off'/'pre'}."""
    weeks = cr._week_axis(conn, sym)
    cr.apply_gate(weeks)
    return {cr._iso_monday(w["week_date"]): STATE_MAP[w["regime"]] for w in weeks}


def _db_weekly_regime(conn, sym, week_dates):
    """Projecteer de coin_regime-intervallen terug op de weken-as: per ISO-maandag de state."""
    with conn.cursor() as c:
        c.execute("SELECT period_from, period_to, state FROM coin_regime WHERE trading_symbol_id=%s "
                  "ORDER BY period_from", (sym,))
        ivs = c.fetchall()
    out = {}
    for monday in week_dates:
        reg = "pre"
        for iv in ivs:
            if iv["period_from"] <= monday <= iv["period_to"]:
                reg = STATE_MAP[iv["state"]]
                break
        out[monday] = reg
    return out


def main():
    conn = brain()
    try:
        cr.run(conn)                       # vul coin_regime + JSON op de echte munten
        php = _php_weekly_regime()
        assert php, "PHP-brug gaf geen munten terug"
        for sym in sorted(php):
            py = _python_weekly_regime(conn, sym)
            assert set(py) == set(php[sym]), \
                f"munt {sym}: weken-as wijkt af (PHP {len(php[sym])} vs PY {len(py)})"
            mism = [(d, php[sym][d], py[d]) for d in sorted(py) if php[sym][d] != py[d]]
            assert not mism, f"munt {sym}: {len(mism)} week-mismatches PHP vs Python, eerste: {mism[0]}"
            db = _db_weekly_regime(conn, sym, list(py))
            mism2 = [(d, py[d], db[d]) for d in sorted(py) if py[d] != db[d]]
            assert not mism2, f"munt {sym}: {len(mism2)} mismatches Python vs DB-intervallen, eerste: {mism2[0]}"
            print(f"munt {sym}: {len(py)} weken bit-gelijk (PHP == Python == DB-intervallen)")
        print("OK test_coin_regime")
    finally:
        conn.close()


if __name__ == "__main__":
    main()
