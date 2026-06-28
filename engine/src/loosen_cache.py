#!/usr/bin/env python3
"""
loosen_cache — memoïsatie van de sole-dict (de dure per-tick subrule-status-scan in rq2_earlier).
Spiegelt fires_cache: per (coin, rule) een fingerprint op rule-def + indicators + coin_periods +
regime-versie (NIET sell-instellingen). Cache-hit = sole laden van schijf (seconden i.p.v. minuten).
"""
import glob
import hashlib
import json
import os
import tempfile
from datetime import datetime, timedelta

from config import FORWARD_MINUTES
from db import brain
import fires_cache
import regime

HERE = os.path.dirname(os.path.abspath(__file__))
LOOSEN_DIR = os.path.join(HERE, "..", "data", "loosen")
_LOOSEN_CODE_VER = "loosen-v1"


def _coin_periods_sig(c, sym):
    """Checksum van coin_periods (de promising-periodes die period_of() gebruikt)."""
    c.execute("SELECT COUNT(*) n, COALESCE(SUM(CRC32(CONCAT(period_from,'|',period_to))),0) cx "
              "FROM coin_periods WHERE trading_symbol_id=%s", (sym,))
    r = c.fetchone()
    return f"cp:{r['n']}:{r['cx']}"


def _non_data_sig(sym, rule):
    """Checksum van alle NIET-indicator inputs: rule-def + coin_periods + regime + min_volume.
    Gebruikt als guard in het incrementele pad — als dit verandert is de prefix-sole ongeldig,
    ook al is de indicator-data ongewijzigd."""
    conn = brain()
    with conn.cursor() as c:
        rule_sig = fires_cache._rule_def_sig(c, rule)
        cp_sig = _coin_periods_sig(c, sym)
        c.execute("SELECT COUNT(*) n, COALESCE(SUM(CRC32(CONCAT(rule_number,'|',COALESCE(min_volume,'')))),0) cx "
                  "FROM coin_rule_settings WHERE trading_symbol_id=%s", (sym,))
        mv = c.fetchone()
    conn.close()
    rv = regime.regime_ver(sym)
    sig = f"{rule_sig}|{cp_sig}|reg:{rv}|mv:{mv['n']}:{mv['cx']}"
    return hashlib.md5(sig.encode()).hexdigest()[:16]


def loosen_fingerprint(sym, rule):
    """Per-(coin, rule) fingerprint voor de sole-cache. Hergebruikt fires_cache-bouwstenen +
    coin_periods + regime-versie. Sell-instellingen zitten er NIET in."""
    conn = brain()
    with conn.cursor() as c:
        shared = fires_cache._ind_crs_sig(c, sym, "brain_volume_found", None, None)
        rule_sig = fires_cache._rule_def_sig(c, rule)
        cp_sig = _coin_periods_sig(c, sym)
    conn.close()
    rv = regime.regime_ver(sym)
    sig = f"{_LOOSEN_CODE_VER}|{shared}|{rule_sig}|{cp_sig}|reg:{rv}"
    return hashlib.md5(sig.encode()).hexdigest()[:16]


def _sole_path(sym, rule, fp):
    return os.path.join(LOOSEN_DIR, f"sole_{int(sym)}_r{int(rule)}__{fp}.json")


def _serialize_sole(sole, meta=None):
    """Sole-dict → JSON-serialiseerbaar. Datetime → ISO string, int keys → str keys."""
    out = {}
    for k, v in sole.items():
        moments = []
        for m in v["moments"]:
            mc = dict(m)
            mc["dt"] = mc["dt"].isoformat() if isinstance(mc["dt"], datetime) else mc["dt"]
            moments.append(mc)
        out[str(k)] = {"goed": v["goed"], "slecht": v["slecht"], "middel": v["middel"],
                        "moments": moments}
    data = {"sole": out}
    if meta:
        data["_meta"] = meta
    return data


def _deserialize_sole(data):
    """JSON → sole-dict. Str keys → int keys, ISO string → datetime."""
    raw = data["sole"]
    sole = {}
    for k, v in raw.items():
        moments = []
        for m in v["moments"]:
            mc = dict(m)
            mc["dt"] = datetime.fromisoformat(mc["dt"]) if isinstance(mc["dt"], str) else mc["dt"]
            moments.append(mc)
        sole[int(k)] = {"goed": v["goed"], "slecht": v["slecht"], "middel": v["middel"],
                         "moments": moments}
    meta = data.get("_meta")
    return sole, meta


def _write_atomic(data, path):
    """Schrijf JSON atomisch weg (tmpfile → os.replace)."""
    os.makedirs(os.path.dirname(path), exist_ok=True)
    fd, tmp = tempfile.mkstemp(dir=os.path.dirname(path), suffix=".json.tmp")
    os.close(fd)
    try:
        with open(tmp, "w") as f:
            json.dump(data, f, separators=(",", ":"))
        os.replace(tmp, path)
    except BaseException:
        try:
            os.remove(tmp)
        except OSError:
            pass
        raise


def _cleanup(sym, rule, keep_path):
    """Verwijder verouderde cache-bestanden van deze (sym, rule)."""
    pat = os.path.join(LOOSEN_DIR, f"sole_{int(sym)}_r{int(rule)}__*.json")
    for old in glob.glob(pat):
        if old != keep_path:
            try:
                os.remove(old)
            except OSError:
                pass


def cached_build_sole(sym, rule, eng, spans, force=False):
    """Sole-dict met cache-laag. Cache-hit → laden van schijf. Miss/force → build_sole() + opslaan.
    Geeft (sole_dict, was_cached)."""
    from rq2_earlier import build_sole

    os.makedirs(LOOSEN_DIR, exist_ok=True)
    fp = loosen_fingerprint(sym, rule)
    path = _sole_path(sym, rule, fp)

    if not force and os.path.exists(path):
        with open(path) as f:
            data = json.load(f)
        sole, meta = _deserialize_sole(data)
        return sole, True

    sole = build_sole(sym, rule, eng, spans)
    new_max = fires_cache.series_max_datetime(sym)
    pfx_cksum = fires_cache.prefix_indicators_checksum(sym, up_to=new_max) if new_max else None
    meta = {
        "last_max": new_max.isoformat() if new_max else None,
        "prefix_checksum": pfx_cksum,
        "non_data_sig": _non_data_sig(sym, rule),
    }
    _write_atomic(_serialize_sole(sole, meta), path)
    _cleanup(sym, rule, path)
    return sole, False


def cached_build_sole_incremental(sym, rule, eng, spans, force=False):
    """Sole-dict met incrementele cache. Bij aangroei: laad prefix-sole (t/m T_safe), scan alleen
    het nieuwe staartje, merge. T_safe = last_max - FORWARD_MINUTES (best_upside kijkt vooruit).
    Geeft (sole_dict, cache_status) met cache_status in ('cold', 'warm', 'incremental')."""
    from rq2_earlier import build_sole

    os.makedirs(LOOSEN_DIR, exist_ok=True)
    fp = loosen_fingerprint(sym, rule)
    path = _sole_path(sym, rule, fp)

    # volledige cache-hit (fingerprint ongewijzigd = zelfde rule-def + zelfde data + zelfde periodes)
    if not force and os.path.exists(path):
        with open(path) as f:
            data = json.load(f)
        sole, _ = _deserialize_sole(data)
        return sole, "warm"

    # probeer incrementeel: zoek de vorige cache (ander fp = data gegroeid maar rule ongewijzigd?)
    prev_sole, prev_meta = None, None
    if not force:
        for old in sorted(glob.glob(os.path.join(LOOSEN_DIR, f"sole_{int(sym)}_r{int(rule)}__*.json"))):
            try:
                with open(old) as f:
                    raw = json.load(f)
                prev_sole, prev_meta = _deserialize_sole(raw)
                break
            except (json.JSONDecodeError, KeyError, FileNotFoundError, OSError):
                continue

    new_max = fires_cache.series_max_datetime(sym)
    cur_nds = _non_data_sig(sym, rule)

    if prev_sole is not None and prev_meta and prev_meta.get("last_max") and new_max:
        last_max = datetime.fromisoformat(prev_meta["last_max"])
        stored_pfx = prev_meta.get("prefix_checksum")
        stored_nds = prev_meta.get("non_data_sig")
        current_pfx = fires_cache.prefix_indicators_checksum(sym, up_to=last_max) if last_max else None
        if (stored_nds and stored_nds == cur_nds
                and stored_pfx and current_pfx and stored_pfx == current_pfx
                and new_max > last_max):
            t_safe = last_max - timedelta(minutes=FORWARD_MINUTES)
            tail_sole = build_sole(sym, rule, eng, spans, frm=t_safe)
            merged = _merge_sole(prev_sole, tail_sole, t_safe)
            pfx_cksum = fires_cache.prefix_indicators_checksum(sym, up_to=new_max)
            meta = {"last_max": new_max.isoformat(), "prefix_checksum": pfx_cksum,
                    "non_data_sig": cur_nds}
            _write_atomic(_serialize_sole(merged, meta), path)
            _cleanup(sym, rule, path)
            return merged, "incremental"

    # koude start: volledige scan
    sole = build_sole(sym, rule, eng, spans)
    pfx_cksum = fires_cache.prefix_indicators_checksum(sym, up_to=new_max) if new_max else None
    meta = {"last_max": new_max.isoformat() if new_max else None, "prefix_checksum": pfx_cksum,
            "non_data_sig": cur_nds}
    _write_atomic(_serialize_sole(sole, meta), path)
    _cleanup(sym, rule, path)
    return sole, "cold"


def _merge_sole(prefix_sole, tail_sole, t_safe):
    """Merge prefix (momenten ≤ t_safe) met tail (momenten ≥ t_safe). Tail overschrijft de
    overlap-zone (momenten tussen t_safe en de oude last_max) zodat geüpdatete best_upside-
    classificaties correct zijn."""
    merged = {}
    all_keys = set(prefix_sole) | set(tail_sole)
    for k in all_keys:
        p = prefix_sole.get(k, {"goed": [], "slecht": [], "middel": [], "moments": []})
        t = tail_sole.get(k, {"goed": [], "slecht": [], "middel": [], "moments": []})
        # prefix-momenten strikt vóór t_safe (die zijn stabiel)
        stable_moments = [m for m in p["moments"]
                          if (m["dt"] if isinstance(m["dt"], datetime) else datetime.fromisoformat(m["dt"])) < t_safe]
        all_moments = stable_moments + t["moments"]
        merged[k] = {
            "goed": [m["value"] for m in all_moments if m["cls"] == "goed"],
            "slecht": [m["value"] for m in all_moments if m["cls"] == "slecht"],
            "middel": [m["value"] for m in all_moments if m["cls"] == "middel"],
            "moments": all_moments,
        }
    return merged
