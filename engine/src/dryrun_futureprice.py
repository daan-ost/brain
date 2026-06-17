#!/usr/bin/env python3
"""
DRY-RUN (read-only): wat betekent het als de engine de koop-bevestiging futureprice /
futureprice_x_rows zou toepassen (zoals legacy live doet: 3 min wachten, pas kopen als de prijs
BOVEN de signaalprijs komt)? Toont per munt/regel hoeveel huidige executed trades zouden afvallen
(spooktrades die live nooit gekocht zouden zijn), uitgesplitst naar klasse, en het effect op
Σprofit + #verliezers. MUTEERT NIETS.

futureprice geldt alleen voor rule 20 (b_min -1,2) en 23 (b_min -0,2); futureprice_x_rows alleen
voor rule 20 (-0,7% in 2 tics). Rules 21/22 hebben geen koop-bevestiging → ongewijzigd.

Twee scenario's:
  A  cross-above-only : koop iff de prijs binnen 3 min boven de signaalprijs komt.
  B  volledig          : + b_min-abort (zakt eerst >|b_min|% onder signaal → afblazen) + x_rows
                         (zakt -0,7% binnen 2 tics → afblazen).
"""
import bisect
import datetime as _dt

from db import brain

FP = {20: -1.2, 23: -0.2}        # futureprice b_min per rule
XROWS = {20: (2, -0.7)}          # futureprice_x_rows: (aantal tics, drempel %)
WINDOW_MIN = 3


def load_series(conn, sym):
    with conn.cursor() as c:
        c.execute("SELECT datetime, price FROM indicators WHERE trading_symbol_id=%s AND indicator='volumeud' "
                  "AND price IS NOT NULL ORDER BY datetime", (sym,))
        seen, DT, PX = set(), [], []
        for r in c.fetchall():
            if r["datetime"] in seen:
                continue
            seen.add(r["datetime"])
            DT.append(r["datetime"]); PX.append(float(r["price"]))
    return DT, PX


def klasse(pl):
    return "slecht" if pl < 0 else ("middel" if pl < 3 else "goed")


def decide(DT, PX, T, signal, rule, scenario):
    """Geeft (keep: bool, new_buy: float|None). 'keep' = de trade zou live gekocht zijn."""
    lo = bisect.bisect_right(DT, T)
    hi = bisect.bisect_right(DT, T + _dt.timedelta(minutes=WINDOW_MIN))
    win = list(range(lo, hi))
    if not win:
        return False, None                              # geen forward-data → geen bevestiging

    # futureprice_x_rows (scenario B, rule 20): eerste N tics, drop >= |drempel|% → afblazen
    if scenario == "B" and rule in XROWS:
        nrows, thr = XROWS[rule]
        for k in win[:nrows]:
            if (PX[k] - signal) / signal * 100 <= thr:
                return False, None

    abort_level = signal * (1 + FP[rule] / 100.0) if (scenario == "B" and rule in FP) else None
    for k in win:
        if abort_level is not None and PX[k] < abort_level:
            return False, None                          # eerst te ver gezakt → afblazen
        if PX[k] > signal:
            return True, PX[k]                          # boven signaal → koop hier
    return False, None                                  # binnen 3 min nooit boven signaal → afblazen


def run():
    conn = brain()
    for sym, naam in ((2525, "DOGEAI"), (244, "NOS")):
        DT, PX = load_series(conn, sym)
        with conn.cursor() as c:
            c.execute("SELECT datetime, buy_price, rule, profit_loss FROM coin_fires WHERE trading_symbol_id=%s "
                      "AND is_executed=1 AND buy_price IS NOT NULL AND profit_loss IS NOT NULL ORDER BY datetime", (sym,))
            trades = [(t["datetime"], float(t["buy_price"]), int(t["rule"]), float(t["profit_loss"])) for t in c.fetchall()]

        print(f"\n{'='*78}\n{naam} ({sym}) — {len(trades)} executed trades nu\n{'='*78}")
        for scenario in ("A", "B"):
            kept, cancelled = [], []
            cancel_kl = {"slecht": 0, "middel": 0, "goed": 0}
            for (T, signal, rule, pl) in trades:
                if rule not in FP:                       # 21/22: geen koop-bevestiging → altijd behouden
                    kept.append(pl); continue
                keep, _ = decide(DT, PX, T, signal, rule, scenario)
                if keep:
                    kept.append(pl)
                else:
                    cancelled.append(pl); cancel_kl[klasse(pl)] += 1
            sig0 = sum(pl for (_, _, _, pl) in trades)
            ver0 = sum(1 for (_, _, _, pl) in trades if pl < 0)
            sig1 = sum(kept); ver1 = sum(1 for pl in kept if pl < 0)
            label = "A cross-above-only" if scenario == "A" else "B + b_min-abort + x_rows"
            print(f"\n  [{label}]  afgeblazen: {len(cancelled)} "
                  f"(slecht {cancel_kl['slecht']}, middel {cancel_kl['middel']}, goed {cancel_kl['goed']})")
            print(f"     trades {len(trades)}→{len(kept)} · Σprofit {sig0:+.0f}%→{sig1:+.0f}% "
                  f"(spooktrades samen {sum(cancelled):+.0f}%) · verliezers {ver0}→{ver1}")
            # alleen op de rules met futureprice (20/23) — de zuivere filter-impact
            for rule in sorted(FP):
                tr = [(T, s, r, pl) for (T, s, r, pl) in trades if r == rule]
                if not tr:
                    continue
                can = sum(1 for (T, s, r, pl) in tr if not decide(DT, PX, T, s, r, scenario)[0])
                cls = {"slecht": 0, "middel": 0, "goed": 0}
                for (T, s, r, pl) in tr:
                    if not decide(DT, PX, T, s, r, scenario)[0]:
                        cls[klasse(pl)] += 1
                print(f"        rule {rule}: {len(tr)} trades → {can} afgeblazen "
                      f"(slecht {cls['slecht']}/{sum(1 for x in tr if x[3]<0)}, "
                      f"middel {cls['middel']}, goed {cls['goed']})")
    conn.close()


if __name__ == "__main__":
    run()
