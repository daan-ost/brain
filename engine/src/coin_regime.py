#!/usr/bin/env python3
"""
coin_regime — operationaliseert de regime-gate (Epic H). Berekent per munt de actieve/inactieve
perioden en schrijft ze idempotent naar `brain.coin_regime` (+ JSON-spiegel engine/data/coin_regime.json).

BRON-VAN-WAARHEID = www/app/Livewire/Coins/Weekly.php::applyGate() (de aan/uit-streep in /coins/weekly).
Deze module spiegelt dat algoritme BIT-GELIJK; `test_coin_regime.py` bewijst het per week op de munten.

Drie dingen die exact moeten kloppen om bit-gelijk te zijn:
 1. De WEKEN-AS komt uit `coin_daily_metrics` (de kansrijkheid-reeks), NIET uit coin_fires. De UI telt
    de weken af langs die reeks (Weekly.perCoin) en joint het trade-resultaat eroverheen
    (Weekly.weeklyTradeResult). Een week mét trades maar zónder daily-metric valt dus buiten de as;
    een week met daily-metric maar zonder trades telt als week_pl=0 (een zwakke week).
 2. De gate start pas bij de eerste week MÉT trades (week_n>0). Weken daarvóór = 'pre' (geen rij).
 3. week_pl = SUM(profit_loss) van de executed trades die week; YEARWEEK(.,3) = ISO-week (maandag-start).

Schaduw-trades (beslissing #4 in het epic-doc) horen bij LIVE traden (nog niet gebouwd) en vallen
buiten Epic H: de UI-streep rekent op echte coin_fires, dus deze module ook — anders is hij niet
bit-gelijk. In de backtest bestaan trades over de hele historie, dus dat volstaat.
"""
import json
import os
import sys
from datetime import datetime, timedelta

from coins import active_coin_ids
from db import brain

# Spiegel van de GATE_*-constants in Weekly.php (regel 37-41). NIET zomaar wijzigen — gevalideerd in
# epic-G. Asymmetrisch: snel uit (2 zwakke weken < 20%), traag aan (3 sterke weken >= 30%).
GATE_ROLL_WEEKS = 4        # rollend venster (~1 maand)
GATE_STOP_FLOOR = 20.0     # onder deze rollende % -> kandidaat-uit
GATE_STOP_CONFIRM = 2      # zwakke weken aaneen vóór stop
GATE_RESTART_FLOOR = 30.0  # boven deze rollende % -> kandidaat-aan (hoger = demping)
GATE_RESTART_CONFIRM = 3   # sterke weken aaneen vóór herstart

HERE = os.path.dirname(os.path.abspath(__file__))
JSON_PATH = os.path.join(HERE, "..", "data", "coin_regime.json")


def _week_axis(conn, sym):
    """De weken-as exact zoals Weekly.perCoin() + Weekly.weeklyTradeResult(): weken uit
    coin_daily_metrics (up_pct niet null), chronologisch, met per week de ISO-maandag (MIN(date)) en
    het trade-resultaat (Σprofit_loss, COUNT) uit coin_fires eroverheen gejoind. Geeft een lijst
    week-dicts in chronologische volgorde."""
    with conn.cursor() as c:
        c.execute("SELECT YEARWEEK(date,3) AS yw, MIN(date) AS week_date "
                  "FROM coin_daily_metrics WHERE up_pct IS NOT NULL AND trading_symbol_id=%s "
                  "GROUP BY YEARWEEK(date,3) ORDER BY YEARWEEK(date,3)", (sym,))
        weeks = c.fetchall()
        # Σwinst per ISO-week (Weekly.weeklyTradeResult). NB: NIET aliassen naar `id` (botst met
        # coin_fires.id -> MySQL groepeert op de PK i.p.v. de week). We aliassen naar `yw`.
        c.execute("SELECT YEARWEEK(datetime,3) AS yw, SUM(profit_loss) AS pl, COUNT(*) AS n "
                  "FROM coin_fires WHERE is_executed=1 AND profit_loss IS NOT NULL "
                  "AND trading_symbol_id=%s GROUP BY YEARWEEK(datetime,3)", (sym,))
        tr = {r["yw"]: r for r in c.fetchall()}
    out = []
    for w in weeks:
        t = tr.get(w["yw"])
        out.append({
            "yw": w["yw"],
            "week_date": w["week_date"],
            "week_pl": float(t["pl"]) if t else 0.0,   # geen trades die week -> 0 (zwakke week)
            "week_n": int(t["n"]) if t else 0,
        })
    return out


def _reason(state, below, above, roll):
    """Leesbare reden, gespiegeld op Weekly.php (cosmetisch; de FILTER en de test kijken naar `state`,
    niet naar deze tekst). round() kan in zeldzame .5-gevallen 1% van PHP afwijken — niet relevant."""
    if state == "active":
        return (f"let op: {below}/{GATE_STOP_CONFIRM} zwakke wkn (rollend {round(roll)}%)" if below > 0
                else f"aan · rollend {round(roll)}%")
    return (f"herstelt ({above}/{GATE_RESTART_CONFIRM} wkn ≥ {int(GATE_RESTART_FLOOR)}%)" if above > 0
            else f"maandtempo < {int(GATE_STOP_FLOOR)}% (rollend {round(roll)}%)")


def apply_gate(weeks):
    """Bit-gelijke spiegel van Weekly.php::applyGate(). Muteert elke week-dict in-place: zet
    'regime' ('pre'/'active'/'inactive'), 'rolling' (Σ rollend venster) en 'reason'.
    Leak-vrij by construction: de stand van week T gebruikt alleen `hist` (weken <= T)."""
    started = False
    state = "active"          # UI: 'on'
    below = above = 0
    hist = []
    for w in weeks:
        if not started:
            if w["week_n"] > 0:
                started = True
            else:
                w["regime"] = "pre"; w["rolling"] = 0.0; w["reason"] = "nog geen trades"
                continue
        hist.append(w["week_pl"])
        if len(hist) > GATE_ROLL_WEEKS:
            hist.pop(0)
        roll = sum(hist)
        w["rolling"] = roll
        if state == "active":
            below = below + 1 if roll < GATE_STOP_FLOOR else 0
            above = 0
            if below >= GATE_STOP_CONFIRM:
                state = "inactive"; below = 0
        else:
            above = above + 1 if roll >= GATE_RESTART_FLOOR else 0
            below = 0
            if above >= GATE_RESTART_CONFIRM:
                state = "active"; above = 0
        w["regime"] = state
        w["reason"] = _reason(state, below, above, roll)
    return weeks


def _iso_monday(d):
    """ISO-maandag van de week waarin `d` valt (== Carbon ->startOfWeek()). weekday(): maandag=0."""
    if isinstance(d, datetime):
        d = d.date()
    return d - timedelta(days=d.weekday())


def to_intervals(weeks):
    """Groepeer aaneengesloten weken met dezelfde regime tot intervallen. 'pre'-weken leveren geen
    interval. period_from = ISO-maandag eerste week, period_to = zondag (maandag+6) laatste week.
    reason/rolling = die van de LAATSTE week in het interval (de stand waarmee het interval eindigt)."""
    intervals = []
    cur = None
    for w in weeks:
        reg = w["regime"]
        if reg == "pre":
            continue
        monday = _iso_monday(w["week_date"])
        if cur and cur["state"] == reg:
            cur["period_to"] = monday + timedelta(days=6)
            cur["rolling_result"] = w["rolling"]
            cur["reason"] = w["reason"]
        else:
            if cur:
                intervals.append(cur)
            cur = {"state": reg, "period_from": monday, "period_to": monday + timedelta(days=6),
                   "rolling_result": w["rolling"], "reason": w["reason"]}
    if cur:
        intervals.append(cur)
    return intervals


def compute_for_coin(conn, sym):
    """Bereken de intervallen voor één munt (zonder weg te schrijven)."""
    weeks = _week_axis(conn, sym)
    apply_gate(weeks)
    return to_intervals(weeks)


def write_to_db(conn, sym, intervals, computed_at):
    """Idempotent: verwijder de munt en schrijf de verse intervallen in één transactie (geen race
    window voor concurrent lezers). Zelfde data -> zelfde intervallen -> geen drift."""
    with conn.cursor() as c:
        c.execute("SET autocommit=0")
        try:
            c.execute("DELETE FROM coin_regime WHERE trading_symbol_id=%s", (sym,))
            for iv in intervals:
                c.execute(
                    "INSERT INTO coin_regime (trading_symbol_id, period_from, period_to, state, reason, "
                    "rolling_result, computed_at) VALUES (%s,%s,%s,%s,%s,%s,%s)",
                    (sym, iv["period_from"], iv["period_to"], iv["state"], iv["reason"],
                     round(iv["rolling_result"], 2), computed_at))
            conn.commit()
        except:
            conn.rollback()
            raise
        finally:
            c.execute("SET autocommit=1")


def export_json(all_by_sym, path=JSON_PATH):
    """Draagbare spiegel van de tabel (Daans voorkeur). Eén dict per munt -> lijst intervallen."""
    data = {
        str(sym): [{
            "period_from": iv["period_from"].isoformat(),
            "period_to": iv["period_to"].isoformat(),
            "state": iv["state"],
            "reason": iv["reason"],
            "rolling_result": round(iv["rolling_result"], 2),
        } for iv in ivs]
        for sym, ivs in all_by_sym.items()
    }
    os.makedirs(os.path.dirname(path), exist_ok=True)
    with open(path, "w") as f:
        json.dump(data, f, indent=2, ensure_ascii=False)


def run(conn, syms=None):
    """Bereken + schrijf + exporteer voor alle (of de gegeven) munten. Geeft {sym: intervallen}."""
    computed_at = datetime.now().strftime("%Y-%m-%d %H:%M:%S")
    targets = syms or active_coin_ids()
    all_by_sym = {}
    for sym in targets:
        intervals = compute_for_coin(conn, sym)
        write_to_db(conn, sym, intervals, computed_at)
        all_by_sym[sym] = intervals
    export_json(all_by_sym)
    return all_by_sym


def main():
    syms = [int(x) for x in sys.argv[1:] if x.isdigit()] or None
    conn = brain()
    try:
        result = run(conn, syms)
    finally:
        conn.close()
    for sym, ivs in result.items():
        act = sum(1 for iv in ivs if iv["state"] == "active")
        ina = sum(1 for iv in ivs if iv["state"] == "inactive")
        print(f"coin {sym}: {len(ivs)} intervallen ({act} actief / {ina} inactief)")


if __name__ == "__main__":
    main()
