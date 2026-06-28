#!/usr/bin/env python3
"""Orakel-vangnet voor de INCREMENTELE refire (epic-I). Bewijst de harde eis: een refire die de
stabiele prefix hergebruikt levert EXACT dezelfde coin_fires op als een volledige verse refire.

Methode (split-test op NOS, het lichtste muntje):
  A. volledige refire t/m M (= ~prefix)         -> legt coin_fires t/m M + coin_refire_state.last_max=M neer
  B. refire t/m eind, DEFAULT pad               -> mag de prefix-cache hergebruiken (incrementeel zodra gebouwd)
  C. volledige refire t/m eind (--full --force) -> de REFERENTIE (alles vers)
  eis: read_fires(na B) == read_fires(na C), BIT-IDENTIEK op alle inhoudelijke velden.

Velden die NIET vergeleken worden: id (auto-increment), period_id (auto-increment FK; de period-GRENZEN
worden indirect getoetst via in_good_period), created_at/updated_at (NOW()). Die verschillen al tussen
twee volledige refires en horen niet in de bit-identiek-eis.

FASE 1 (deze versie): het incrementele pad bestaat nog niet -> B draait gewoon volledig, dus B==C
triviaal. Dat bewijst de HARNESS (split + snapshot/restore + de veld-vergelijking) vóór de echte logica
er is. Zodra fires_cache prefix-bewust is, gebruikt B de prefix-cache en moet B==C blijven.

LET OP: muteert tijdelijk de LIVE coin_fires/coin_periods/coin_refire_state van NOS. Snapshot vooraf,
restore in finally (incl. PK's, zodat period_id-FK's exact terugkomen). Niet draaien terwijl een routine
op NOS werkt. Draai: ../.venv/bin/python test_incremental_refire.py
"""
import datetime as _dt
import os
import subprocess
import sys

from db import brain

HERE = os.path.dirname(os.path.abspath(__file__))
PY = os.path.join(HERE, "..", ".venv", "bin", "python")
SYM = 244  # NOS

# tabellen die een refire van NOS aanraakt — volledig snapshotten + restoren (PK's incl.).
# coin_fires.period_id is een FK naar coin_periods.id: DELETE child-first, INSERT parent-first.
COIN_TABLES = ["coin_periods", "coin_fires", "coin_refire_state", "coin_fires_changelog"]
DELETE_ORDER = ["coin_fires", "coin_fires_changelog", "coin_refire_state", "coin_periods"]
INSERT_ORDER = ["coin_periods", "coin_fires", "coin_refire_state", "coin_fires_changelog"]

# velden buiten de bit-identiek-vergelijking (zie module-docstring)
IGNORE_FIELDS = {"id", "period_id", "created_at", "updated_at"}


def _fmt(dt):
    return dt.strftime("%Y-%m-%d %H:%M:%S")


def _series_bounds():
    c = brain()
    with c.cursor() as cur:
        cur.execute("SELECT MIN(datetime) lo, MAX(datetime) hi FROM indicators "
                    "WHERE trading_symbol_id=%s AND indicator='volumeud'", (SYM,))
        r = cur.fetchone()
    c.close()
    return r["lo"], r["hi"]


def snapshot():
    """Lees alle NOS-rijen uit de aangeraakte tabellen (incl. PK's) zodat we exact kunnen herstellen."""
    snap = {}
    c = brain()
    try:
        for t in COIN_TABLES:
            with c.cursor() as cur:
                cur.execute(f"SELECT * FROM {t} WHERE trading_symbol_id=%s", (SYM,))
                rows = cur.fetchall()
                cols = [d[0] for d in cur.description]
            snap[t] = (cols, rows)
    finally:
        c.close()
    return snap


def restore(snap):
    """DELETE + re-INSERT de exacte snapshot-rijen (PK's behouden). DELETE child-first, INSERT
    parent-first zodat de coin_fires.period_id-FK nooit naar een ontbrekende coin_periods-rij wijst.
    In finally aangeroepen."""
    c = brain()
    try:
        for t in DELETE_ORDER:
            with c.cursor() as cur:
                cur.execute(f"DELETE FROM {t} WHERE trading_symbol_id=%s", (SYM,))
        for t in INSERT_ORDER:
            cols, rows = snap[t]
            if not rows:
                continue
            collist = ",".join(f"`{x}`" for x in cols)
            ph = ",".join(["%s"] * len(cols))
            with c.cursor() as cur:
                cur.executemany(f"INSERT INTO {t} ({collist}) VALUES ({ph})",
                                [tuple(r[x] for x in cols) for r in rows])
        c.commit()
    finally:
        c.close()


def _clean_firesinc(frm):
    """Ruim de firesinc-parquets op die deze test onder zijn eigen venster-START (frm) schreef via de
    persist-subprocessen — anders blijft er dode cache-litter staan (productie gebruikt frm=None)."""
    import glob
    import fires_cache as fc
    for f in glob.glob(os.path.join(fc.FIRES_DIR, f"firesinc_{SYM}_r*_f{fc._frm_hash(frm)}__*.parquet")):
        try:
            os.remove(f)
        except OSError:
            pass


def read_fires():
    """Genormaliseerde coin_fires-rijen voor de vergelijking: drop de genegeerde velden, sorteer
    deterministisch op (datetime, rule, is_executed)."""
    c = brain()
    try:
        with c.cursor() as cur:
            cur.execute("SELECT * FROM coin_fires WHERE trading_symbol_id=%s", (SYM,))
            rows = cur.fetchall()
    finally:
        c.close()
    norm = []
    for r in rows:
        norm.append({k: v for k, v in r.items() if k not in IGNORE_FIELDS})
    norm.sort(key=lambda d: (d["datetime"], d["rule"], d["is_executed"]))
    return norm


def run_persist(frm, to, *flags):
    """Draai persist_to_brain als subprocess (zoals de routine). Faalt hard bij non-zero exit."""
    cmd = [PY, os.path.join(HERE, "persist_to_brain.py"), str(SYM), _fmt(frm), _fmt(to), "15", *flags]
    r = subprocess.run(cmd, capture_output=True, text=True)
    if r.returncode != 0:
        raise RuntimeError(f"persist_to_brain faalde ({' '.join(flags)}):\n{r.stdout}\n{r.stderr}")
    return r.stdout


def _diff(a, b):
    """Eerste paar verschillen tussen twee genormaliseerde fire-lijsten (voor de foutmelding)."""
    out = []
    if len(a) != len(b):
        out.append(f"AANTAL verschilt: incrementeel={len(a)} vs volledig={len(b)}")
    for i in range(min(len(a), len(b))):
        if a[i] != b[i]:
            diffs = {k: (a[i].get(k), b[i].get(k)) for k in set(a[i]) | set(b[i])
                     if a[i].get(k) != b[i].get(k)}
            out.append(f"rij {i} @ {a[i].get('datetime')}: {diffs}")
            if len(out) >= 6:
                break
    return "\n".join(out)


def test_split_bit_identity():
    lo, hi = _series_bounds()
    # smal venster zodat de discovery-rules niet de volle ~290 dagen scannen (elke refire ~1 min i.p.v. 12).
    frm = hi - _dt.timedelta(days=20)
    M = hi - _dt.timedelta(days=5)        # 15d prefix, 5d staart
    end = hi
    print(f"  venster {_fmt(frm)} .. {_fmt(end)} | split M={_fmt(M)} (staart {_fmt(M)}..{_fmt(end)})")

    snap = snapshot()
    try:
        run_persist(frm, M, "--force")                 # A: prefix t/m M (+ cache + state.last_max=M)
        b_out = run_persist(frm, end)                  # B: DEFAULT — moet de prefix-cache hergebruiken
        incremental = read_fires()
        run_persist(frm, end, "--full", "--force")     # C: referentie (alles vers)
        full = read_fires()
    finally:
        restore(snap)
        _clean_firesinc(frm)

    # B MOET incrementeel zijn gegaan (anders test de assert het incrementele pad niet — stille terugval
    # op volledig zou ook slagen). De log bewijst het pad + dat er echt een staart verwerkt is.
    assert "refire-modus: incrementeel" in b_out, f"B ging NIET incrementeel:\n{b_out}"
    assert "0 nieuwe fire-momenten" not in b_out, f"B had een lege staart (geen aangroei getest):\n{b_out}"
    assert incremental == full, "incrementeel != volledig:\n" + _diff(incremental, full)
    print(f"  A split bit-identity: PASS ({len(full)} fires, incrementeel==volledig, B-pad incrementeel)")


def test_prefix_mismatch_falls_back():
    """Wijzig één OUDE indicator-waarde (dt < M) na de prefix-refire → de prefix-checksum klopt niet meer
    → B MOET terugvallen op volledig, en nog steeds bit-identiek zijn aan een verse volledige refire op
    diezelfde (gewijzigde) data."""
    lo, hi = _series_bounds()
    frm = hi - _dt.timedelta(days=20)
    M = hi - _dt.timedelta(days=5)
    end = hi
    # een te muteren oude tick (ruim binnen de prefix) kiezen
    c = brain()
    try:
        with c.cursor() as cur:
            cur.execute("SELECT id, value FROM indicators WHERE trading_symbol_id=%s AND indicator='volumeud' "
                        "AND value IS NOT NULL AND datetime < %s ORDER BY datetime DESC LIMIT 1",
                        (SYM, _fmt(M)))
            tick = cur.fetchone()
    finally:
        c.close()
    assert tick, "geen oude volumeud-tick om te muteren"

    snap = snapshot()
    orig_val = tick["value"]
    try:
        run_persist(frm, M, "--force")                 # A: legt prefix-checksum t/m M vast
        # muteer één oude waarde → prefix-data wijkt af van de opgeslagen checksum
        c = brain()
        with c.cursor() as cur:
            cur.execute("UPDATE indicators SET value=%s WHERE id=%s", (float(orig_val) + 123.0, tick["id"]))
        c.close()
        b_out = run_persist(frm, end)                  # B: moet de mismatch zien → volledig
        incremental = read_fires()
        run_persist(frm, end, "--full", "--force")     # C: verse volledige refire op de gewijzigde data
        full = read_fires()
    finally:
        c = brain()                                    # waarde herstellen
        with c.cursor() as cur:
            cur.execute("UPDATE indicators SET value=%s WHERE id=%s", (orig_val, tick["id"]))
        c.close()
        restore(snap)
        _clean_firesinc(frm)

    assert "prefix gewijzigd" in b_out, f"B zag de prefix-wijziging NIET (geen terugval op volledig):\n{b_out}"
    assert incremental == full, "fallback-volledig != referentie-volledig:\n" + _diff(incremental, full)
    print(f"  B prefix-mismatch → volledige fallback: PASS ({len(full)} fires, correct)")


def test_cached_fires_incremental_direct():
    """Directe unit-test van cached_fires_incremental (niet via persist): prime t/m M, dan grow t/m end
    waarbij end VOORBIJ de series-max ligt (de venster-rand). Eis: grow bit-identiek aan een volledige
    verse fires()-berekening, geen duplicaten, prefix warm. Bewaakt de invariant 'cache nooit voorbij
    new_max' — anders zou een venster-caller met to>new_max dubbele trades krijgen (critical-eye #1).
    Raakt geen coin_-tabellen; schrijft alleen z'n eigen (unieke frm) parquet-lineage + ruimt die op."""
    import glob
    from collections import Counter
    import fires_cache as fc
    from rule_engine import RuleEngine

    _, hi = _series_bounds()
    frm = hi - _dt.timedelta(days=14)          # smal, eigen venster
    M = hi - _dt.timedelta(days=4)
    end = hi + _dt.timedelta(days=1)           # VOORBIJ de series-max
    re = RuleEngine(SYM)
    try:
        rules = sorted(re.rules.keys())
        fires_fn = lambda rule, a, b: re.fires(rule, a, b)
        nm1 = fc.series_max_datetime(SYM, M)
        fc.cached_fires_incremental(SYM, frm, M, rules, fires_fn, last_max=None, new_max=nm1, force=True)
        nm2 = fc.series_max_datetime(SYM, end)
        grow, w2, n2 = fc.cached_fires_incremental(SYM, frm, end, rules, fires_fn, last_max=nm1, new_max=nm2)
        ref = sorted((d, rule) for rule in rules for d in fires_fn(rule, frm, end))
    finally:
        re.close()
        for f in glob.glob(os.path.join(fc.FIRES_DIR, f"firesinc_{SYM}_r*_f{fc._frm_hash(frm)}__*.parquet")):
            try:
                os.remove(f)
            except OSError:
                pass
    dups = [k for k, c in Counter(grow).items() if c > 1]
    assert w2 == n2, f"prefix niet warm: {w2}/{n2}"
    assert not dups, f"duplicaten in grow (cache reikte voorbij new_max): {dups[:5]}"
    assert grow == ref, f"grow != volledig vers: n_grow={len(grow)} n_ref={len(ref)}"
    print(f"  C cached_fires_incremental direct (to voorbij new_max): PASS ({len(ref)} fires, warm {w2}/{n2})")


if __name__ == "__main__":
    print("test_incremental_refire — orakel-vangnet (NOS)")
    test_cached_fires_incremental_direct()
    test_split_bit_identity()
    test_prefix_mismatch_falls_back()
    print("ALLE TESTS PASS")
