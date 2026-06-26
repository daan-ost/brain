"""
opt_lib — the VERIFIED numeric core for rule-set optimisation (read-only analysis).

Single source of truth for: loading executed trades + the indicator_metrics cache (duckdb over
Parquet), placing a subrule threshold at the BAD EDGE (brain-rule-tuning principle 2), and
validating a candidate OUT-OF-SAMPLE — both a per-coin time split (train 70 / test 30) and a
CROSS-COIN split (derive on DOGEAI, test on NOS and vice versa).

Everything here is descriptive/read-only. It creates and changes NOTHING. The rq_*.py scripts and
the workflow's verify agents call these functions so the math is identical everywhere.

GOOD  = executed trade, best_upside >= 3%   (the opportunity was real)
BAD   = executed trade, best_upside <  0.5% (slecht — to prevent)
MIDDEL= 0.5 .. 3%   (grey zone, ignored in good/bad separation)
"""
import glob
import hashlib
import os

import duckdb
import numpy as np
import pandas as pd

from db import brain
from calc import WINDOW_METRIC_KEYS

# Classification thresholds — must mirror www/app/Models/CoinFire.php::klasseKey().
# For EXECUTED trades we now classify on profit_loss (realized result) to match the UI; this is
# what Daan steers on. Shadow fires (no sell) fall back to best_upside in load_all_fires.
# UI rule: profit_loss >= 3 → goed, 0..3 → middel, < 0 → slecht.
# (Best_upside thresholds 3 / 0.5 are kept as the FALLBACK for shadows / non-executed analysis.)
GOOD_PL = 3.0
BAD_PL = 0.0
GOOD_UPSIDE = 3.0
BAD_UPSIDE = 0.5
from coins import active_coin_ids, optimize_coin_ids

# Back-compat: een paar onderzoek-tools (rq1/rq2/split_2b/new_feat_discover) gebruiken nog `o.DOGEAI`/
# `o.NOS` als constanten. Die tools zijn 2-coin-only by design (vroege analyses op DOGEAI/NOS); shim
# vermijdt een AttributeError-crash zonder ze allemaal te hoeven herschrijven.
DOGEAI = 2525
NOS = 244

# Cross-coin validatie via leave-one-out over de OPTIMIZE-set (snel pad: DOGEAI+NOS; overrule via env
# OPTIMIZE_COINS). 4-coin LOO bleek onpraktisch traag (5u rq1_tighten zonder resultaat). De
# discovery-engine + sell-tuning gebruiken nog steeds alle coins; alleen rule-precision is hier
# geschaald omdat de bottleneck zich daar manifesteerde.
def crosscoin_splits():
    cs = optimize_coin_ids()
    return [(tuple(x for x in cs if x != te), te) for te in cs] if len(cs) >= 2 else []

HERE = os.path.dirname(os.path.abspath(__file__))
METRICS_GLOB = os.path.join(HERE, "..", "data", "metrics", "indicator_metrics_*.parquet")
CALC_COLS = list(WINDOW_METRIC_KEYS)

# ---------------------------------------------------------------------------
# Scale-validity guard (cache-vs-engine mismatch protection).
# The indicator_metrics cache stores `volumeud` as RELATIVE volume (raw / min_volume), but the
# rule-engine evaluates subrule metrics on the RAW volumeud series. A threshold derived from the
# cache is therefore only valid in the engine if the metric is INVARIANT under scaling by a positive
# constant. LEVEL metrics (scale with the constant) are NOT — a cache-derived threshold on them is
# meaningless in the engine (it silently becomes a no-op). See docs/optimization/...rule-set... and
# memory note volumeud-cache-engine-scale. Only volumeud is normalised, so only it is affected.
SCALE_NORMALIZED_INDICATORS = {"volumeud"}
# absolute/level calcs: value scales linearly with the series -> cache != engine for volumeud.
LEVEL_CALCS = {
    "current_value", "first_value", "last_value", "diff_previous_number", "max_diff_number",
    "diff_number_prev_max", "diff_number_prev_min", "lowest_value", "highest_value", "sum_value",
    "diff_lowest_value_period", "diff_highest_value_period", "standard_deviation",
    "average_reversal_size", "median_value",
}
# everything else in WINDOW_METRIC_KEYS is scale-invariant (percentage/ratio/count/shape):
# diff_*_percentage, range_percentage, volatility (=std/first), skewness, *_increases/_decreases,
# reversal_count, count_positive/negative, max_same_value (relative margin), sideways_* (percentage).
assert LEVEL_CALCS <= set(WINDOW_METRIC_KEYS), LEVEL_CALCS - set(WINDOW_METRIC_KEYS)


def scale_unsafe(indicator, calc):
    """True if a threshold derived from the indicator_metrics cache would be INVALID in the rule
    engine because of the volumeud relative-vs-raw scale mismatch. Such a candidate must never be
    shipped from a cache-only sweep — flag it SCALE_UNSAFE, not SAFE."""
    return indicator in SCALE_NORMALIZED_INDICATORS and calc in LEVEL_CALCS


def _cls(u):
    """LEGACY classifier on best_upside (potentie). Used for shadows in load_all_fires (no sell)."""
    return "goed" if u >= GOOD_UPSIDE else ("slecht" if u < BAD_UPSIDE else "middel")


def _cls_pl(pl):
    """REALIZED classifier on profit_loss — mirrors CoinFire::klasseKey() for executed trades.
    >=3% goed, 0..3% middel, <0% slecht. Used by the optimization routine since the UI shows this."""
    return "goed" if pl >= GOOD_PL else ("slecht" if pl < BAD_PL else "middel")


def load_trades():
    """Executed trades (both coins). Classified on profit_loss (realized result) to match the UI.
    The optimization routine therefore targets actually-realized winners/losers, not theoretical
    upside-within-the-hour — a rule's "slecht" now includes trades the sell-engine couldn't capture."""
    if not glob.glob(METRICS_GLOB):
        raise SystemExit("no indicator_metrics parquet — run build_indicator_metrics.py first")
    conn = brain()
    with conn.cursor() as c:
        c.execute("SELECT trading_symbol_id AS sym, datetime, rule, best_upside, profit_loss "
                  "FROM coin_fires WHERE is_executed=1 AND profit_loss IS NOT NULL")
        df = pd.DataFrame(c.fetchall())
    conn.close()
    # MySQL DECIMAL columns arrive as Python Decimal objects; cast to float so DuckDB registers
    # them as DOUBLE. Otherwise DuckDB infers a narrow DECIMAL type from the first value and an
    # outlier (e.g. best_upside 174.452 / profit_loss 159.45) overflows it in load_long().
    df["best_upside"] = pd.to_numeric(df["best_upside"], errors="coerce")
    df["profit_loss"] = pd.to_numeric(df["profit_loss"], errors="coerce")
    df["cls"] = df["profit_loss"].apply(_cls_pl)
    # per-coin time split: first 70% train, last 30% test
    df["split"] = "train"
    for sym, g in df.groupby("sym"):
        cut = g["datetime"].sort_values().iloc[int(len(g) * 0.7)]
        df.loc[(df["sym"] == sym) & (df["datetime"] > cut), "split"] = "test"
    return df


def load_long(trades=None):
    """Long table: one row per (trade x indicator x lookback x calc) -> value, joined to the
    indicator_metrics Parquet cache. NaN values dropped. Columns:
    sym, datetime, rule, cls, split, best_upside, indicator, lookback, calc, value."""
    if trades is None:
        trades = load_trades()
    con = duckdb.connect()
    con.register("trades", trades)
    wide = con.execute(f"""
        SELECT t.sym, t.datetime, t.rule, t.cls, t.split, t.best_upside,
               m.indicator, m.lookback, {','.join('m.' + c for c in CALC_COLS)}
        FROM read_parquet('{METRICS_GLOB}') m
        JOIN trades t ON m.trading_symbol_id=t.sym AND m.datetime=t.datetime
    """).df()
    con.close()
    long = wide.melt(
        id_vars=["sym", "datetime", "rule", "cls", "split", "best_upside", "indicator", "lookback"],
        value_vars=CALC_COLS, var_name="calc", value_name="value").dropna(subset=["value"])
    return long


# ---------------------------------------------------------------------------
# Fase 2 (schaalplan): gematerialiseerde PER-COIN long-parquet + fingerprint-cache. load_long doet de
# DuckDB-join+melt elke run opnieuw en wordt door meerdere rq-subprocessen herhaald. Per coin cachen
# (fp = metrics-scope + executed-trades-staat) laat STATIC coins de join+melt overslaan; de globale long
# = concat van de per-coin caches. load_long_cached() is bedoeld als drop-in voor load_long() ZONDER
# expliciete trades (een afwijkende trade-set, bv. simulate_brain_vf, valt terug op load_long).
LONG_DIR = os.path.join(HERE, "..", "data", "long")
_METRICS_DIR = os.path.join(HERE, "..", "data", "metrics")


def _long_fingerprint(sym):
    """Fingerprint van de per-coin long: de metrics-scope (indicator_metrics_state, gevuld door
    build_indicator_metrics) + de executed trades van deze coin. count+max(datetime) bepalen de 70/30
    tijd-split (die hangt aan de datetime-ordening); de cls-checksum (SUM(CRC32(datetime|rule|cls)))
    vangt een classificatie-RUIL bij gelijke counts. SUM, niet XOR (CRC32-lineariteit, zie test_opt_lib)."""
    conn = brain()
    with conn.cursor() as c:
        c.execute("SELECT fingerprint FROM indicator_metrics_state WHERE trading_symbol_id=%s", (sym,))
        r = c.fetchone(); mfp = r["fingerprint"] if r else "no-metrics"
        c.execute("SELECT COUNT(*) n, COALESCE(MAX(datetime),'') mx, "
                  "COALESCE(SUM(CRC32(CONCAT(datetime,'|',rule,'|',"
                  "CASE WHEN profit_loss>=3 THEN 'g' WHEN profit_loss<0 THEN 'b' ELSE 'm' END))),0) cx "
                  "FROM coin_fires WHERE trading_symbol_id=%s AND is_executed=1 AND profit_loss IS NOT NULL", (sym,))
        t = c.fetchone()
    conn.close()
    return hashlib.md5(f"{mfp}|fires:{t['n']}:{t['mx']}:{t['cx']}".encode()).hexdigest()[:16]


def _materialize_long(sym, trades_all):
    """Bouw de per-coin long-slice: de coin's trades (met hun reeds-bepaalde 70/30 split) gejoind met
    ALLEEN die coin's metrics-parquet. Identiek aan de globale load_long, maar per coin."""
    tr = trades_all[trades_all["sym"] == sym]
    fpath = os.path.join(_METRICS_DIR, f"indicator_metrics_{sym}.parquet")
    if tr.empty or not os.path.exists(fpath):
        return None
    con = duckdb.connect(); con.register("trades", tr)
    wide = con.execute(f"""
        SELECT t.sym, t.datetime, t.rule, t.cls, t.split, t.best_upside,
               m.indicator, m.lookback, {','.join('m.' + c for c in CALC_COLS)}
        FROM read_parquet('{fpath}') m
        JOIN trades t ON m.trading_symbol_id=t.sym AND m.datetime=t.datetime
    """).df()
    con.close()
    return wide.melt(
        id_vars=["sym", "datetime", "rule", "cls", "split", "best_upside", "indicator", "lookback"],
        value_vars=CALC_COLS, var_name="calc", value_name="value").dropna(subset=["value"])


def load_long_cached(trades=None):
    """Drop-in voor load_long(): per-coin gematerialiseerde long-parquet met fingerprint-cache. Een coin
    waarvan de fingerprint matcht wordt direct van schijf gelezen (geen join+melt). De globale long =
    concat van de per-coin slices over alle coins MET executed trades. Met een expliciete `trades`
    (afwijkende set) valt het terug op load_long want die cache hoort bij de default trade-set."""
    if trades is not None:
        return load_long(trades=trades)
    trades_all = load_trades()
    os.makedirs(LONG_DIR, exist_ok=True)
    parts = []
    for sym in sorted(trades_all["sym"].unique()):
        fp = _long_fingerprint(int(sym))
        fpath = os.path.join(LONG_DIR, f"long_{int(sym)}__{fp}.parquet")
        if os.path.exists(fpath):
            parts.append(pd.read_parquet(fpath))
            continue
        lg = _materialize_long(int(sym), trades_all)
        if lg is None or lg.empty:
            continue
        lg.to_parquet(fpath, index=False)
        for old in glob.glob(os.path.join(LONG_DIR, f"long_{int(sym)}__*.parquet")):
            if old != fpath:
                try:
                    os.remove(old)
                except OSError:
                    pass
        parts.append(lg)
    if not parts:
        return load_long()
    return pd.concat(parts, ignore_index=True)


# ---------------------------------------------------------------------------
# Principle 2 — threshold at the BAD EDGE (in the gap), keeping ALL good by construction.
# ---------------------------------------------------------------------------
def bad_edge_conditions(good_vals, bad_vals):
    """Given good/bad value arrays for ONE feature, return candidate single conditions placed at
    the bad edge. A lower bound keeps value >= threshold; an upper bound keeps value <= threshold.

    lower: threshold = highest bad strictly below the good band (good.min). Drops bad < threshold,
           keeps the single borderline bad (buffer) AND all good (all good >= good.min > threshold).
    upper: threshold = lowest bad strictly above the good band (good.max). Symmetric.
    Returns list of dicts {bound, threshold, drop_insample}."""
    good = np.asarray(good_vals, dtype=float)
    bad = np.asarray(bad_vals, dtype=float)
    out = []
    if len(good) == 0 or len(bad) == 0:
        return out
    gmin, gmax = good.min(), good.max()
    bb = bad[bad < gmin]
    ba = bad[bad > gmax]
    if len(bb):
        thr = float(bb.max())
        drop = int((bad < thr).sum())
        if drop > 0:
            out.append({"bound": "lower", "threshold": round(thr, 5), "drop_insample": drop})
    if len(ba):
        thr = float(ba.min())
        drop = int((bad > thr).sum())
        if drop > 0:
            out.append({"bound": "upper", "threshold": round(thr, 5), "drop_insample": drop})
    return out


def _passes(values, bound, threshold):
    v = np.asarray(values, dtype=float)
    return (v >= threshold) if bound == "lower" else (v <= threshold)


def oos_metrics(tr_good, tr_bad, te_good, te_bad, bound, threshold):
    """Out-of-sample scoring of a single condition whose threshold is given (already derived on a
    train slice). Returns good_keep (fraction of held-out GOOD that survives — must be ~1.0) and
    bad_drop (fraction of held-out BAD dropped — the benefit)."""
    te_good = np.asarray(te_good, dtype=float)
    te_bad = np.asarray(te_bad, dtype=float)
    good_keep = float(_passes(te_good, bound, threshold).mean()) if len(te_good) else float("nan")
    bad_drop = float((~_passes(te_bad, bound, threshold)).mean()) if len(te_bad) else float("nan")
    return {"good_keep": round(good_keep, 3), "bad_drop": round(bad_drop, 3),
            "n_te_good": int(len(te_good)), "n_te_bad": int(len(te_bad))}


# ---------------------------------------------------------------------------
# Single-condition sweep over the full grid for one rule (pooled over both coins by default).
# ---------------------------------------------------------------------------
def sweep_single(long, rule, min_good=5, min_bad=5):
    """Every (indicator, lookback, calc) for `rule`: place a bad-edge condition (in-sample on the
    pooled good/bad) and report how many bad it drops at zero good loss. Descriptive — feed the
    survivors to validate_* for the out-of-sample gate."""
    sub = long[long["rule"] == rule]
    rows = []
    for (ind, lb, calc), g in sub.groupby(["indicator", "lookback", "calc"]):
        good = g[g["cls"] == "goed"]["value"]
        bad = g[g["cls"] == "slecht"]["value"]
        if len(good) < min_good or len(bad) < min_bad:
            continue
        for cond in bad_edge_conditions(good, bad):
            rows.append({"rule": rule, "indicator": ind, "lookback": int(lb), "calc": calc,
                         "n_good": int(len(good)), "n_bad": int(len(bad)), **cond})
    return pd.DataFrame(rows).sort_values("drop_insample", ascending=False) if rows else pd.DataFrame()


def validate_timesplit(long, rule, ind, lb, calc, bound,
                       min_tr_good=5, min_tr_bad=5, min_te_good=3, min_te_bad=3, g=None):
    """Per-coin TIME split (train 70 / test 30, pooled): derive the bad-edge threshold on train,
    score on test. Returns None if too little data. The OOS safety gate from validate_subrules.py.
    Fase 3: optioneel `g` = het al op (rule,ind,lb,calc) gefilterde subframe (groep-cache) — bit-
    identiek aan zelf filteren, maar bespaart de O(long) boolean-scan per kandidaat per split."""
    if g is None:
        g = long[(long["rule"] == rule) & (long["indicator"] == ind) &
                 (long["lookback"] == lb) & (long["calc"] == calc)]
    tr_good = g[(g["split"] == "train") & (g["cls"] == "goed")]["value"]
    tr_bad = g[(g["split"] == "train") & (g["cls"] == "slecht")]["value"]
    te_good = g[(g["split"] == "test") & (g["cls"] == "goed")]["value"]
    te_bad = g[(g["split"] == "test") & (g["cls"] == "slecht")]["value"]
    if (len(tr_good) < min_tr_good or len(tr_bad) < min_tr_bad
            or len(te_good) < min_te_good or len(te_bad) < min_te_bad):
        return None
    conds = {c["bound"]: c for c in bad_edge_conditions(tr_good, tr_bad)}
    if bound not in conds:
        return None
    thr = conds[bound]["threshold"]
    m = oos_metrics(tr_good, tr_bad, te_good, te_bad, bound, thr)
    return {"split": "time", "threshold": thr, "train_drop": conds[bound]["drop_insample"], **m}


def validate_crosscoin(long, rule, ind, lb, calc, bound, train_syms, test_sym,
                       min_tr_good=5, min_tr_bad=5, min_te_good=3, min_te_bad=3, g=None):
    """CROSS-COIN split: leid de bad-edge-drempel af op de samengevoegde TRAIN-coins (een lijst of een
    enkele id), evalueer op test_sym. Robuustheidstoets: houdt een band die op N-1 munten ontstond
    stand op de overgebleven munt? Schaalt naar N coins (LOO via crosscoin_splits()).
    Fase 3: optioneel `g` = het al op (rule,ind,lb,calc) gefilterde subframe (groep-cache)."""
    if isinstance(train_syms, int):
        train_syms = (train_syms,)
    if g is None:
        g = long[(long["rule"] == rule) & (long["indicator"] == ind) &
                 (long["lookback"] == lb) & (long["calc"] == calc)]
    tr_good = g[(g["sym"].isin(train_syms)) & (g["cls"] == "goed")]["value"]
    tr_bad = g[(g["sym"].isin(train_syms)) & (g["cls"] == "slecht")]["value"]
    te_good = g[(g["sym"] == test_sym) & (g["cls"] == "goed")]["value"]
    te_bad = g[(g["sym"] == test_sym) & (g["cls"] == "slecht")]["value"]
    if (len(tr_good) < min_tr_good or len(tr_bad) < min_tr_bad
            or len(te_good) < min_te_good or len(te_bad) < min_te_bad):
        return None
    conds = {c["bound"]: c for c in bad_edge_conditions(tr_good, tr_bad)}
    if bound not in conds:
        return None
    thr = conds[bound]["threshold"]
    m = oos_metrics(tr_good, tr_bad, te_good, te_bad, bound, thr)
    tr_label = "+".join(str(x) for x in train_syms)
    return {"split": f"{tr_label}->{test_sym}", "threshold": thr,
            "train_drop": conds[bound]["drop_insample"], **m}


def full_validation(long, rule, ind, lb, calc, bound, g=None):
    """Out-of-sample splits voor één kandidaat: tijd-split + LEAVE-ONE-OUT cross-coin (per coin: train op
    alle anderen, test op deze). Een kandidaat is SAFE iff good_keep ~ 1.0 op ÉLKE split met genoeg data.
    Schaalt automatisch naar N coins (crosscoin_splits() uit brain.coins via coins.py).
    Fase 3: optioneel `g` = het op (rule,ind,lb,calc) gefilterde subframe (groep-cache); doorgegeven aan
    elke split-validatie zodat de O(long) boolean-scan niet per split herhaald wordt. Bit-identiek."""
    res = {}
    t = validate_timesplit(long, rule, ind, lb, calc, bound, g=g)
    if t:
        res["time"] = t
    for tr, te in crosscoin_splits():
        cc = validate_crosscoin(long, rule, ind, lb, calc, bound, tr, te, g=g)
        if cc:
            res[cc["split"]] = cc
    return res


# ---------------------------------------------------------------------------
# TOEVAL-TOETS (permutation test) on the bad-drop of a candidate — the guard the OOS good_keep gate
# does NOT give. full_validation only checks that GOOD survives out-of-sample; it never asks whether
# the BAD-separation itself is more than the sweep dredging an edge from noise. This does: derive the
# bad-edge threshold on the held-out TRAIN split, measure the TEST-bad it drops at 0 TEST-good lost
# (observed), then SHUFFLE the good/slecht labels, re-derive on the shuffled train and re-measure on
# test. p = fraction of shuffles whose drop >= observed (at 0 good lost). Low p = the separation is
# unlikely from chance. Mirrors subrule_power.perm_p_test, but on the cache long-table the candidate
# was actually derived from. See docs/methodology/rule-discovery.md §4b and CLAUDE.md.
# ---------------------------------------------------------------------------
def sidak(p, n_hyp):
    """Šidák family-wise correction for n_hyp simultaneous tests: 1-(1-p)^n_hyp, clamped to [0,1]."""
    if p is None:
        return None
    n_hyp = max(1, int(n_hyp))
    return float(min(1.0, 1.0 - (1.0 - p) ** n_hyp))


def required_raw_p(n_hyp, alpha=0.05):
    """The raw per-test p needed so the Šidák-corrected p stays < alpha across n_hyp tests."""
    n_hyp = max(1, int(n_hyp))
    return 1.0 - (1.0 - alpha) ** (1.0 / n_hyp)


def permutation_pvalue(long, rule, ind, lb, calc, bound, n_perm=400, seed=42,
                       min_te_good=3, min_te_bad=3):
    """Toeval-toets for ONE single-condition candidate, on the held-out TEST split (the per-coin
    70/30 split already in `long.split`), pooled over both coins. Returns
    {p, obs_drop, n_te_good, n_te_bad, n_perm} or None when there is too little held-out data to
    test (caller must treat None as "kan niet certificeren")."""
    g = long[(long["rule"] == rule) & (long["indicator"] == ind) &
             (long["lookback"] == lb) & (long["calc"] == calc)]
    tr_vals = g[g["split"] == "train"]["value"].to_numpy(dtype=float)
    tr_cls = g[g["split"] == "train"]["cls"].to_numpy(dtype=object)
    te_vals = g[g["split"] == "test"]["value"].to_numpy(dtype=float)
    te_cls = g[g["split"] == "test"]["cls"].to_numpy(dtype=object)
    n_te_good = int((te_cls == "goed").sum()); n_te_bad = int((te_cls == "slecht").sum())
    n_tr_good = int((tr_cls == "goed").sum()); n_tr_bad = int((tr_cls == "slecht").sum())
    if n_te_good < min_te_good or n_te_bad < min_te_bad or n_tr_good < 2 or n_tr_bad < 2:
        return None

    def _edge(vals, cls):
        # DEZELFDE bad-edge die rq1 deploy't (bad_edge_conditions), NIET good.min/max — anders toetst
        # de toeval-toets een andere drempel dan de gate toepast. Nodig: zowel goede als slechte waarden.
        good = vals[cls == "goed"]; bad = vals[cls == "slecht"]
        if good.size == 0 or bad.size == 0:
            return None
        conds = {c["bound"]: c for c in bad_edge_conditions(good, bad)}
        return conds[bound]["threshold"] if bound in conds else None

    def _drop(thr, vals, cls):
        # band houdt value binnen (>= thr lower / <= thr upper, buffer-bad op de rand blijft); telt
        # de test-bad die BUITEN de band valt (de winst) + eventueel verloren goede. Spiegelt oos_metrics.
        out = (vals < thr - 1e-9) if bound == "lower" else (vals > thr + 1e-9)
        return int(((cls == "slecht") & out).sum()), int(((cls == "goed") & out).sum())

    thr = _edge(tr_vals, tr_cls)
    if thr is None:
        return None
    obs_drop, obs_gl = _drop(thr, te_vals, te_cls)
    if obs_gl != 0 or obs_drop <= 0:
        return None                          # geen schone OOS-scheiding of geen test-drop -> niet toetsbaar

    rng = np.random.default_rng(seed)
    all_cls = np.concatenate([tr_cls, te_cls])
    n_tr = tr_vals.shape[0]
    ge = 0
    for _ in range(n_perm):
        sh = all_cls.copy(); rng.shuffle(sh)
        t = _edge(tr_vals, sh[:n_tr])
        if t is None:
            continue                         # shuffle vormt geen bad-edge -> drop 0 -> verslaat obs (>=1) niet
        bd, gl = _drop(t, te_vals, sh[n_tr:])
        if gl == 0 and bd >= obs_drop:
            ge += 1
    return {"p": (ge + 1) / (n_perm + 1), "obs_drop": obs_drop,
            "n_te_good": n_te_good, "n_te_bad": n_te_bad, "n_perm": n_perm}


def signflip_pvalue(deltas, n_perm=4000, seed=42):
    """Gepaarde sign-flip toeval-toets voor een NETTO verbetering (gebruikt door de sell-tuning: deltas =
    per-trade (tuned_pl − base_pl) van de trades die een instelknop ECHT raakt). H0: de knop heeft geen
    systematisch netto-effect — de per-trade verschillen zijn symmetrisch rond 0 (evenveel kans + als −).
    Statistiek = Σ deltas (de netto Σprofit-ruil). p = aandeel willekeurige teken-toewijzingen waarvan de
    som ≥ de waargenomen som (met de standaard +1-demping). Anders dan permutation_pvalue (die aan de
    goed/slecht-edge hangt) toetst dit een netto-grootheid — exact de sell-meetlat.

    Geeft {p, n, floor, obs} of None als geen enkele delta ≠ 0. floor = de kleinste p die de toets KAN
    halen = max(1/2^n, 1/(n_perm+1)); de aanroeper behandelt floor > vereiste-p als 'kan niet
    certificeren' (te weinig geraakte trades — meer data nodig, niet relaxen). obs ≤ 0 → p = 1.0 (geen
    netto-winst om te certificeren)."""
    d = np.asarray([float(x) for x in deltas if abs(float(x)) > 1e-9], dtype=float)
    n = int(d.shape[0])
    if n == 0:
        return None
    floor = max((1.0 / (2 ** n)) if n < 30 else 0.0, 1.0 / (n_perm + 1))
    obs = float(d.sum())
    if obs <= 1e-12:
        return {"p": 1.0, "n": n, "floor": floor, "obs": obs}
    rng = np.random.default_rng(seed)
    signs = rng.choice(np.array([-1.0, 1.0]), size=(n_perm, n))   # willekeurige ± per trade per schudbeurt
    perm_sums = signs @ d                                          # Σ per schudbeurt (gevectoriseerd)
    ge = int((perm_sums >= obs - 1e-9).sum())
    return {"p": (ge + 1) / (n_perm + 1), "n": n, "floor": floor, "obs": obs}


def load_all_fires():
    """ALL fires (executed AND shadow), both coins, with rule + best_upside class. Shadows matter
    for redundancy: single-position dedup means only one rule executes at a time, so executed-only
    overlap is always ~0 (an artefact). A shadow records that another rule ALSO fired there."""
    conn = brain()
    with conn.cursor() as c:
        c.execute("SELECT trading_symbol_id AS sym, datetime, rule, is_executed, best_upside "
                  "FROM coin_fires WHERE best_upside IS NOT NULL")
        df = pd.DataFrame(c.fetchall())
    conn.close()
    df["cls"] = df["best_upside"].apply(_cls)
    return df


def rule_overlap(tol_minutes=3, fires=None):
    """RQ3 — redundancy. Population per rule X = its EXECUTED GOOD trades. Coverage by rule Y = Y
    has ANY fire (executed or shadow) within +/- tol_minutes (same coin) — i.e. if X were removed,
    Y would have fired there too. union = ANY other rule fires there. A rule whose executed-good
    trades are ~fully covered by others is a drop candidate. Uses all fires (shadows included)."""
    if fires is None:
        fires = load_all_fires()
    tol = pd.Timedelta(minutes=tol_minutes)
    rules = sorted(fires["rule"].unique())
    exec_good = fires[(fires["is_executed"] == 1) & (fires["cls"] == "goed")]
    pair_rows, union_rows = [], []
    for X in rules:
        gx = exec_good[exec_good["rule"] == X]
        if gx.empty:
            continue
        covered_union = 0
        for _, r in gx.iterrows():
            others = fires[(fires["rule"] != X) & (fires["sym"] == r["sym"]) &
                           (fires["datetime"] >= r["datetime"] - tol) &
                           (fires["datetime"] <= r["datetime"] + tol)]
            if not others.empty:
                covered_union += 1
        union_rows.append({"rule": X, "n_exec_good": len(gx), "covered_by_others": covered_union,
                           "pct_covered": round(covered_union / len(gx) * 100, 1)})
        for Y in rules:
            if Y == X:
                continue
            fy = fires[fires["rule"] == Y]
            cov = 0
            for _, r in gx.iterrows():
                m = fy[(fy["sym"] == r["sym"]) & (fy["datetime"] >= r["datetime"] - tol) &
                       (fy["datetime"] <= r["datetime"] + tol)]
                if not m.empty:
                    cov += 1
            pair_rows.append({"X": X, "Y": Y, "n_exec_good_X": len(gx), "covered_by_Y": cov,
                              "pct": round(cov / len(gx) * 100, 1)})
    return pd.DataFrame(pair_rows), pd.DataFrame(union_rows)


if __name__ == "__main__":
    # 1) unit-test the bad-edge placement against the brain-rule-tuning worked example:
    #    good lower bound -20; the highest bad below it is -30 -> lower bound at -30 (not -20),
    #    dropping the bad strictly below -30, keeping the -30 buffer bad and all good.
    good = [-20, -10, 0, 5, 12, 30]
    bad = [-45, -30, 8]                          # highest bad below -20 is -30; -45 dropped, -30 kept
    conds = bad_edge_conditions(good, bad)
    low = [c for c in conds if c["bound"] == "lower"]
    assert low and abs(low[0]["threshold"] - (-30)) < 1e-9 and low[0]["drop_insample"] == 1, conds
    # upper: good max 30; lowest bad above none -> no upper condition
    assert not [c for c in conds if c["bound"] == "upper"], conds
    print("unit-test bad_edge_conditions: PASS", low[0])

    # 1b) unit-test the scale-validity guard against the report's worked case:
    #     volumeud/median_value (level) is UNSAFE; volumeud/range_percentage (ratio) is SAFE;
    #     a non-normalised indicator is never affected.
    assert scale_unsafe("volumeud", "median_value")            # the rejected rule-22 candidate
    assert scale_unsafe("volumeud", "diff_number_prev_min")    # the rejected rule-21 fallback
    assert not scale_unsafe("volumeud", "range_percentage")    # the accepted rule-22 replacement
    assert not scale_unsafe("volumeud", "diff_percentage_prev_max")  # accepted rule-21 primary
    assert not scale_unsafe("vzo", "median_value")             # vzo is not normalised -> safe
    print("unit-test scale_unsafe: PASS")

    # 1c) unit-test the Šidák math + the toeval-toets: a CLEAN separation must score a low p, pure
    #     NOISE a high p, and the family correction must inflate a borderline single p above 0.05.
    assert abs(sidak(0.05, 1) - 0.05) < 1e-9 and sidak(0.0, 9) == 0.0
    assert abs(required_raw_p(1) - 0.05) < 1e-9 and required_raw_p(124) < 0.0005
    assert sidak(0.02, 50) > 0.05            # a "significant-looking" single p is noise across 50 tests

    # 1d) unit-test de sign-flip toeval-toets (sell-tuning): een consistente netto-winst over genoeg
    #     trades scoort laag, pure ruis hoog, en te weinig geraakte trades geeft een floor die niet
    #     onder 0.05 kan komen (kan-niet-certificeren).
    assert signflip_pvalue([0.0, 0.0]) is None                      # geen effect → niet toetsbaar
    sf_clean = signflip_pvalue([2.0] * 12)                          # 12× duidelijk positief
    assert sf_clean["p"] < 0.01 and sf_clean["n"] == 12
    sf_noise = signflip_pvalue([3.0, -2.8, 2.5, -3.1, 2.9, -2.6])   # gemengd, som ~0
    assert sf_noise["p"] > 0.05
    sf_thin = signflip_pvalue([1.0, 1.0, 1.0, 1.0])                 # 4 trades: floor 1/16 = 0.0625
    assert abs(sf_thin["floor"] - 0.0625) < 1e-9 and sf_thin["floor"] > 0.05
    assert signflip_pvalue([-1.0, -2.0, 0.5])["p"] == 1.0           # netto negatief → p=1 (niets te certificeren)
    print("unit-test signflip_pvalue: PASS")

    def _synth(values_by_cls):
        rows = []
        for cls, vals in values_by_cls.items():
            for i, v in enumerate(vals):
                rows.append({"rule": 1, "indicator": "x", "lookback": 1, "calc": "c",
                             "split": "train" if i % 10 < 7 else "test", "cls": cls, "value": float(v)})
        return pd.DataFrame(rows)
    rng = np.random.default_rng(0)
    # CLEAN: good clustered high, bad clustered low -> upper bad-edge separates them out-of-sample.
    clean = _synth({"goed": list(rng.normal(10, 0.5, 60)), "slecht": list(rng.normal(0, 0.5, 60))})
    pr = permutation_pvalue(clean, 1, "x", 1, "c", "lower", n_perm=400, seed=1)
    assert pr and pr["p"] < 0.05, pr
    # NOISE: good and bad from the SAME distribution -> no real separation. The test must NOT certify
    # it: either it isn't even clean out-of-sample (None) or its p is high. Both = "geen echte edge".
    noise = _synth({"goed": list(rng.normal(5, 1, 60)), "slecht": list(rng.normal(5, 1, 60))})
    pn = permutation_pvalue(noise, 1, "x", 1, "c", "lower", n_perm=400, seed=1)
    assert pn is None or pn["p"] > 0.10, pn
    noise_p = "None" if pn is None else f"{pn['p']:.4f}"
    print(f"unit-test permutation_pvalue: PASS (clean p={pr['p']:.4f} < noise p={noise_p})")

    # 2) smoke-test the cache join + sweep on real data
    long = load_long()
    print("trades loaded:", long[["sym", "datetime"]].drop_duplicates().shape[0],
          "| executed good/bad pooled:",
          long.drop_duplicates(["sym", "datetime"]).cls.value_counts().to_dict())
    for rule in (20, 21, 22, 23):
        sw = sweep_single(long, rule)
        n = 0 if sw.empty else len(sw)
        top = "" if sw.empty else (f" | top: {sw.iloc[0]['indicator']}/{sw.iloc[0]['calc']}/lb"
                                   f"{sw.iloc[0]['lookback']} {sw.iloc[0]['bound']}<= "
                                   f"{sw.iloc[0]['threshold']} drops {sw.iloc[0]['drop_insample']}")
        print(f"rule {rule}: {n} single bad-edge candidates{top}")
