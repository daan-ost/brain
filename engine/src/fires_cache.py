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
_FIRES_CODE_VER = "fires-v1"


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


def _ind_crs_sig(c, sym, gate, frm, to):
    """De GEDEELDE fires-inputs (gelijk voor elke rule van de munt): indicators-reeks + de VOLLEDIGE
    coin_rule_settings.min_volume. De volledige min_volume MOET mee — niet alleen die van de rule zelf —
    want rule_engine.relvol_base = min(min_volume over ALLE rules); een min_volume-wijziging in een andere
    rule verschuift de relvol-waarden van deze rule. Geeft (ind_part_str, crs_part_str)."""
    c.execute(f"SELECT COUNT(*) n, COALESCE(MAX(datetime),'') mx, "
              f"COALESCE(SUM(CRC32(CONCAT(indicator,'|',datetime,'|',value,'|',"
              f"COALESCE(price,''),'|',{gate}))),0) cx "
              "FROM indicators WHERE trading_symbol_id=%s AND value IS NOT NULL", (sym,))
    ind = c.fetchone()
    c.execute("SELECT COUNT(*) n, COALESCE(SUM(CRC32(CONCAT(rule_number,'|',COALESCE(min_volume,'')))),0) cx "
              "FROM coin_rule_settings WHERE trading_symbol_id=%s", (sym,))
    crs = c.fetchone()
    return (f"win:{frm}:{to}|ind:{ind['n']}:{ind['mx']}:{ind['cx']}|crs:{crs['n']}:{crs['cx']}")


def rule_fires_fingerprint(sym, rule, frm, to, gate_col="brain_volume_found"):
    """Per-rule fires-fingerprint: de gedeelde inputs (indicators + volledige min_volume) + ALLEEN de
    definitie van DEZE rule. Een banden-/subregel-wijziging in een andere rule verandert deze fp dus NIET
    → die andere rule re-firet, deze blijft warm. Een min_volume-wijziging (waar dan ook) verandert wél
    alle per-rule fp's (relvol_base-koppeling). Sell-instellingen/labels zitten er bewust niet in."""
    gate = gate_col if gate_col in ("brain_volume_found", "volume_found") else "brain_volume_found"
    conn = brain()
    with conn.cursor() as c:
        shared = _ind_crs_sig(c, sym, gate, frm, to)
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


def _rule_path(sym, rule, fp):
    return os.path.join(FIRES_DIR, f"fires_{int(sym)}_r{int(rule)}__{fp}.parquet")


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
    for rule in rules:
        fp = rule_fires_fingerprint(sym, rule, frm, to, gate_col)
        path = _rule_path(sym, rule, fp)
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
    # opruimen: alle fires-bestanden van DEZE munt die niet bij deze run horen (oude per-rule fp's én de
    # all-rules Plan A-parquet). De glob matcht niet per ongeluk een andere munt: de underscore na het
    # munt-id voorkomt prefix-botsing (244 vs 2440).
    for old in glob.glob(os.path.join(FIRES_DIR, f"fires_{int(sym)}_*.parquet")) + \
               glob.glob(os.path.join(FIRES_DIR, f"fires_{int(sym)}__*.parquet")):
        if old not in keep:
            try:
                os.remove(old)
            except OSError:
                pass
    all_fires.sort()
    return all_fires, n_warm, len(rules)
