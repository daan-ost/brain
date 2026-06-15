"""
new_feat_lib — candidate NEW deterministic features for separating GOOD from BAD trades, beyond the
existing 31 single-indicator window statistics (calc.WINDOW_METRIC_KEYS).

Every feature is a LEAK-FREE, AS-OF scalar computed at datetime T from the RAW engine series
(brain.indicators, via a RuleEngine-like object exposing `_vals(indicator, n, T)`). The engine
evaluates a subrule as ONE scalar vs [b_min, b_max], so each feature reduces a multi-point /
multi-series view to a single number. Computed exactly the way an engine subrule would be — so a
feature that separates here is directly shippable as a subrule (no cache/scale mismatch: thresholds
are derived AND evaluated on the same raw series).

Families (the owner's hypothesis about WHERE the missed multivariate edge lives):
  A interaction  — cross-indicator / volume×momentum / price-vs-indicator divergence
  B shape        — slope, acceleration, curvature, cleanliness of the move (price-shape is genuinely
                   new: the 31 are computed on indicator VALUES, not on price dynamics)
  C context      — distance to recent high/low, volatility regime, position within the move
  D sequence     — volume-lead-vs-lag, short-vs-long lookback comparison (is volume/momentum
                   ACCELERATING into the entry?)

SCALE DISCIPLINE (the volumeud lesson): volumeud is a raw, coin-dependent level (DOGEAI ~30x NOS).
A raw-level volume feature does NOT transfer cross-coin. So every volume feature here is scale-free
by construction — a WITHIN-WINDOW ratio (value / median(window)) or a percentage / z-score. Indicator
channels (vzo/mfi/phobos/obv) are already bounded and comparable across coins.

Channels available via channel(eng, name, n, T), newest-first (index 0 = most recent at/before T):
  "price"  — the volumeud price column (genuine price dynamics)
  "volume" — the volumeud value column (RAW; only ever used through scale-free transforms below)
  "vzo" / "mfi" / "phobos" / "obv-x-value" — the indicator value series
"""
import numpy as np

INDS = ("vzo", "mfi", "phobos", "obv-x-value")
LOOKBACKS = (3, 5, 7, 10, 14, 20)


# ---------------------------------------------------------------------------
# channel extraction (newest-first), leak-free as-of via eng._vals
# ---------------------------------------------------------------------------
def channel(eng, name, n, T):
    if name == "price":
        _, p = eng._vals("volumeud", n, T)
        return [float(x) for x in p]
    if name == "volume":
        v, _ = eng._vals("volumeud", n, T)
        return [float(x) for x in v]
    v, _ = eng._vals(name, n, T)
    return [float(x) for x in v]


# ---------------------------------------------------------------------------
# shape primitives on a newest-first list x (x[0] = most recent)
# ---------------------------------------------------------------------------
def _ols_slope(x):
    """Per-step OLS slope over time (oldest->newest). Positive = rising into the entry."""
    if len(x) < 2:
        return None
    y = np.asarray(x[::-1], float)
    t = np.arange(len(y), dtype=float)
    tm = t.mean()
    denom = ((t - tm) ** 2).sum()
    if denom == 0:
        return 0.0
    return float(((t - tm) * (y - y.mean())).sum() / denom)


def _zslope(x):
    """Slope of the WITHIN-WINDOW z-scored series — scale-free, comparable across channels/coins."""
    if len(x) < 2:
        return None
    y = np.asarray(x[::-1], float)
    sd = y.std()
    if sd == 0:
        return 0.0
    z = (y - y.mean()) / sd
    t = np.arange(len(z), dtype=float)
    tm = t.mean()
    denom = ((t - tm) ** 2).sum()
    if denom == 0:
        return 0.0
    return float(((t - tm) * (z - z.mean())).sum() / denom)


def _accel(x):
    """Acceleration: z-slope of the recent half minus z-slope of the older half (>0 = accelerating,
    <0 = stalling). Captures 'the move is still building' vs 'the move is petering out'."""
    if len(x) < 4:
        return None
    h = len(x) // 2
    sr, so = _zslope(x[:h]), _zslope(x[h:])
    if sr is None or so is None:
        return None
    return sr - so


def _up_frac(x):
    """Fraction of consecutive steps (oldest->newest) that are up — cleanliness of the rise."""
    if len(x) < 2:
        return None
    y = x[::-1]
    return sum(1 for i in range(1, len(y)) if y[i] > y[i - 1]) / (len(y) - 1)


def _pos_in_range(x):
    """Where the current value sits in the window's [min,max] — 1 = at the high (late/topped),
    0 = at the low (buying the dip)."""
    if len(x) < 2:
        return None
    hi, lo = max(x), min(x)
    if hi == lo:
        return None
    return (x[0] - lo) / (hi - lo)


def _gap_below_high(x):
    """How far the current value is below the recent high, in window-range units (0 = at the high)."""
    if len(x) < 2:
        return None
    hi, lo = max(x), min(x)
    if hi == lo:
        return None
    return (hi - x[0]) / (hi - lo)


def _reversals(x):
    """Direction reversals in the window (choppiness numerator)."""
    if len(x) < 3:
        return None
    cnt = 0
    trend = None
    y = x[::-1]
    for i in range(1, len(y)):
        if y[i] > y[i - 1]:
            if trend == "down":
                cnt += 1
            trend = "up"
        elif y[i] < y[i - 1]:
            if trend == "up":
                cnt += 1
            trend = "down"
    return cnt


def _max_step_pct(x):
    """Largest single-step % move in the window — a big single jump = 'already pumped in one tick'."""
    if len(x) < 2:
        return None
    y = x[::-1]
    best = 0.0
    for i in range(1, len(y)):
        if y[i - 1] != 0:
            best = max(best, abs((y[i] - y[i - 1]) / abs(y[i - 1]) * 100))
    return best


def _relvol0(vol):
    """Most-recent volume relative to the window median — scale-free volume spike size."""
    if len(vol) < 2:
        return None
    med = float(np.median(vol))
    if med == 0:
        return None
    return vol[0] / med


# ---------------------------------------------------------------------------
# FEATURE REGISTRY — built by factories so each base feature sweeps over lookbacks.
# Each entry: (family, name, fn(eng, T) -> float|None).
# ---------------------------------------------------------------------------
def _safe(fn):
    def g(eng, T):
        try:
            return fn(eng, T)
        except Exception:
            return None
    return g


def build_registry():
    reg = {}

    def add(family, name, fn):
        reg[name] = (family, _safe(fn))

    for lb in LOOKBACKS:
        # ---- B SHAPE (price dynamics — genuinely new vs the indicator-value 31) ----
        add("shape", f"price_zslope_lb{lb}", lambda e, T, lb=lb: _zslope(channel(e, "price", lb, T)))
        add("shape", f"price_accel_lb{lb}", lambda e, T, lb=lb: _accel(channel(e, "price", lb, T)))
        add("shape", f"price_upfrac_lb{lb}", lambda e, T, lb=lb: _up_frac(channel(e, "price", lb, T)))
        add("shape", f"price_posrange_lb{lb}", lambda e, T, lb=lb: _pos_in_range(channel(e, "price", lb, T)))
        add("shape", f"price_chop_lb{lb}",
            lambda e, T, lb=lb: (lambda r: None if r is None else r / lb)(_reversals(channel(e, "price", lb, T))))
        add("shape", f"price_maxstep_lb{lb}", lambda e, T, lb=lb: _max_step_pct(channel(e, "price", lb, T)))
        add("shape", f"vol_accel_lb{lb}", lambda e, T, lb=lb: _accel(channel(e, "volume", lb, T)))

        # ---- C CONTEXT / REGIME ----
        add("context", f"price_gaphigh_lb{lb}", lambda e, T, lb=lb: _gap_below_high(channel(e, "price", lb, T)))
        add("context", f"price_vsmean_lb{lb}",
            lambda e, T, lb=lb: (lambda x: None if len(x) < 2 or np.std(x) == 0
                                 else float((x[0] - np.mean(x)) / np.std(x)))(channel(e, "price", lb, T)))
        if lb >= 6:
            add("context", f"price_volregime_lb{lb}",
                lambda e, T, lb=lb: (lambda x: None if len(x) < lb or np.std(x) == 0
                                     else float(np.std(x[: lb // 2]) / np.std(x)))(channel(e, "price", lb, T)))
        add("context", f"vol_spike_age_lb{lb}",
            lambda e, T, lb=lb: (lambda v: None if len(v) < 2 else float(int(np.argmax(v))))(channel(e, "volume", lb, T)))

        # ---- A INTERACTION (cross-indicator / volume×momentum / divergence) ----
        for ind in INDS:
            add("interaction", f"div_price_{ind}_lb{lb}",
                lambda e, T, lb=lb, ind=ind: (lambda a, b: None if a is None or b is None else a - b)(
                    _zslope(channel(e, "price", lb, T)), _zslope(channel(e, ind, lb, T))))
        add("interaction", f"confirm_count_lb{lb}",
            lambda e, T, lb=lb: float(sum(1 for ind in INDS
                                          if (_zslope(channel(e, ind, lb, T)) or 0) > 0)))
        add("interaction", f"volmom_price_lb{lb}",
            lambda e, T, lb=lb: (lambda rv, sl: None if rv is None or sl is None else rv * sl)(
                _relvol0(channel(e, "volume", lb, T)), _zslope(channel(e, "price", lb, T))))
        add("interaction", f"volmom_vzo_lb{lb}",
            lambda e, T, lb=lb: (lambda rv, sl: None if rv is None or sl is None else rv * sl)(
                _relvol0(channel(e, "volume", lb, T)), _zslope(channel(e, "vzo", lb, T))))
        add("interaction", f"vol_vs_price_lb{lb}",
            lambda e, T, lb=lb: (lambda a, b: None if a is None or b is None else a - b)(
                _zslope(channel(e, "volume", lb, T)), _zslope(channel(e, "price", lb, T))))

        # ---- D SEQUENCE (volume lead/lag) ----
        add("sequence", f"vol_lead_price_lb{lb}",
            lambda e, T, lb=lb: _vol_lead(channel(e, "volume", lb, T), channel(e, "price", lb, T)))

    # ---- D SHORT-vs-LONG (fixed pairs; "accelerating into the entry") ----
    def sl_zslope(e, T, ch, s, l):
        a, b = _zslope(channel(e, ch, s, T)), _zslope(channel(e, ch, l, T))
        return None if a is None or b is None else a - b

    add("sequence", "price_zslope_short_minus_long", lambda e, T: sl_zslope(e, T, "price", 3, 14))
    add("sequence", "vzo_zslope_short_minus_long", lambda e, T: sl_zslope(e, T, "vzo", 3, 14))
    add("sequence", "mfi_zslope_short_minus_long", lambda e, T: sl_zslope(e, T, "mfi", 3, 14))
    add("sequence", "phobos_zslope_short_minus_long", lambda e, T: sl_zslope(e, T, "phobos", 3, 14))

    def vol_sl(e, T, s, l):
        vs, vl = channel(e, "volume", s, T), channel(e, "volume", l, T)
        if len(vs) < 2 or len(vl) < 2:
            return None
        ms, ml = float(np.median(vs)), float(np.median(vl))
        return None if ml == 0 else ms / ml

    add("sequence", "vol_ratio_short_long_3_14", lambda e, T: vol_sl(e, T, 3, 14))
    add("sequence", "vol_ratio_short_long_3_20", lambda e, T: vol_sl(e, T, 3, 20))
    return reg


def _vol_lead(vol, price):
    """Sequence: index of the biggest volume spike minus index of the biggest price step (both in
    oldest->newest indexing). >0 => the volume spike came BEFORE the biggest price move (volume led);
    <0 => volume followed price. A clean breakout often has volume leading."""
    if len(vol) < 2 or len(price) < 2:
        return None
    v = vol[::-1]
    p = price[::-1]
    steps = [abs(p[i] - p[i - 1]) for i in range(1, len(p))]
    if not steps:
        return None
    i_vol = int(np.argmax(v))
    i_step = int(np.argmax(steps)) + 1  # step i is between p[i-1],p[i]
    return float(i_step - i_vol)


REGISTRY = build_registry()
FAMILIES = ("interaction", "shape", "context", "sequence")


def features_for_family(family):
    return {n: fn for n, (fam, fn) in REGISTRY.items() if family == "all" or fam == family}


if __name__ == "__main__":
    # wiring self-test: instantiate the engine for DOGEAI, compute every feature at one good trade,
    # report how many produce a finite value (catches None/exception wiring bugs).
    import sys
    from rule_engine import RuleEngine
    from db import brain

    sym = int(sys.argv[1]) if len(sys.argv) > 1 else 2525
    conn = brain()
    with conn.cursor() as c:
        c.execute("SELECT datetime FROM coin_fires WHERE trading_symbol_id=%s AND is_executed=1 "
                  "AND best_upside>=3 ORDER BY datetime LIMIT 1", (sym,))
        T = c.fetchone()["datetime"]
    conn.close()
    eng = RuleEngine(sym)
    ok = bad = 0
    by_fam = {f: [0, 0] for f in FAMILIES}
    for name, (fam, fn) in REGISTRY.items():
        v = fn(eng, T)
        if v is None or (isinstance(v, float) and (np.isnan(v) or np.isinf(v))):
            bad += 1
            by_fam[fam][1] += 1
        else:
            ok += 1
            by_fam[fam][0] += 1
    eng.close()
    print(f"=== new_feat_lib self-test — sym {sym} @ {T} ===")
    print(f"features: {len(REGISTRY)} | finite {ok} | none/nan {bad}")
    for f in FAMILIES:
        print(f"  {f:12} finite {by_fam[f][0]:3} / none {by_fam[f][1]}")
