#!/usr/bin/env python3
"""
recall_seed — SEED-and-TIGHTEN ontdekking van nieuwe BR-achtige bands voor NOS recall.

READ-ONLY: muteert NIETS (niet brain.rules, niet brain.coin_fires, niet brain.indicators, niet
volume.py). Bouwt voort op rule_engine._vals + calc.window_metrics. Mirror van het patroon uit
recall_shadow._precompute en recall_volagg: één pass over alle vf=1 candidate ticks, alle (indicator,
lookback, metric) waarden in een 4D-array, daarna VECTORIZED box-checks.

METHODE (op NOS=244, alleen):
1) precompute  — voor elk van de 8307 vf=1 ticks: M[tick, ind, lookback, metric] over
                 {vzo, mfi, phobos, obv-x-value, volumeud} × lookback 1..10 × 31 window-metrics.
                 SCALE GUARD: voor indicator='volumeud' zijn de 15 schaal-onveilige metrics op NaN
                 gezet (zie SCALE_UNSAFE_VOLUMEUD).
2) ok-moments  — laad 303 NOS ok-moments (decision='yes'); snap elk naar nearest vf=1 tick binnen
                 ±180s; drop on miss. Groep elk gesnapt ok-moment naar zijn promising_recall_state
                 groep (group_lead<=dt<=group_to). Niet-gegroepeerd → singleton-groep op dt.
3) holdout    — median(dt) over surviving (snapped) ok-moments = midpoint; train = dt<=mid, test = >mid.
4) seed       — voor ELKE (indicator, lookback 1..10, metric, direction in {high,low}):
                 top-10 train ok-moments by value, eis >=6 distinct promising groups,
                 seed_band = (min, max) van die 10. Slaan over bij NaN of < 10 trefbare ok-momenten.
5) box-tighten — voor diezelfde 10 seed-ticks, neem (min, max) over ELKE (indicator, lookback, metric);
                 drop conditions met NaN of vacuous (min==max). Box bevat altijd de seed.
6) eval       — vectorized box-match over alle 8307 ticks → captured set. Single-position dedup
                 (60 min lock-out na elke capture). best_upside per captured tick op price-series.
                 train_good/bad (<=mid) en test_good/bad (>mid) gerapporteerd.
7) vol-gate   — voor survivors (test_good>=3 EN test_ratio>=2.0): heval met volume.check_volumeud_3
                 (rule 20 settings) per captured tick. Apart gerapporteerd in 'with_volume_gate'.

Output JSON: /Users/daanvantongeren/Documents/Sites/brain/engine/out/opt/recall_seed_nos.json
"""
import bisect
import datetime as _dt
import json
import os
import sys
import time

import numpy as np

from calc import window_metrics, WINDOW_METRIC_KEYS
from config import FORWARD_MINUTES
from db import brain
from rule_engine import RuleEngine
from volume import check_volumeud_3, volume_settings

NOS = 244
INDICATORS = ("vzo", "mfi", "phobos", "obv-x-value", "volumeud")
LOOKBACKS = tuple(range(1, 11))   # 1..10
MAX_LOOKBACK = max(LOOKBACKS)
METRIC_KEYS = list(WINDOW_METRIC_KEYS)  # 31
N_METRICS = len(METRIC_KEYS)

# SCALE TRAP: when indicator='volumeud' the engine reads RAW values (rule_engine._vals) but the
# indicator_metrics cache stores RELATIVE volumeud (opt_lib). The 15 level-metrics below would be
# inert as engine-portable subrules. We compute on RAW (engine-correct) but mask them to NaN in M
# so they can never enter a seed/box for indicator='volumeud'.
SCALE_UNSAFE_VOLUMEUD = frozenset((
    "current_value", "first_value", "last_value", "diff_previous_number",
    "max_diff_number", "diff_number_prev_max", "diff_number_prev_min",
    "lowest_value", "highest_value", "sum_value",
    "diff_lowest_value_period", "diff_highest_value_period",
    "standard_deviation", "average_reversal_size", "median_value",
))

GOOD_EDGE, BAD_EDGE = 3.0, 0.5
SNAP_TOL_SEC = 180

# survivor thresholds for the vol-gate stage
SURVIVOR_MIN_TEST_GOOD = 3
SURVIVOR_MIN_TEST_RATIO = 2.0


# ---------------------------------------------------------------------------
class SeedTightenEval(RuleEngine):
    """Loads NOS once; precomputes a (cand × indicator × lookback × metric) 4D array for vectorized
    box evaluation. Inherits db loading + _vals from RuleEngine."""

    def __init__(self):
        super().__init__(NOS)
        s = self.series["volumeud"]
        self.cand = [dt for i, dt in enumerate(s["dt"]) if s["vf"][i] == 1]
        self.cand_dt = np.array(self.cand)                    # for slicing by midpoint
        self.cand_index = {dt: i for i, dt in enumerate(self.cand)}
        # full price series (= volumeud price) for best_upside
        self.PX_DT = s["dt"]
        self.PX = s["p"]
        self.M = None                                         # built below
        self._volrows = {}                                    # dt -> _vol_rows(dt,60), lazy
        self._precompute_grid()

    # ------------------------------------------------------------------ grid
    def _precompute_grid(self):
        n_ticks = len(self.cand)
        n_ind = len(INDICATORS)
        n_lb = len(LOOKBACKS)
        M = np.full((n_ticks, n_ind, n_lb, N_METRICS), np.nan, dtype=np.float64)
        # for the scale-guard mask
        unsafe_idx = np.array([i for i, k in enumerate(METRIC_KEYS) if k in SCALE_UNSAFE_VOLUMEUD],
                              dtype=int)
        vud_ind_idx = INDICATORS.index("volumeud")

        t0 = time.time()
        for ind_idx, ind in enumerate(INDICATORS):
            t1 = time.time()
            for tick_idx, dt in enumerate(self.cand):
                vals, _prices = self._vals(ind, MAX_LOOKBACK, dt)
                if not vals:
                    continue
                # window_metrics on every prefix slice vals[:lb]
                for lb_idx, lb in enumerate(LOOKBACKS):
                    slice_vals = vals[:lb] if lb <= len(vals) else vals
                    if not slice_vals:
                        continue
                    m = window_metrics(slice_vals)
                    if not m:
                        continue
                    for met_idx, key in enumerate(METRIC_KEYS):
                        v = m.get(key)
                        if v is None:
                            continue
                        try:
                            M[tick_idx, ind_idx, lb_idx, met_idx] = float(v)
                        except (TypeError, ValueError):
                            pass
            print(f"  precompute {ind}: {time.time()-t1:.1f}s", flush=True)
        # scale-guard mask
        if len(unsafe_idx):
            M[:, vud_ind_idx, :, unsafe_idx] = np.nan
        self.M = M
        print(f"  precompute total: {time.time()-t0:.1f}s, shape={M.shape}, "
              f"nan-frac={np.isnan(M).mean():.3f}", flush=True)

    # -------------------------------------------------------------- price API
    def price_at(self, dt):
        i = bisect.bisect_right(self.PX_DT, dt)
        if i == 0:
            return None
        return self.PX[i - 1]

    def best_upside(self, dt, buy):
        if buy is None or buy <= 0:
            return None
        lo = bisect.bisect_left(self.PX_DT, dt)
        hi = bisect.bisect_right(self.PX_DT, dt + _dt.timedelta(minutes=FORWARD_MINUTES))
        if lo >= hi:
            return None
        prices = [p for p in self.PX[lo:hi] if p is not None]
        if not prices:
            return None
        return (max(prices) - buy) / buy * 100.0

    # ----------------------------------------------------------- volume rows
    def vol_rows_60(self, dt):
        r = self._volrows.get(dt)
        if r is None:
            r = self._vol_rows(dt, 60)
            self._volrows[dt] = r
        return r


# ---------------------------------------------------------------------------
def snap_to_candidate(dt_ok, cand_dts, tol_sec=SNAP_TOL_SEC):
    """Snap an ok-moment dt to nearest vf=1 candidate within ±tol_sec. Returns the snapped dt or
    None if no candidate within tolerance."""
    i = bisect.bisect_left(cand_dts, dt_ok)
    nearest = []
    if i < len(cand_dts):
        nearest.append(cand_dts[i])
    if i > 0:
        nearest.append(cand_dts[i - 1])
    if not nearest:
        return None
    best = min(nearest, key=lambda d: abs((d - dt_ok).total_seconds()))
    if abs((best - dt_ok).total_seconds()) > tol_sec:
        return None
    return best


def load_ok_moments_with_groups(conn):
    """Returns list of {dt_orig, dt_snapped, group_key} ordered by dt_snapped. Drops moments
    where snapping fails."""
    with conn.cursor() as c:
        c.execute("SELECT datetime FROM coin_moment_labels "
                  "WHERE trading_symbol_id=%s AND decision='yes' ORDER BY datetime", (NOS,))
        ok_rows = c.fetchall()
        c.execute("SELECT id, group_lead, group_to FROM promising_recall_state "
                  "WHERE trading_symbol_id=%s ORDER BY group_lead", (NOS,))
        groups = c.fetchall()
    return ok_rows, groups


def assign_group(dt, groups):
    """Find the promising group whose [group_lead, group_to] window contains dt. If multiple match,
    pick the one with smallest |group_lead - dt|. Returns group id, or None."""
    hits = []
    for g in groups:
        gl, gt = g["group_lead"], g["group_to"]
        if gt is None:
            continue
        if gl <= dt <= gt:
            hits.append(g)
    if not hits:
        return None
    return min(hits, key=lambda g: abs((g["group_lead"] - dt).total_seconds()))["id"]


# ---------------------------------------------------------------------------
def evaluate_box(M, conditions, cand_dts):
    """Vectorized box mask over all candidate ticks.
    conditions = list of (ind_idx, lb_idx, met_idx, lo, hi). Returns boolean array of len(cand)."""
    if not conditions:
        return np.zeros(M.shape[0], dtype=bool)
    mask = np.ones(M.shape[0], dtype=bool)
    for ind_idx, lb_idx, met_idx, lo, hi in conditions:
        col = M[:, ind_idx, lb_idx, met_idx]
        ok = ~np.isnan(col) & (col >= lo) & (col <= hi)
        mask &= ok
        if not mask.any():
            return mask
    return mask


def dedup_in_time(fire_idxs, cand_dts, forward_minutes=FORWARD_MINUTES):
    """Walk fires in time order; after a fire keep, skip everything within forward_minutes."""
    if len(fire_idxs) == 0:
        return []
    kept = []
    open_until = None
    for idx in fire_idxs:
        dt = cand_dts[idx]
        if open_until is not None and dt <= open_until:
            continue
        kept.append(idx)
        open_until = dt + _dt.timedelta(minutes=forward_minutes)
    return kept


def classify_captures(ev, cand, kept_idxs, midpoint):
    """For each kept fire-idx: compute best_upside, classify good/bad/middel, split train/test by
    midpoint. Returns (train_g, train_b, train_n, test_g, test_b, test_n, kept_dts)."""
    train_g = train_b = train_n = 0
    test_g = test_b = test_n = 0
    kept_dts = []
    for idx in kept_idxs:
        dt = cand[idx]
        buy = ev.price_at(dt)
        bu = ev.best_upside(dt, buy)
        if bu is None:
            continue
        is_train = dt <= midpoint
        if is_train:
            train_n += 1
            if bu >= GOOD_EDGE:
                train_g += 1
            elif bu < BAD_EDGE:
                train_b += 1
        else:
            test_n += 1
            if bu >= GOOD_EDGE:
                test_g += 1
            elif bu < BAD_EDGE:
                test_b += 1
        kept_dts.append(dt)
    return train_g, train_b, train_n, test_g, test_b, test_n, kept_dts


def build_box(M, seed_tick_idxs):
    """Given the 10 seed tick indices, return the (min,max) per (ind,lb,metric) where ALL 10 are
    not-NaN AND min<max. Returns list of (ind_idx, lb_idx, met_idx, lo, hi)."""
    sub = M[seed_tick_idxs]   # (10, n_ind, n_lb, n_metric)
    nan_mask = np.isnan(sub).any(axis=0)
    mins = np.nanmin(sub, axis=0)
    maxs = np.nanmax(sub, axis=0)
    valid = (~nan_mask) & (maxs > mins)
    conds = []
    for ind_idx in range(M.shape[1]):
        for lb_idx in range(M.shape[2]):
            for met_idx in range(M.shape[3]):
                if valid[ind_idx, lb_idx, met_idx]:
                    conds.append((int(ind_idx), int(lb_idx), int(met_idx),
                                  float(mins[ind_idx, lb_idx, met_idx]),
                                  float(maxs[ind_idx, lb_idx, met_idx])))
    return conds


# ---------------------------------------------------------------------------
def run_sweep():
    out_path = "/Users/daanvantongeren/Documents/Sites/brain/engine/out/opt/recall_seed_nos.json"
    os.makedirs(os.path.dirname(out_path), exist_ok=True)

    print("=== recall_seed: SeedTightenEval — NOS=244 ===", flush=True)
    print("loading + precompute…", flush=True)
    t_start = time.time()
    ev = SeedTightenEval()
    conn = brain()

    # ---------- ok-moments + grouping --------------------------------------
    ok_rows, groups = load_ok_moments_with_groups(conn)
    conn.close()
    cand_dts = ev.cand                          # ordered ascending
    snapped = []
    for r in ok_rows:
        dt_ok = r["datetime"]
        snap = snap_to_candidate(dt_ok, cand_dts)
        if snap is None:
            continue
        gid = assign_group(snap, groups)
        if gid is None:
            gid = f"singleton:{snap.isoformat()}"
        snapped.append({"dt_orig": dt_ok, "dt": snap, "g": gid})
    snapped.sort(key=lambda x: x["dt"])

    n_ok_total = len(ok_rows)
    n_ok_snapped = len(snapped)
    if n_ok_snapped == 0:
        print("FATAL: no ok-moments snapped", flush=True)
        return None

    # ---------- holdout split ----------------------------------------------
    snapped_dts = sorted([r["dt"] for r in snapped])
    mid_idx = len(snapped_dts) // 2
    midpoint = snapped_dts[mid_idx]
    train_oks = [r for r in snapped if r["dt"] <= midpoint]
    test_oks = [r for r in snapped if r["dt"] > midpoint]
    print(f"  ok-moments: {n_ok_total} loaded -> {n_ok_snapped} snapped "
          f"(±{SNAP_TOL_SEC}s); midpoint={midpoint.isoformat()} "
          f"train={len(train_oks)} test={len(test_oks)}", flush=True)

    # tick index for each train ok-moment
    for r in snapped:
        r["tick_idx"] = ev.cand_index.get(r["dt"])

    # ---------- seed sweep -------------------------------------------------
    print("seed sweep…", flush=True)
    M = ev.M
    train_tick_idxs = np.array([r["tick_idx"] for r in train_oks if r["tick_idx"] is not None],
                               dtype=int)
    # train ok-moment group keys per tick_idx
    train_groups = {r["tick_idx"]: r["g"] for r in train_oks if r["tick_idx"] is not None}

    sweep_seeds = []
    n_seeds_eval = 0
    n_viable = 0
    for ind_idx, ind in enumerate(INDICATORS):
        for lb_idx, lb in enumerate(LOOKBACKS):
            for met_idx, met in enumerate(METRIC_KEYS):
                # scale-unsafe? values are NaN, sweep will short-circuit
                col = M[train_tick_idxs, ind_idx, lb_idx, met_idx]
                mask = ~np.isnan(col)
                if mask.sum() < 10:
                    continue
                vals = col[mask]
                trainset = train_tick_idxs[mask]
                for direction in ("high", "low"):
                    n_seeds_eval += 1
                    if direction == "high":
                        order = np.argsort(-vals)
                    else:
                        order = np.argsort(vals)
                    top10_pos = order[:10]
                    top10_ticks = trainset[top10_pos]
                    seeds_vals = vals[top10_pos]
                    distinct_groups = {train_groups[int(t)] for t in top10_ticks}
                    if len(distinct_groups) < 6:
                        continue
                    n_viable += 1
                    lo_seed = float(np.min(seeds_vals))
                    hi_seed = float(np.max(seeds_vals))
                    sweep_seeds.append({
                        "ind_idx": ind_idx, "ind": ind,
                        "lb_idx": lb_idx, "lb": lb,
                        "met_idx": met_idx, "met": met,
                        "direction": direction,
                        "seed_band": (lo_seed, hi_seed),
                        "seed_ticks": top10_ticks.tolist(),
                        "n_distinct_groups": len(distinct_groups),
                    })
    print(f"  sweep evaluated {n_seeds_eval} (ind,lb,metric,dir) cells; "
          f"viable seeds (>=6 distinct groups): {n_viable}", flush=True)

    # ---------- box build + eval per seed ----------------------------------
    print("box build + eval…", flush=True)
    cand_np = np.array(ev.cand)
    candidates = []
    for s_i, seed in enumerate(sweep_seeds):
        seed_ticks = seed["seed_ticks"]
        conds = build_box(M, np.array(seed_ticks, dtype=int))
        if not conds:
            continue
        # ensure the SEED condition is in the box (build_box drops min==max → if seed values are
        # all identical it would be dropped; force-include by widening with the seed_band)
        # Actually: seed values come from a single (ind,lb,metric) so the (min,max) for that one
        # IS in conds (unless all 10 had the same value — then it was vacuously dropped). Add it
        # explicitly with seed_band to be safe:
        seed_cond_key = (seed["ind_idx"], seed["lb_idx"], seed["met_idx"])
        has_seed_cond = any((c[0], c[1], c[2]) == seed_cond_key for c in conds)
        if not has_seed_cond:
            lo, hi = seed["seed_band"]
            if hi > lo:
                conds.append((seed["ind_idx"], seed["lb_idx"], seed["met_idx"], lo, hi))

        if not conds:
            continue

        # evaluate over all cand ticks
        mask = evaluate_box(M, conds, ev.cand)
        fire_idxs = np.flatnonzero(mask)
        if len(fire_idxs) == 0:
            continue

        # dedup in time
        kept_idxs = dedup_in_time(fire_idxs.tolist(), ev.cand)
        if not kept_idxs:
            continue

        # classify train/test
        train_g, train_b, train_n, test_g, test_b, test_n, kept_dts = classify_captures(
            ev, ev.cand, kept_idxs, midpoint
        )

        # how many of the 10 seed moments were captured (post-dedup)?
        seed_dt_set = {ev.cand[i] for i in seed_ticks}
        seed_hit = sum(1 for d in kept_dts if d in seed_dt_set)
        # actually a seed dt could also be SHADOWED by an earlier capture in dedup; recompute on
        # the un-deduped fire mask to know "would have been captured ignoring dedup"
        seed_hit_raw = sum(1 for i in fire_idxs.tolist() if ev.cand[i] in seed_dt_set)

        test_ratio = test_g / max(test_b, 1)

        entry = {
            "seed": {
                "indicator": seed["ind"],
                "lookback": seed["lb"],
                "metric": seed["met"],
                "direction": seed["direction"],
                "band": [seed["seed_band"][0], seed["seed_band"][1]],
            },
            "n_seed_groups": seed["n_distinct_groups"],
            "n_box_conditions": len(conds),
            "train": {"good": train_g, "bad": train_b, "captures": train_n, "seed_hit": seed_hit,
                      "seed_hit_raw": seed_hit_raw},
            "test": {"good": test_g, "bad": test_b, "captures": test_n},
            "test_ratio": float(test_ratio),
            "with_volume_gate": None,
            "_kept_idxs": kept_idxs,    # internal; stripped before JSON dump
        }
        candidates.append(entry)

    print(f"  evaluated boxes: {len(candidates)}", flush=True)

    # ---------- vol-gate stage --------------------------------------------
    print("volume-gate stage on survivors…", flush=True)
    n_survivors = 0
    vset20 = volume_settings(20)
    minvol20 = ev.minvol.get(20, 1e12)
    for entry in candidates:
        test_g = entry["test"]["good"]
        if test_g < SURVIVOR_MIN_TEST_GOOD or entry["test_ratio"] < SURVIVOR_MIN_TEST_RATIO:
            continue
        n_survivors += 1
        kept_idxs = entry["_kept_idxs"]
        # re-evaluate each captured tick's volume_check (rule 20 settings)
        train_g = train_b = train_n = 0
        test_g_v = test_b_v = test_n_v = 0
        for idx in kept_idxs:
            dt = ev.cand[idx]
            buy = ev.price_at(dt)
            bu = ev.best_upside(dt, buy)
            if bu is None:
                continue
            ok = check_volumeud_3(ev.vol_rows_60(dt), minvol20, vset20)
            if not ok:
                continue
            is_train = dt <= midpoint
            if is_train:
                train_n += 1
                if bu >= GOOD_EDGE:
                    train_g += 1
                elif bu < BAD_EDGE:
                    train_b += 1
            else:
                test_n_v += 1
                if bu >= GOOD_EDGE:
                    test_g_v += 1
                elif bu < BAD_EDGE:
                    test_b_v += 1
        gated_ratio = test_g_v / max(test_b_v, 1)
        entry["with_volume_gate"] = {
            "train": {"good": train_g, "bad": train_b, "captures": train_n},
            "test": {"good": test_g_v, "bad": test_b_v, "captures": test_n_v},
            "test_ratio": float(gated_ratio),
        }
    print(f"  survivors processed with vol-gate: {n_survivors}", flush=True)

    # ---------- sort + finalize -------------------------------------------
    # Sort by test_ratio desc, then test_good desc
    candidates.sort(key=lambda c: (-c["test_ratio"], -c["test"]["good"]))
    # strip internal fields
    for c in candidates:
        c.pop("_kept_idxs", None)

    sweep_meta = {
        "coin": NOS,
        "total_ok_moments": n_ok_total,
        "snapped_ok_moments": n_ok_snapped,
        "midpoint": midpoint.isoformat(),
        "train_oks": len(train_oks),
        "test_oks": len(test_oks),
        "n_candidate_ticks": len(ev.cand),
        "n_seeds_evaluated": n_seeds_eval,
        "n_viable_seeds": n_viable,
        "n_survivors": n_survivors,
        "elapsed_sec": time.time() - t_start,
    }
    payload = {"sweep_meta": sweep_meta, "candidates": candidates}
    with open(out_path, "w") as f:
        json.dump(payload, f, indent=1, default=str)
    print(f"-> {out_path}  ({sweep_meta['elapsed_sec']:.1f}s)", flush=True)

    # ---------- TOP 20 print -----------------------------------------------
    print()
    print("=== TOP 20 by test_ratio (asc seed) ===", flush=True)
    print(f"{'#':>3} {'indicator':>11} lb {'metric':>30} dir {'n_cond':>6} "
          f"{'tr g/b':>8} {'te g/b':>8} {'ratio':>6} {'grp':>3} {'vol g/b':>8} {'volR':>5}",
          flush=True)
    for k, c in enumerate(candidates[:20], 1):
        s = c["seed"]
        tr = f"{c['train']['good']}/{c['train']['bad']}"
        te = f"{c['test']['good']}/{c['test']['bad']}"
        vg = c.get("with_volume_gate")
        if vg:
            vol_gb = f"{vg['test']['good']}/{vg['test']['bad']}"
            vol_r = f"{vg['test_ratio']:.2f}"
        else:
            vol_gb = "-"
            vol_r = "-"
        print(f"{k:>3} {s['indicator']:>11} {s['lookback']:>2} {s['metric']:>30} "
              f"{s['direction']:>3} {c['n_box_conditions']:>6} {tr:>8} {te:>8} "
              f"{c['test_ratio']:>6.2f} {c['n_seed_groups']:>3} {vol_gb:>8} {vol_r:>5}",
              flush=True)

    ev.close()
    return out_path


def probe():
    """Quick coverage probe before the full sweep."""
    print("=== probe: SeedTightenEval precompute coverage ===", flush=True)
    ev = SeedTightenEval()
    M = ev.M
    print(f"  M shape: {M.shape}  (cand × ind × lookback × metric)", flush=True)
    print(f"  total cells: {M.size}", flush=True)
    print(f"  nan fraction overall: {np.isnan(M).mean():.4f}", flush=True)
    # per-indicator nan fraction
    for ind_idx, ind in enumerate(INDICATORS):
        sub = M[:, ind_idx, :, :]
        print(f"  {ind:>12}: nan-frac={np.isnan(sub).mean():.4f}", flush=True)
    ev.close()


if __name__ == "__main__":
    cmd = sys.argv[1] if len(sys.argv) > 1 else "sweep"
    if cmd == "probe":
        probe()
    else:
        run_sweep()
