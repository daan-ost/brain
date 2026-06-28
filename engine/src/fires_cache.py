#!/usr/bin/env python3
"""Memoïsatie van de KOOP-kant fire-momenten (all_fires) per munt.

De refire (persist_to_brain) herberekent elke keer `rule_engine.fires()` over de volle tick-reeks — de
discovery-rules (30-34) zijn ONGEPOORT en draaien dus op élke tick, wat ~90% van de refire-tijd kost
(gemeten: NOS ~10 min, MUMU ~13 min). Maar `fires()` hangt ALLEEN af van:
  (a) de indicator-reeksen (value/price/volume_found) van de munt, en
  (b) de actieve rule-definities (20-23 + discovery) + per-munt min_volume.
NIET van de verkoop-instellingen (`coin_strategies.sl_settings`), labels of strategies. Een sell-tuning-
refire wijzigt alleen een verkoop-knop → de fire-momenten zijn IDENTIEK, alleen de sell-engine-P&L erna
verandert. Door all_fires te cachen op een fingerprint van precies (a)+(b) valt zo'n refire van ~13 min
terug naar de (goedkope) sell-loop.

Bit-identiek by construction: bij een fingerprint-mismatch wordt gewoon de oude `fires()`-lus gedraaid en
het resultaat weggeschreven. De orakel-test (test_fires_cache.py) bewijst cold==warm==directe-lus en dat
een verkoop-wijziging de cache NIET invalideert terwijl een rule-wijziging dat WEL doet.
"""
import glob
import hashlib
import os
import tempfile

import pandas as pd

from db import brain

HERE = os.path.dirname(os.path.abspath(__file__))
FIRES_DIR = os.path.join(HERE, "..", "data", "fires")
# Codeversie van de fires-bouw/serialisatie. Bump bij een wijziging aan compute/serialisatie zodat oude
# parquet's automatisch invalideren (los van de data-fingerprint).
_FIRES_CODE_VER = "fires-v3"   # v3: venster-hash in per-rule filename (cross-venster cache-isolatie)


def fires_fingerprint(sym, frm, to, gate_col="brain_volume_found"):
    """Content-checksum van ALLEEN de fires-bepalende inputs voor deze munt:
    - indicators-reeks (count + max(datetime) + CRC32-checksum van indicator|datetime|value|price|gate),
    - actieve fires-rules (20-23 + discovery) — globale definitie, content-checksum,
    - per-munt coin_rule_settings.min_volume — content-checksum,
    - gate_col + het [frm,to]-venster.
    SUM(CRC32(...)) (niet XOR) tegen het swap-probleem (zie schaalplan test_opt_lib). Bevat BEWUST geen
    coin_strategies/labels/strategies/CHANGELOG_REASON: die mogen de fire-momenten niet invalideren."""
    gate = gate_col if gate_col in ("brain_volume_found", "volume_found") else "brain_volume_found"
    conn = brain()
    with conn.cursor() as c:
        c.execute(f"SELECT COUNT(*) n, COALESCE(MAX(datetime),'') mx, "
                  f"COALESCE(SUM(CRC32(CONCAT(indicator,'|',datetime,'|',value,'|',"
                  f"COALESCE(price,''),'|',{gate}))),0) cx "
                  "FROM indicators WHERE trading_symbol_id=%s AND value IS NOT NULL", (sym,))
        ind = c.fetchone()
        c.execute("SELECT COUNT(*) n, COALESCE(SUM(CRC32(CONCAT(rule_number,'|',sort,'|',indicator,'|',"
                  "subrulename,'|',COALESCE(def1_value,''),'|',COALESCE(b_min,''),'|',COALESCE(b_max,''),"
                  "'|',COALESCE(value_condition,''),'|',COALESCE(source,'')))),0) cx "
                  "FROM rules WHERE active=1 AND (rule_number IN (20,21,22,23) OR source LIKE 'discovery%')")
        rul = c.fetchone()
        c.execute("SELECT COUNT(*) n, COALESCE(SUM(CRC32(CONCAT(rule_number,'|',COALESCE(min_volume,'')))),0) cx "
                  "FROM coin_rule_settings WHERE trading_symbol_id=%s", (sym,))
        crs = c.fetchone()
    conn.close()
    sig = (f"{_FIRES_CODE_VER}|gate:{gate}|win:{frm}:{to}"
           f"|ind:{ind['n']}:{ind['mx']}:{ind['cx']}"
           f"|rul:{rul['n']}:{rul['cx']}"
           f"|crs:{crs['n']}:{crs['cx']}")
    return hashlib.md5(sig.encode()).hexdigest()[:16]


def _ind_crs_sig(c, sym, gate, frm, to, up_to=None):
    """De GEDEELDE fires-inputs (gelijk voor elke rule van de munt): indicators-reeks + de VOLLEDIGE
    coin_rule_settings.min_volume. De volledige min_volume MOET mee — niet alleen die van de rule zelf —
    want rule_engine.relvol_base = min(min_volume over ALLE rules); een min_volume-wijziging in een andere
    rule verschuift de relvol-waarden van deze rule. Geeft (ind_part_str, crs_part_str).

    up_to (epic-I): beperk de indicators-checksum tot datetime <= up_to (de stabiele PREFIX). Zo krijgt
    de prefix-cache van een rule een eigen content-key; de volgende refire vindt 'm terug zolang de data
    t/m up_to onveranderd is. up_to=None → de volledige reeks (ongewijzigd gedrag)."""
    cond = "AND datetime <= %s" if up_to is not None else ""
    params = (sym, up_to) if up_to is not None else (sym,)
    c.execute(f"SELECT COUNT(*) n, COALESCE(MAX(datetime),'') mx, "
              f"COALESCE(SUM(CRC32(CONCAT(indicator,'|',datetime,'|',value,'|',"
              f"COALESCE(price,''),'|',{gate}))),0) cx "
              f"FROM indicators WHERE trading_symbol_id=%s AND value IS NOT NULL {cond}", params)
    ind = c.fetchone()
    c.execute("SELECT COUNT(*) n, COALESCE(SUM(CRC32(CONCAT(rule_number,'|',COALESCE(min_volume,'')))),0) cx "
              "FROM coin_rule_settings WHERE trading_symbol_id=%s", (sym,))
    crs = c.fetchone()
    return (f"win:{frm}:{to}|ind:{ind['n']}:{ind['mx']}:{ind['cx']}|crs:{crs['n']}:{crs['cx']}")


def rule_fires_fingerprint(sym, rule, frm, to, gate_col="brain_volume_found", up_to=None):
    """Per-rule fires-fingerprint: de gedeelde inputs (indicators + volledige min_volume) + ALLEEN de
    definitie van DEZE rule. Een banden-/subregel-wijziging in een andere rule verandert deze fp dus NIET
    → die andere rule re-firet, deze blijft warm. Een min_volume-wijziging (waar dan ook) verandert wél
    alle per-rule fp's (relvol_base-koppeling). Sell-instellingen/labels zitten er bewust niet in.
    up_to (epic-I): de prefix-variant (indicators t/m up_to) — zie _ind_crs_sig."""
    gate = gate_col if gate_col in ("brain_volume_found", "volume_found") else "brain_volume_found"
    conn = brain()
    with conn.cursor() as c:
        shared = _ind_crs_sig(c, sym, gate, frm, to, up_to=up_to)
        c.execute("SELECT COUNT(*) n, COALESCE(SUM(CRC32(CONCAT(rule_number,'|',sort,'|',indicator,'|',"
                  "subrulename,'|',COALESCE(def1_value,''),'|',COALESCE(b_min,''),'|',COALESCE(b_max,''),"
                  "'|',COALESCE(value_condition,''),'|',COALESCE(source,'')))),0) cx "
                  "FROM rules WHERE active=1 AND rule_number=%s "
                  "AND (rule_number IN (20,21,22,23) OR source LIKE 'discovery%%')", (rule,))
        rd = c.fetchone()
    conn.close()
    sig = f"{_FIRES_CODE_VER}|gate:{gate}|{shared}|rule:{int(rule)}:{rd['n']}:{rd['cx']}"
    return hashlib.md5(sig.encode()).hexdigest()[:16]


def _path(sym, fp):
    return os.path.join(FIRES_DIR, f"fires_{int(sym)}__{fp}.parquet")


def _win_hash(frm, to):
    """Korte hash van het (frm,to)-venster — komt in de filename zodat cleanup files van een ander
    venster (bv. smal-venster test naast volle-venster productie) niet weggooit. Hier OK om short te
    zijn: een collision zou alleen een verouderde cache laten staan, niet de juiste invalideren — de
    data-fp dekt dat af."""
    return hashlib.md5(f"{frm}|{to}".encode()).hexdigest()[:8]


def _rule_path(sym, rule, fp, frm=None, to=None):
    """Per-rule cache-pad. Bevat venster-hash zodat één-rule files van verschillende vensters naast
    elkaar kunnen bestaan (test smal venster vs productie volle venster) — cleanup ruimt dan per
    (sym, rule, venster) op, niet over de vensters heen."""
    wh = _win_hash(frm, to)
    return os.path.join(FIRES_DIR, f"fires_{int(sym)}_r{int(rule)}_w{wh}__{fp}.parquet")


def _load(path):
    """Lees de parquet en geef de gesorteerde lijst (datetime, rule) — exact zoals all_fires na .sort()."""
    df = pd.read_parquet(path)
    out = [(dt.to_pydatetime(), int(r)) for dt, r in zip(df["datetime"], df["rule"])]
    out.sort()
    return out


def cached_all_fires(sym, frm, to, compute_fn, gate_col="brain_volume_found", force=False):
    """Geef all_fires (gesorteerde lijst (datetime, rule)) voor `sym`. Cache-hit op gelijke fingerprint →
    direct van schijf (geen fires()-lus). Miss/force → `compute_fn()` draaien en atomisch wegschrijven.
    compute_fn() moet de gesorteerde lijst (datetime, rule) teruggeven (de oude persist_to_brain-lus)."""
    os.makedirs(FIRES_DIR, exist_ok=True)
    fp = fires_fingerprint(sym, frm, to, gate_col)
    path = _path(sym, fp)
    if not force and os.path.exists(path):
        return _load(path), True
    # wees-temp opruimen (gekilde write) — matchen de cache-glob niet, alleen netheid
    for stale in glob.glob(os.path.join(FIRES_DIR, "*.parquet.tmp")):
        try:
            os.remove(stale)
        except OSError:
            pass
    all_fires = compute_fn()
    df = pd.DataFrame(all_fires, columns=["datetime", "rule"])
    fd, tmp = tempfile.mkstemp(dir=FIRES_DIR, suffix=".parquet.tmp")
    os.close(fd)
    try:
        df.to_parquet(tmp, index=False)
        os.replace(tmp, path)
    except BaseException:
        try:
            os.remove(tmp)
        except OSError:
            pass
        raise
    # oude fp-bestanden van deze munt opruimen
    for old in glob.glob(os.path.join(FIRES_DIR, f"fires_{int(sym)}__*.parquet")):
        if old != path:
            try:
                os.remove(old)
            except OSError:
                pass
    return all_fires, False


def cached_fires_per_rule(sym, frm, to, rules, compute_rule_fn, gate_col="brain_volume_found", force=False):
    """Plan B — PER-RULE fires-cache. Cachet de fire-momenten van elke rule apart, gesleuteld op die rule's
    eigen definitie + de gedeelde inputs (indicators + volledige min_volume). Eén rule wijzigen → alleen
    die rule re-firet; de (zware) discovery-rules blijven warm. compute_rule_fn(rule) geeft de fire-
    datetimes voor één rule (de oude rule_engine.fires(rule, ...)). Geeft (all_fires_gesorteerd, n_warm,
    n_rules). Bit-identiek aan de all-rules-lus: all_fires = gesorteerde concat van de per-rule lijsten.

    Atomic write + wees-temp-opruiming als bij cached_all_fires. De cache-bestanden die NIET bij deze run
    horen (oude fp's + de all-rules Plan A-parquet van deze munt) worden opgeruimd."""
    os.makedirs(FIRES_DIR, exist_ok=True)
    for stale in glob.glob(os.path.join(FIRES_DIR, "*.parquet.tmp")):
        try:
            os.remove(stale)
        except OSError:
            pass
    all_fires = []
    keep = set()
    n_warm = 0
    wh = _win_hash(frm, to)
    for rule in rules:
        fp = rule_fires_fingerprint(sym, rule, frm, to, gate_col)
        path = _rule_path(sym, rule, fp, frm, to)
        keep.add(path)
        if not force and os.path.exists(path):
            df = pd.read_parquet(path)
            all_fires.extend((dt.to_pydatetime(), int(r)) for dt, r in zip(df["datetime"], df["rule"]))
            n_warm += 1
            continue
        fires = [(dt, int(rule)) for dt in compute_rule_fn(rule)]
        df = pd.DataFrame(fires, columns=["datetime", "rule"])
        fd, tmp = tempfile.mkstemp(dir=FIRES_DIR, suffix=".parquet.tmp")
        os.close(fd)
        try:
            df.to_parquet(tmp, index=False)
            os.replace(tmp, path)
        except BaseException:
            try:
                os.remove(tmp)
            except OSError:
                pass
            raise
        all_fires.extend(fires)
    # Opruimen: PER (sym, rule, venster) alleen de verouderde data-fp weggooien. De venster-hash
    # (w<wh>) in de filename isoleert verschillende vensters: een smal-venster test naast volle-
    # venster productie blijft naast elkaar bestaan. Wij ruimen alleen ONS venster's verouderde
    # data-fps op (bv. een min_volume-wijziging op DIT venster maakt de oude fp obsolete).
    # De all-rules Plan A-parquet (`fires_<sym>__<fp>.parquet`, dubbele underscore) wordt door deze
    # glob niet gepakt (we vragen om _r<rule>_w<wh>__*).
    for rule in rules:
        pat = os.path.join(FIRES_DIR, f"fires_{int(sym)}_r{int(rule)}_w{wh}__*.parquet")
        for old in glob.glob(pat):
            if old not in keep:
                try:
                    os.remove(old)
                except OSError:
                    pass
    all_fires.sort()
    return all_fires, n_warm, len(rules)


# ===================== epic-I: incrementele (aangroei-bewuste) fires-cache =====================
# De per-rule cache hierboven sleutelt op de checksum van de HELE reeks → nieuwe data verschuift de
# checksum → alle rules koud (~13 min). Bij dagelijkse AANGROEI verandert de oude data niet; alleen
# een staartje komt erbij. cached_fires_incremental hergebruikt de prefix-fires (t/m last_max) uit de
# cache van de vorige run en berekent alleen de staart (> last_max). Bit-identiek aan een volledige
# fires()-lus: de fire-uitkomst van een prefix-tick hangt alleen van zijn look-back af (data ≤ tick ≤
# last_max), die ongewijzigd is; de staart-ticks zien de volle reeks in geheugen. Bewezen in
# test_incremental_refire.py.


def prefix_indicators_checksum(sym, up_to, gate_col="brain_volume_found"):
    """PURE data-checksum van de indicators-prefix t/m up_to (count + max + CRC32-som). GEEN rule-def,
    GEEN min_volume, GEEN venster — alleen: 'is de ruwe data t/m up_to onveranderd?'. persist_to_brain
    bewaart 'm (t/m new_max) in coin_refire_state en vergelijkt 'm de volgende run (t/m last_max): match
    → aangroei → incrementeel; mismatch → oude data gewijzigd/herladen → volledige refire."""
    gate = gate_col if gate_col in ("brain_volume_found", "volume_found") else "brain_volume_found"
    conn = brain()
    with conn.cursor() as c:
        c.execute(f"SELECT COUNT(*) n, COALESCE(MAX(datetime),'') mx, "
                  f"COALESCE(SUM(CRC32(CONCAT(indicator,'|',datetime,'|',value,'|',"
                  f"COALESCE(price,''),'|',{gate}))),0) cx "
                  "FROM indicators WHERE trading_symbol_id=%s AND value IS NOT NULL AND datetime <= %s",
                  (sym, up_to))
        r = c.fetchone()
    conn.close()
    return hashlib.md5(f"pfx|gate:{gate}|n:{r['n']}|mx:{r['mx']}|cx:{r['cx']}".encode()).hexdigest()[:16]


def series_max_datetime(sym, to=None):
    """De laatste gedekte tick (MAX(datetime), value IS NOT NULL). to (exclusief, zoals fires()) begrenst
    'm voor een venster-refire. = de new_max-grens: t/m hier is de prefix van de volgende run gedekt."""
    cond = "AND datetime < %s" if to is not None else ""
    params = (sym, to) if to is not None else (sym,)
    conn = brain()
    with conn.cursor() as c:
        c.execute(f"SELECT MAX(datetime) mx FROM indicators WHERE trading_symbol_id=%s "
                  f"AND value IS NOT NULL {cond}", params)
        mx = c.fetchone()["mx"]
    conn.close()
    return mx


def _write_fires_atomic(fires, path):
    """Schrijf de (datetime, rule)-lijst atomisch weg als parquet (tmp → os.replace)."""
    df = pd.DataFrame(fires, columns=["datetime", "rule"])
    fd, tmp = tempfile.mkstemp(dir=FIRES_DIR, suffix=".parquet.tmp")
    os.close(fd)
    try:
        df.to_parquet(tmp, index=False)
        os.replace(tmp, path)
    except BaseException:
        try:
            os.remove(tmp)
        except OSError:
            pass
        raise


def _frm_hash(frm):
    """Hash van ALLEEN de venster-START. Het venster-EIND mag NIET in de incrementele cache-key: dat
    eind groeit elke run (aangroei), terwijl de prefix juist hergebruikt moet worden. Identificatie van
    een prefix = (start, gedekte-max), waar de gedekte-max in de fp zit (via up_to). Isoleert wel
    verschillende starts (productie frm=None naast een test met vaste frm)."""
    return hashlib.md5(f"{frm}".encode()).hexdigest()[:8]


def _inc_rule_path(sym, rule, fp, frm):
    """Per-rule incrementele cache (eigen prefix `firesinc_`, los van de Plan-B `fires_`-familie).
    Filename: alleen de venster-START + de content-fp (die de gedekte-max encodeert)."""
    return os.path.join(FIRES_DIR, f"firesinc_{int(sym)}_r{int(rule)}_f{_frm_hash(frm)}__{fp}.parquet")


def _rule_def_sig(c, rule):
    """De per-rule definitie-component van de fp (goedkoop: enkele subrule-rijen). EXACT het rule-deel
    uit rule_fires_fingerprint, zodat de samengestelde fp ermee overeenkomt."""
    c.execute("SELECT COUNT(*) n, COALESCE(SUM(CRC32(CONCAT(rule_number,'|',sort,'|',indicator,'|',"
              "subrulename,'|',COALESCE(def1_value,''),'|',COALESCE(b_min,''),'|',COALESCE(b_max,''),"
              "'|',COALESCE(value_condition,''),'|',COALESCE(source,'')))),0) cx "
              "FROM rules WHERE active=1 AND rule_number=%s "
              "AND (rule_number IN (20,21,22,23) OR source LIKE 'discovery%%')", (rule,))
    rd = c.fetchone()
    return f"rule:{int(rule)}:{rd['n']}:{rd['cx']}"


def _combine_fp(gate, shared, rule_sig):
    """Stel de per-rule fp samen uit de (1× berekende) gedeelde sig + de rule-def sig. EXACT hetzelfde
    formaat als rule_fires_fingerprint → de fp's zijn uitwisselbaar (de cache blijft consistent)."""
    return hashlib.md5(f"{_FIRES_CODE_VER}|gate:{gate}|{shared}|{rule_sig}".encode()).hexdigest()[:16]


def cached_fires_incremental(sym, frm, to, rules, fires_fn, last_max, new_max,
                             gate_col="brain_volume_found", force=False):
    """all_fires (gesorteerde (datetime, rule)-lijst) via aangroei. Per rule:
      - cache-hit op de prefix-fp (rule-def + data t/m last_max) → laad prefix + bereken staart (> last_max);
      - miss (eerste run / rule of data gewijzigd / opgeruimd) of last_max=None / force → bereken vers volledig.
    fires_fn(rule, w_frm, w_to) geeft de fire-datetimes van één rule over [w_frm, w_to) (= rule_engine.fires).
    Schrijft per rule de NIEUWE volledige fires weg onder de fp t/m new_max — de prefix van de volgende run.
    Geeft (all_fires, n_prefix_warm, n_rules)."""
    os.makedirs(FIRES_DIR, exist_ok=True)
    for stale in glob.glob(os.path.join(FIRES_DIR, "*.parquet.tmp")):
        try:
            os.remove(stale)
        except OSError:
            pass
    gate = gate_col if gate_col in ("brain_volume_found", "volume_found") else "brain_volume_found"
    # De ZWARE gedeelde indicators-CRC32 (1 scan over de hele prefix, ~0,9s op NOS / ~2,5s op FARTCOIN)
    # 1× per grens berekenen i.p.v. per rule — anders 2×N volledige scans (16 op NOS ≈ 14s verspild).
    # Rule-def is goedkoop (enkele rijen). De fp blijft bit-identiek aan rule_fires_fingerprint.
    conn = brain()
    with conn.cursor() as c:
        shared_prefix = _ind_crs_sig(c, sym, gate, frm, last_max, up_to=last_max) if last_max is not None else None
        shared_new = _ind_crs_sig(c, sym, gate, frm, new_max, up_to=new_max) if new_max is not None else None
        rule_sigs = {rule: _rule_def_sig(c, rule) for rule in rules}
    conn.close()
    all_fires = []
    keep = set()
    n_warm = 0
    for rule in rules:
        prefix = None
        if last_max is not None and not force and shared_prefix is not None:
            ppath = _inc_rule_path(sym, rule, _combine_fp(gate, shared_prefix, rule_sigs[rule]), frm)
            if os.path.exists(ppath):
                df = pd.read_parquet(ppath)
                prefix = [(d.to_pydatetime(), int(r)) for d, r in zip(df["datetime"], df["rule"])]
        if prefix is not None:
            # prefix bevat fires t/m last_max (inclusief); de staart is strikt erna
            tail = [(d, int(rule)) for d in fires_fn(rule, last_max, to) if d > last_max]
            rule_fires = prefix + tail
            n_warm += 1
        else:
            rule_fires = [(d, int(rule)) for d in fires_fn(rule, frm, to)]
        all_fires.extend(rule_fires)
        npath = _inc_rule_path(sym, rule, _combine_fp(gate, shared_new, rule_sigs[rule]), frm)
        keep.add(npath)
        _write_fires_atomic(rule_fires, npath)
    # verouderde firesinc-fp's van dit (sym, rule, START) opruimen — de prefix van gisteren is vandaag
    # geconsumeerd; alleen de net-geschreven (t/m new_max) blijft. Andere START (ander frm) blijft staan.
    for rule in rules:
        for old in glob.glob(os.path.join(FIRES_DIR, f"firesinc_{int(sym)}_r{int(rule)}_f{_frm_hash(frm)}__*.parquet")):
            if old not in keep:
                try:
                    os.remove(old)
                except OSError:
                    pass
    all_fires.sort()
    return all_fires, n_warm, len(rules)
