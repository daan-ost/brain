#!/usr/bin/env python3
"""
PROBE (READ-ONLY, muteert NIETS) — bewijst of de sell-tuning MEER kan certificeren wanneer hij meet
op de BREDE promising-set (duizenden momenten) in plaats van alleen de EXECUTED trades (paar honderd
per munt). De hypothese uit een eerdere review: de dunne executed-holdout laat de net-toegevoegde
toeval-toets (sign-flip) vaak "kan-niet-certificeren" zeggen (floor > vereiste p); de promising-set
geeft genoeg geraakte trades (perm_n) om wél onder de Šidák-gecorrigeerde lat te komen.

Wat dit script doet (per munt 2525, 244):
  1. Genereert de promising-momenten IN-MEMORY (kopie van sell_promising.metrics + de filter), NIET
     via de tabel coin_moment_sells (die staat niet in de routine-keten → stale).
  2. Splitst per (munt,rule) op de eigen mediaan (per-regel, exact zoals sell_tuning.split_per_rule),
     met MIN_SPLIT. rule = de fire-rule op dat exacte moment (uit coin_fires, ALLE fires incl. shadow),
     anders DEFAULT_RULE=20.
  3. Draait HETZELFDE GRID + knob-injectie (eng.sl_by_rule[rule]=cand) + metrics/verdict/signflip_pvalue
     als sell_tuning, maar op de promising-set. De baseline-pl per moment wordt één keer gecached
     (anders O(n*window) per grid-waarde = te traag).
  4. Past dezelfde Šidák-poort toe die sell_apply zou toepassen (familiegrootte = alle SAFE-kandidaten
     van die munt) en vergelijkt EXECUTED vs PROMISING: hoeveel voorstellen gaan van
     GEEN_HOLDOUT/ZWAK/kan-niet-certificeren → beslisbaar/gecertificeerd, en wat is perm_n (aantal
     geraakte trades) in beide bronnen.

STRIKT read-only: geen INSERT/UPDATE/DELETE, geen --apply, geen persist_to_brain. Importeert
sell_tuning.measure() voor de executed-kant (die is zelf read-only, write_json=False).

Usage: probe_promising_tuning.py [symbol_id ...]   (default 2525 244)
"""
import bisect
import datetime as _dt
import sys
from collections import defaultdict

import sell_engine
import sell_tuning as st
import opt_lib as ol
from config import FORWARD_MINUTES
from db import brain

# Promising-definitie — byte-identiek aan sell_promising.PROM_REACH/PROM_DIP (== PromisingLabeler).
PROM_REACH = 3.0
PROM_DIP = -0.5
DEFAULT_RULE = 20

# We hergebruiken EXACT de sell_tuning-constanten zodat de meting identiek is aan de productie-routine.
RULES = st.RULES                 # [20, 21, 22, 23]
GRID = st.GRID
JSON_KEY = st.JSON_KEY
MIN_SPLIT = st.MIN_SPLIT
PERM_N = st.PERM_N
PERM_SEED = st.PERM_SEED
ALPHA = 0.05                     # zelfde lat als sell_apply / rule-discovery

COINS = [int(a) for a in sys.argv[1:] if a.isdigit()] or [2525, 244]


def prom_metrics(PX, DT, i):
    """buy, max60%, early-dip% — exact sell_promising.metrics, maar met de reeksen meegegeven."""
    buy = PX[i]
    hi = bisect.bisect_right(DT, DT[i] + _dt.timedelta(minutes=FORWARD_MINUTES))
    seg = PX[i:hi] or [buy]
    low = min(PX[i:i + 10] or [buy])
    return buy, (max(seg) - buy) / buy * 100, (low - buy) / buy * 100


def load_promising(conn, sym, eng):
    """Genereer de promising-momenten in-memory (kopie van sell_promising) en wijs elk een fire-rule toe.
    Geeft een lijst trades-dicts [{dt, buy, rule, is_real_rule}] gesorteerd op datetime (al chronologisch
    want eng.DT is gesorteerd). is_real_rule = of er op dat tick een echte fire stond (anders DEFAULT_RULE)."""
    DT, PX = eng.DT, eng.PX
    n = len(DT)
    with conn.cursor() as c:
        # ALLE fires (incl. shadow) — exact zoals sell_promising rule_at bouwt. Een tick kan meerdere
        # rules hebben (shadow + executed); we nemen de laatst geziene (zoals sell_promising's dict).
        c.execute("SELECT datetime, rule FROM coin_fires WHERE trading_symbol_id=%s", (sym,))
        rule_at = {r["datetime"]: int(r["rule"]) for r in c.fetchall()}
    prom = []
    for i in range(n):
        buy, mx, dip = prom_metrics(PX, DT, i)
        if mx >= PROM_REACH and dip >= PROM_DIP:
            r = rule_at.get(DT[i])
            prom.append({"dt": DT[i], "buy": buy, "rule": r if r is not None else DEFAULT_RULE,
                         "is_real_rule": r is not None})
    return prom


def measure_promising(conn, sym):
    """Meet ALLE grid-kandidaten op de promising-set van één munt — zelfde logica als
    sell_tuning.measure maar met de bredere bron + baseline-cache. Geeft (proposals, n_prom,
    n_default_rule_pollution, per_rule_counts)."""
    eng = sell_engine.SellEngine(sym, conn=conn)
    prom = load_promising(conn, sym, eng)
    name = eng.symbol

    # per-regel mediaan-split (exact split_per_rule, maar op promising-rijen). split_per_rule verwacht
    # rijen met ["rule"] en ["datetime"]; promising is al chronologisch.
    rows_for_split = [{"rule": t["rule"], "datetime": t["dt"]} for t in prom]
    splits, _med = st.split_per_rule(rows_for_split)
    for i, t in enumerate(prom):
        t["split"] = splits[i]

    # baseline pl per moment — ÉÉN keer (de dure stap). Daarna injecteren we per grid-waarde.
    base_pl = []
    for t in prom:
        r = eng.sell(t["dt"], t["buy"], t["rule"])
        base_pl.append(r["profit_loss"] if r else 0.0)

    by_rule = defaultdict(list)
    for idx, t in enumerate(prom):
        by_rule[t["rule"]].append(idx)

    n_default_pollution = sum(1 for t in prom if not t["is_real_rule"])
    per_rule_counts = {ru: len(idxs) for ru, idxs in sorted(by_rule.items())}

    proposals = []
    for rule in RULES:
        idxs = by_rule.get(rule, [])
        if not idxs:
            continue
        cur = eng.sl_by_rule[rule]
        for knob, values in GRID.items():
            for val in values:
                if abs(cur[knob] - val) < 1e-12:
                    continue
                cand = dict(cur)
                cand[knob] = val
                eng.sl_by_rule[rule] = cand
                tuned = {i: (eng.sell(prom[i]["dt"], prom[i]["buy"], rule) or {}).get("profit_loss", 0.0)
                         for i in idxs}
                eng.sl_by_rule[rule] = cur
                spl = {"train": [], "holdout": []}
                for i in idxs:
                    spl[prom[i]["split"]].append((base_pl[i], tuned[i]))
                mt, mh = st.metrics(spl["train"]), st.metrics(spl["holdout"])
                vd = st.verdict(mt, mh)
                netto_tot = round(mt["netto"] + mh["netto"], 2)
                prop = {"coin": sym, "rule": rule, "knob": JSON_KEY[knob], "from": cur[knob], "to": val,
                        "verdict": vd, "netto_totaal": netto_tot,
                        "netto_train": mt["netto"], "netto_holdout": mh["netto"],
                        "train": mt, "holdout": mh, "perm_n": None, "perm_p": None, "perm_floor": None}
                if vd == "SAFE":
                    deltas = [t - b for b, t in spl["train"]] + [t - b for b, t in spl["holdout"]]
                    sf = ol.signflip_pvalue(deltas, n_perm=PERM_N, seed=PERM_SEED)
                    if sf:
                        prop["perm_p"], prop["perm_n"], prop["perm_floor"] = sf["p"], sf["n"], sf["floor"]
                proposals.append(prop)
    eng.close()
    return name, proposals, len(prom), n_default_pollution, per_rule_counts


def apply_sidak_gate(proposals):
    """Zoals sell_apply: familiegrootte = aantal SAFE-kandidaten; een SAFE-voorstel is GECERTIFICEERD
    als floor <= required_raw_p(n_safe) (kan überhaupt certificeren) ÉN perm_p <= required_raw_p(n_safe).
    Markeert per voorstel: 'cert' (gecertificeerd), 'floor_block' (kan-niet-certificeren: te weinig
    geraakte trades), of 'p_block' (geraakt genoeg, maar p te hoog = waarschijnlijk toeval)."""
    safe = [p for p in proposals if p["verdict"] == "SAFE"]
    n_safe = len(safe)
    req = ol.required_raw_p(n_safe) if n_safe else ALPHA
    for p in proposals:
        p["sidak_status"] = None
        if p["verdict"] != "SAFE":
            continue
        if p["perm_p"] is None:                       # geen geraakte trade → niet toetsbaar
            p["sidak_status"] = "floor_block"
            continue
        if p["perm_floor"] > req + 1e-12:
            p["sidak_status"] = "floor_block"         # floor kan vereiste p nooit halen
        elif p["perm_p"] <= req + 1e-12:
            p["sidak_status"] = "cert"
        else:
            p["sidak_status"] = "p_block"
    return n_safe, req


def index_executed(report):
    """Zet sell_tuning.measure-output om naar {(coin,rule): [proposals]} met dezelfde Šidák-poort,
    zodat we per (munt,rule) executed vs promising naast elkaar kunnen leggen."""
    by_coin = defaultdict(list)
    for p in report["proposals"]:
        by_coin[p["coin"]].append(p)
    out = {}
    for coin, props in by_coin.items():
        n_safe, req = apply_sidak_gate(props)
        out[coin] = {"props": props, "n_safe": n_safe, "req": req}
    return out


def summarize(props, n_safe, req):
    """Tel beslisbaar (SAFE/OVERFIT/UNSAFE = een echt oordeel op holdout) vs niet-beslisbaar
    (GEEN_HOLDOUT/ZWAK/INERT), SAFE, en gecertificeerd. Geef ook de perm_n's van de SAFE-kandidaten."""
    decidable = sum(1 for p in props if p["verdict"] in ("SAFE", "OVERFIT", "UNSAFE"))
    geen_holdout = sum(1 for p in props if p["verdict"] == "GEEN_HOLDOUT")
    zwak = sum(1 for p in props if p["verdict"] == "ZWAK")
    safe = [p for p in props if p["verdict"] == "SAFE"]
    cert = [p for p in props if p.get("sidak_status") == "cert"]
    floor_block = [p for p in props if p.get("sidak_status") == "floor_block"]
    perm_ns = sorted(p["perm_n"] for p in safe if p["perm_n"] is not None)
    return {"total": len(props), "decidable": decidable, "geen_holdout": geen_holdout, "zwak": zwak,
            "safe": len(safe), "cert": len(cert), "floor_block": len(floor_block),
            "perm_ns": perm_ns, "cert_props": cert}


def main():
    conn = brain()
    print("=" * 96)
    print("PROBE — sell-tuning op de BREDE promising-set vs de EXECUTED trades (READ-ONLY, niets gemuteerd)")
    print("=" * 96)

    # EXECUTED-kant: hergebruik exact de productie-meting (read-only, geen JSON).
    print("\n[1] EXECUTED-meting via sell_tuning.measure() ...")
    exec_report = st.measure(coins=COINS, conn=conn, write_json=False, verbose=False)
    exec_idx = index_executed(exec_report)

    grand = {"exec": defaultdict(int), "prom": defaultdict(int)}
    for sym in COINS:
        print("\n" + "=" * 96)
        # PROMISING-kant
        name, prom_props, n_prom, n_pollution, per_rule = measure_promising(conn, sym)
        p_n_safe, p_req = apply_sidak_gate(prom_props)
        e = exec_idx.get(sym, {"props": [], "n_safe": 0, "req": ALPHA})
        e_props, e_n_safe, e_req = e["props"], e["n_safe"], e["req"]

        # baseline executed trades-aantal per regel (ter context)
        exec_base = {f"{sym}-{ru}": v for (k, v) in exec_report["baseline"].items()
                     if k.startswith(f"{sym}-") for ru in [int(k.split("-")[1])]}

        print(f"MUNT {name} ({sym})")
        print(f"  promising-momenten in-memory: {n_prom}  (waarvan {n_pollution} ZONDER echte fire-rule "
              f"= {100*n_pollution/max(1,n_prom):.0f}% → bucket {DEFAULT_RULE}-vervuiling)")
        print(f"  promising per regel (na rule-toewijzing): {per_rule}")
        es = summarize(e_props, e_n_safe, e_req)
        ps = summarize(prom_props, p_n_safe, p_req)
        print(f"  Šidák-familie: EXECUTED {e_n_safe} SAFE → vereiste raw-p {e_req:.4g}   |   "
              f"PROMISING {p_n_safe} SAFE → vereiste raw-p {p_req:.4g}")

        print(f"\n  {'':<26}{'EXECUTED':>12}{'PROMISING':>12}")
        for label, key in (("gemeten", "total"), ("beslisbaar (holdout)", "decidable"),
                           ("  waarvan SAFE", "safe"), ("GECERTIFICEERD (Šidák)", "cert"),
                           ("kan-niet-cert (floor)", "floor_block"),
                           ("GEEN_HOLDOUT (te dun)", "geen_holdout"), ("ZWAK (geen h.o.-bewijs)", "zwak")):
            print(f"  {label:<26}{es[key]:>12}{ps[key]:>12}")
        print(f"  perm_n (geraakte trades) van SAFE-kandidaten:")
        print(f"    EXECUTED : {es['perm_ns'] or '—'}")
        print(f"    PROMISING: {ps['perm_ns'] or '—'}")

        # per (munt,rule) detail
        print(f"\n  --- per regel (executed → promising) ---")
        for rule in RULES:
            ep = [p for p in e_props if p["rule"] == rule]
            pp = [p for p in prom_props if p["rule"] == rule]
            if not ep and not pp:
                continue
            esr, psr = summarize(ep, e_n_safe, e_req), summarize(pp, p_n_safe, p_req)
            ebase = exec_report["baseline"].get(f"{sym}-{rule}", {})
            pbase_n = per_rule.get(rule, 0)
            print(f"   rule {rule}: trades exec={ebase.get('n','?')} / prom={pbase_n}  |  "
                  f"beslisbaar {esr['decidable']}→{psr['decidable']}  SAFE {esr['safe']}→{psr['safe']}  "
                  f"cert {esr['cert']}→{psr['cert']}  perm_n SAFE exec={esr['perm_ns'] or '—'} "
                  f"prom={psr['perm_ns'] or '—'}")
            for cp in psr["cert_props"]:
                print(f"        ✔ PROM gecertificeerd: {cp['knob']} {cp['from']}→{cp['to']}  "
                      f"netto {cp['netto_totaal']:+.1f}%  perm_n={cp['perm_n']} p={cp['perm_p']:.4g} "
                      f"(floor {cp['perm_floor']:.4g} ≤ req {p_req:.4g})")

        for src, s in (("exec", es), ("prom", ps)):
            for k in ("total", "decidable", "safe", "cert", "floor_block", "geen_holdout", "zwak"):
                grand[src][k] += s[k]

    print("\n" + "=" * 96)
    print("TOTAAL over alle munten:")
    print(f"  {'':<26}{'EXECUTED':>12}{'PROMISING':>12}")
    for label, key in (("gemeten", "total"), ("beslisbaar (holdout)", "decidable"),
                       ("SAFE", "safe"), ("GECERTIFICEERD", "cert"),
                       ("kan-niet-cert (floor)", "floor_block"),
                       ("GEEN_HOLDOUT", "geen_holdout"), ("ZWAK", "zwak")):
        print(f"  {label:<26}{grand['exec'][key]:>12}{grand['prom'][key]:>12}")
    conn.close()


if __name__ == "__main__":
    main()
