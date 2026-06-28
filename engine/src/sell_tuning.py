#!/usr/bin/env python3
"""
Sell-tuning meet-instrument (FASE 2) — READ-ONLY, muteert NIETS.

Per coin en per rule: vergelijk de huidige verkopen (baseline) met een aangepaste instelknop, en
toon of het netto BETER wordt. De meetlat is netto Σprofit-ruil (gewonnen − verloren), NIET het
aantal geredde verliezers — want NOS redt verliezers maar verliest Σprofit, en die ruil moet
zichtbaar blijven (Daan: "gebalanceerd" = netto Σprofit ≥ 0 én verliezers niet omhoog).

Tegen overfit: elke trade-set wordt per coin op de mediaan-datetime gesplitst in een OUDE helft
(train — hierop stellen we af) en een NIEUWE helft (holdout — hierop controleren we). Een voorstel
telt alleen als SAFE als het ÓÓK op de holdout wint (les recall-seed-tighten: in-sample winst die op
de holdout instort = afkeuren).

Meten gebeurt in-memory door eng.sl_by_rule[rule] te overschrijven (NIET de lock-functie te
monkey-patchen) — exact wat een echte per-coin override (coin_strategies) zou doen, maar zonder DB.

Usage: sell_tuning.py [symbol_id ...]      (default 2525 244; schrijft out/opt/sell_tuning_<date>.json)
"""
import sys
import json
import os
import datetime
from collections import defaultdict

import sell_engine
import opt_lib as ol
import regime
from db import brain
from coins import active_coin_ids

# CLI-args overrulen, anders alle coins met indicator-data (zie coins.py) — schaalt automatisch naar
# nieuwe munten zodra ze via import_indicators.py zijn ingeladen.
COINS = [int(a) for a in sys.argv[1:] if a.isdigit()] or active_coin_ids()
RULES = [20, 21, 22, 23]

# Minimum-omvang per helft (train én holdout) om een voorstel te mógen beoordelen. Eronder is er geen
# geldige apart-gehouden testperiode → GEEN_HOLDOUT (nooit SAFE). Vangt de scheve mini-holdout (2-3
# trades die toevallig niet verslechteren) die anders een vals SAFE-stempel kreeg. Mild gekozen voor de
# dunne executed-set; de echte oplossing voor dunne data is de bredere promising-bron (apart traject).
MIN_SPLIT = 4

# Toeval-toets (bug 4): aantal schudbeurten + seed voor de sign-flip toets per SAFE-kandidaat. De Šidák-
# correctie over de familie + de floor-check (kan-niet-certificeren bij te weinig geraakte trades) doet
# sell_apply, want pas dáár is de familiegrootte bekend.
PERM_N = 4000
PERM_SEED = 42

# Kandidaat-grid: per instelknop een kleine vaste reeks rond de huidige waarde. Eén knop tegelijk
# (schone attributie). Volgorde = de prioriteit uit het plan: hp6/hp7 (de NOS-lever) eerst.
# array_profit (per-coin tijd/winst-ladder) komt later — die vereist een dynamische afleiding.
GRID = {
    "hp6": [3.0, 4.0, 5.0, 6.0, 8.0],            # deler "bewaar ~25%" (piek 0,70–5%)
    "hp7": [10.0, 15.0, 20.0, 25.0],             # aftrek "bewaar ~50%" (piek ≥5%)
    "min_sl1": [0.985, 0.988, 0.99],             # absolute bodem-multiplier
    "minimal_profit": [0.5, 0.8, 1.0],           # drempel waaronder de leeftijdsbodems gelden
}
# Naar de echte JSON-sleutel (voor de changelog-reason later).
JSON_KEY = {"hp6": "hp_setting6", "hp7": "hp_setting7", "min_sl1": "min_sl1", "minimal_profit": "minimal_profit"}


def klasse(pl):
    return "slecht" if pl < 0 else ("middel" if pl < 3 else "goed")


def split_per_rule(rows):
    """Wijs elke (chronologisch gesorteerde) rij 'train' (oudste helft) of 'holdout' (nieuwste helft) toe
    PER REGEL, op de eigen mediaan-positie. Niet één globale knip over alle regels samen: regels zijn niet
    gelijk over de tijd verdeeld, dus een globale mediaan zou een regel die laat begon volledig in de
    holdout duwen (train leeg) of een scheve mini-holdout geven die niets bewijst. Pure functie (geen DB)
    zodat los testbaar; verwacht rows al gesorteerd op datetime. Geeft (splits in rij-volgorde,
    {rule: mediaan-datetime})."""
    by_rule = defaultdict(list)
    for idx, r in enumerate(rows):
        by_rule[int(r["rule"])].append(idx)
    splits = [None] * len(rows)
    med_by_rule = {}
    for ru, idxs in by_rule.items():
        mid_r = len(idxs) // 2
        for rank, idx in enumerate(idxs):
            splits[idx] = "train" if rank < mid_r else "holdout"
        med_by_rule[ru] = rows[idxs[mid_r]]["datetime"]      # eerste holdout-tick van deze regel
    return splits, med_by_rule


def load_trades(conn, sym, include_inactive=False):
    """Executed trades, chronologisch. Splitst PER REGEL op de eigen mediaan (zie split_per_rule).
    Geeft (trades, naam, {rule: mediaan-datetime}). Epic H: default zónder de inactieve-periode-trades
    (regime-gate); include_inactive=True laadt alles."""
    reg = "" if include_inactive else " AND " + regime.active_sql_clause()   # gekwalificeerd (coin_fires.*)
    with conn.cursor() as c:
        c.execute("SELECT datetime, buy_price, rule, symbol FROM coin_fires WHERE trading_symbol_id=%s "
                  "AND is_executed=1 AND buy_price IS NOT NULL" + reg + " ORDER BY datetime", (sym,))
        rows = c.fetchall()
    splits, med_by_rule = split_per_rule(rows)
    trades = [{"dt": r["datetime"], "buy": float(r["buy_price"]), "rule": int(r["rule"]),
               "symbol": r["symbol"], "split": splits[i]} for i, r in enumerate(rows)]
    name = rows[0]["symbol"] if rows else str(sym)
    return trades, name, med_by_rule


def metrics(pairs):
    """pairs = [(base_pl, tuned_pl), ...] voor één split. Geeft de netto-ruil + de twee assen + flips."""
    won = sum(max(0.0, t - b) for b, t in pairs)
    lost = sum(max(0.0, b - t) for b, t in pairs)
    netto = sum(t - b for b, t in pairs)                 # == Σtuned − Σbase == won − lost
    losers_base = sum(1 for b, t in pairs if b < 0)
    losers_tuned = sum(1 for b, t in pairs if t < 0)
    redding = sum(1 for b, t in pairs if b < 0 <= t)     # as-1: verlies → winst/middel
    uitloop = sum(1 for b, t in pairs if klasse(b) == "middel" and klasse(t) == "goed")  # as-2
    flips = sum(1 for b, t in pairs if b >= 0 > t)        # winst → verlies (mag NOOIT)
    affected = sum(1 for b, t in pairs if abs(b - t) > 1e-9)  # hoeveel trades de knop ECHT raakt
    return {"n": len(pairs), "affected": affected, "won": round(won, 2), "lost": round(lost, 2),
            "netto": round(netto, 2), "losers_base": losers_base, "losers_tuned": losers_tuned,
            "redding": redding, "uitloop": uitloop, "flips": flips}


def verdict(train, holdout):
    """SAFE = wint op train ÉN holdout zonder winnaars te breken of verliezers toe te voegen.
    OVERFIT = wint op train maar zakt op holdout. ZWAK = de knop raakt geen holdout-trade (geen
    bewijs op ongeziene data — telt NIET als bevestigd). INERT = raakt nergens iets. GEEN_HOLDOUT = een
    van beide helften is te klein (< MIN_SPLIT) voor een geldige toets. Anders UNSAFE."""
    if train["n"] < MIN_SPLIT or holdout["n"] < MIN_SPLIT:
        return "GEEN_HOLDOUT"
    if train["affected"] == 0 and holdout["affected"] == 0:
        return "INERT"
    geen_breuk = train["flips"] == 0 and holdout["flips"] == 0
    geen_extra_verlies = (train["losers_tuned"] <= train["losers_base"] and
                          holdout["losers_tuned"] <= holdout["losers_base"])
    if train["netto"] > 0 and holdout["netto"] < 0:
        return "OVERFIT"
    if holdout["affected"] == 0:
        return "ZWAK"                       # train-effect zonder holdout-bewijs → niet bevestigd
    if train["netto"] > 0 and holdout["netto"] >= 0 and geen_breuk and geen_extra_verlies:
        return "SAFE"
    return "UNSAFE"


def measure(coins=None, conn=None, write_json=True, verbose=True):
    """Meet alle kandidaten read-only en geef het rapport-dict terug (proposals + baseline). Schrijft
    optioneel out/opt/sell_tuning_<date>.json (voor het scherm). Wordt zowel door de CLI als de
    sell-tuning-routine aangeroepen — daarom geen prints tenzij verbose."""
    own = conn is None
    conn = conn or brain()
    coins = coins or COINS
    p = (lambda *a: print(*a)) if verbose else (lambda *a: None)
    report = {"generated_at": datetime.datetime.now().isoformat(timespec="seconds"),
              "coins": {}, "median_split": {}, "baseline": {}, "proposals": []}
    p("=" * 78)
    p("SELL-TUNING — meet-instrument (read-only). Meetlat = netto Σprofit-ruil, holdout leidend.")
    p("=" * 78)

    for sym in coins:
        eng = sell_engine.SellEngine(sym, conn=conn)
        trades, name, med_by_rule = load_trades(conn, sym)
        report["coins"][sym] = name
        report["median_split"][sym] = {ru: (d.isoformat() if hasattr(d, "isoformat") else d)
                                       for ru, d in med_by_rule.items()}
        # baseline pl per trade (huidige instellingen)
        base_pl = []
        for t in trades:
            r = eng.sell(t["dt"], t["buy"], t["rule"])
            base_pl.append(r["profit_loss"] if r else 0.0)

        by_rule = defaultdict(list)
        for idx, t in enumerate(trades):
            by_rule[t["rule"]].append(idx)

        p(f"\n### {name} ({sym}) — {len(trades)} trades, mediaan-split per regel (min {MIN_SPLIT}/helft)")
        for rule in RULES:
            idxs = by_rule.get(rule, [])
            if not idxs:
                continue
            base_sigma = round(sum(base_pl[i] for i in idxs), 1)
            base_losers = sum(1 for i in idxs if base_pl[i] < 0)
            report["baseline"][f"{sym}-{rule}"] = {"n": len(idxs), "sigma": base_sigma, "verliezers": base_losers}
            cur = eng.sl_by_rule[rule]
            best = None
            for knob, values in GRID.items():
                for val in values:
                    if abs(cur[knob] - val) < 1e-12:
                        continue
                    cand = dict(cur)
                    cand[knob] = val
                    eng.sl_by_rule[rule] = cand
                    tuned = {i: (eng.sell(trades[i]["dt"], trades[i]["buy"], rule) or {}).get("profit_loss", 0.0)
                             for i in idxs}
                    eng.sl_by_rule[rule] = cur     # herstel direct
                    spl = {"train": [], "holdout": []}
                    for i in idxs:
                        spl[trades[i]["split"]].append((base_pl[i], tuned[i]))
                    mt, mh = metrics(spl["train"]), metrics(spl["holdout"])
                    vd = verdict(mt, mh)
                    netto_tot = round(mt["netto"] + mh["netto"], 2)
                    prop = {"coin": sym, "coin_name": name, "rule": rule, "knob": JSON_KEY[knob],
                            "from": cur[knob], "to": val, "verdict": vd,
                            "netto_train": mt["netto"], "netto_holdout": mh["netto"], "netto_totaal": netto_tot,
                            "train": mt, "holdout": mh}
                    # Toeval-toets (bug 4): alleen voor SAFE-kandidaten (de enige die toegepast kunnen
                    # worden). Sign-flip op de per-trade verschillen, train+holdout gepoold — de knop is een
                    # vaste grid-waarde, er wordt niets op de train gefit, dus poolen is leak-vrij en geeft
                    # de meeste kracht. Šidák + floor-check volgen in sell_apply (familiegrootte-afhankelijk).
                    if vd == "SAFE":
                        deltas = [t - b for b, t in spl["train"]] + [t - b for b, t in spl["holdout"]]
                        sf = ol.signflip_pvalue(deltas, n_perm=PERM_N, seed=PERM_SEED)
                        if sf:
                            prop["perm_p"], prop["perm_n"], prop["perm_floor"] = sf["p"], sf["n"], sf["floor"]
                    report["proposals"].append(prop)
                    if vd == "SAFE" and (best is None or netto_tot > best["netto_totaal"]):
                        best = prop
            # toon de baseline + het beste veilige voorstel voor deze (coin, rule)
            line = f"  rule {rule}: baseline Σ{base_sigma:+.1f}% ({len(idxs)} trades, {base_losers} verlies)"
            if best:
                b = best
                line += (f"  →  {b['knob']} {b['from']}→{b['to']}: "
                         f"netto train {b['netto_train']:+.1f}% / holdout {b['netto_holdout']:+.1f}%, "
                         f"{b['holdout']['redding']}+{b['train']['redding']} gered  [SAFE]")
            else:
                line += "  →  geen veilig voorstel"
            p(line)
        eng.close()

    safe = [x for x in report["proposals"] if x["verdict"] == "SAFE"]
    overfit = [x for x in report["proposals"] if x["verdict"] == "OVERFIT"]
    zwak = [x for x in report["proposals"] if x["verdict"] == "ZWAK"]
    p("\n--- samenvatting voorstellen ---")
    p(f"  {len(safe)} SAFE · {len(overfit)} OVERFIT (train wint, holdout zakt) · "
      f"{len(zwak)} ZWAK (geen holdout-bewijs) · {len(report['proposals'])} gemeten")
    for x in sorted(safe, key=lambda x: -x["netto_totaal"])[:5]:
        p(f"   TOP {x['coin_name']} r{x['rule']} {x['knob']} {x['from']}→{x['to']}: "
          f"netto {x['netto_totaal']:+.1f}% (holdout {x['netto_holdout']:+.1f}%)")

    if write_json:
        os.makedirs("out/opt", exist_ok=True)
        path = f"out/opt/sell_tuning_{datetime.date.today().isoformat()}.json"
        with open(path, "w") as f:
            json.dump(report, f, indent=2, default=str)
        report["report_path"] = path
        p(f"\nrapport → engine/src/{path}")
    if own:
        conn.close()
    return report


if __name__ == "__main__":
    measure(write_json=True, verbose=True)
