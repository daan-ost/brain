"""
split_2b_companion — the MISSING half of 2b (read-only DRY RUN, builds nothing).

split_2b measures only the SPLIT side: how much slecht falls away when a rule's band is tightened to
the good cluster, sacrificing ONE outlier good. But that good must be recovered by a COMPANION rule.
The owner's question: if you add a new rule for that one good trade, HOW MANY SLECHT come back (and
which extra GOOD does it also catch)? Net 2b benefit = split_drop - companion_slecht.

A companion rule must: (a) fire on the sacrificed outlier, (b) NOT re-admit the gap-slecht the split
just removed (else net zero), (c) admit as few other slecht as possible. For every metric we build the
band that contains the outlier and excludes the gap-slecht (bad-edge style), and count slecht/good it
admits over the broadest labeled universe we have: ALL fire MOMENTS (executed + shadow), deduped per
(coin, datetime) and labeled by best_upside. We report the best single-metric companion and a greedy
2-metric AND companion (which brackets how tight a real companion can get).

CAVEAT (honest): this universe is fire-moments only. A companion can also fire on currently NON-firing
moments (and must pass the volume gate), so the true slecht-cost is >= what we measure. The final word
is an engine re-fire with a real rule_number 24. This is a dry run to size the companion cost.

Run:  ../.venv/bin/python split_2b_companion.py
"""
import json
import os

import duckdb
import numpy as np
import pandas as pd

import opt_lib as o
from db import brain

HERE = o.HERE
JSON = os.path.join(HERE, "..", "out", "opt", "split_2b.json")
RULES = (21, 22, 23)           # rule 20 has no clean split per the report — skip
MARGIN = 1e-6


def load_moment_universe():
    """Every fire MOMENT (executed + shadow), deduped per (coin, datetime), labeled by best_upside,
    joined to the indicator_metrics cache. This is the broadest labeled set a companion could fire on."""
    fires = o.load_all_fires()
    moments = (fires.sort_values("is_executed", ascending=False)
               .drop_duplicates(["sym", "datetime"])[["sym", "datetime", "cls", "best_upside"]])
    con = duckdb.connect()
    con.register("m", moments)
    cols = ",".join("x." + c for c in o.CALC_COLS)
    wide = con.execute(f"""
        SELECT t.sym, t.datetime, t.cls, x.indicator, x.lookback, {cols}
        FROM read_parquet('{o.METRICS_GLOB}') x
        JOIN m t ON x.trading_symbol_id=t.sym AND x.datetime=t.datetime
    """).df()
    con.close()
    long = wide.melt(id_vars=["sym", "datetime", "cls", "indicator", "lookback"],
                     value_vars=o.CALC_COLS, var_name="calc", value_name="value").dropna(subset=["value"])
    long["mid"] = long["sym"].astype(str) + "|" + long["datetime"].astype(str)
    return long


def top_candidate(cands, rule):
    """The top genuine k=1 cross-coin split candidate for a rule, from split_2b.json."""
    pool = [c for c in cands if c["rule"] == rule and c["genuine"] and c["k_outliers"] == 1
            and c.get("min_coin_drop", 0) >= 1]
    if not pool:
        return None
    pool.sort(key=lambda c: (c["bad_dropped_by_split"], c.get("balance_ratio", 0)), reverse=True)
    return pool[0]


def gap_bad_moments(execlong, cand):
    """The executed SLECHT the split removes (between cluster_edge and the full good edge) — these must
    NOT be re-admitted by the companion. Returns their mids + the split metric values."""
    ind, lb, calc, tail = cand["indicator"], cand["lookback"], cand["calc"], cand["tail"]
    edge, full = cand["cluster_edge"], cand["full_good_edge"]
    g = execlong[(execlong["rule"] == cand["rule"]) & (execlong["indicator"] == ind) &
                 (execlong["lookback"] == lb) & (execlong["calc"] == calc) & (execlong["cls"] == "slecht")]
    if tail == "upper":
        sel = g[(g["value"] > edge) & (g["value"] <= full)]
    else:
        sel = g[(g["value"] < edge) & (g["value"] >= full)]
    return set((sel["sym"].astype(str) + "|" + sel["datetime"].astype(str)).tolist())


def companion_band(values, v_o, bad_vals):
    """A one-sided band that CONTAINS v_o (the outlier) and EXCLUDES every bad in bad_vals. Returns
    (lo, hi) or None if v_o sits inside the bad range (then this metric can't separate them)."""
    bad_vals = np.asarray(bad_vals, dtype=float)
    if len(bad_vals) == 0:
        return (-np.inf, np.inf)                     # no gap-bad on this metric -> trivially excludes
    bmin, bmax = bad_vals.min(), bad_vals.max()
    if v_o > bmax + MARGIN:
        return ((bmax + v_o) / 2.0, np.inf)          # band above the bad (bad-edge midpoint)
    if v_o < bmin - MARGIN:
        return (-np.inf, (bmin + v_o) / 2.0)
    return None                                       # v_o between bad -> not separable here


def search_companion(uni, outlier_mid, gap_mids):
    """Per metric, build the companion band (contains outlier, excludes gap-bad) and count the MOMENTS
    it admits over the universe. Returns a DataFrame ranked by slecht admitted (ascending)."""
    rows = []
    for (ind, lb, calc), g in uni.groupby(["indicator", "lookback", "calc"]):
        s = g.set_index("mid")
        if outlier_mid not in s.index:
            continue
        v_o = float(s.loc[outlier_mid, "value"]) if np.ndim(s.loc[outlier_mid, "value"]) == 0 else float(s.loc[outlier_mid, "value"].iloc[0])
        gap_here = s.reindex([m for m in gap_mids if m in s.index])["value"].to_numpy(float)
        band = companion_band(s["value"].to_numpy(float), v_o, gap_here)
        if band is None:
            continue
        lo, hi = band
        inb = g[(g["value"] >= lo) & (g["value"] <= hi)]
        n_bad = int((inb["cls"] == "slecht").sum())
        n_good = int((inb["cls"] == "goed").sum())
        # the companion must actually catch the outlier (it does by construction) and exclude the gap-bad
        readmitted = sum(1 for m in gap_mids if m in set(inb["sym"].astype(str) + "|" + inb["datetime"].astype(str)))
        rows.append({"indicator": ind, "lookback": int(lb), "calc": calc,
                     "lo": round(lo, 4) if np.isfinite(lo) else None,
                     "hi": round(hi, 4) if np.isfinite(hi) else None,
                     "companion_slecht": n_bad, "companion_goed": n_good,
                     "gap_readmitted": readmitted, "scale_unsafe": bool(o.scale_unsafe(ind, calc))})
    df = pd.DataFrame(rows)
    if df.empty:
        return df
    df = df[(df["gap_readmitted"] == 0) & (~df["scale_unsafe"])]
    return df.sort_values(["companion_slecht", "companion_goed"], ascending=[True, False]).reset_index(drop=True)


def _price_series():
    """datetime+price per coin, for labeling ARBITRARY datetimes with best_upside (trade quality)."""
    import bisect, datetime as _dt
    from config import FORWARD_MINUTES
    conn = brain()
    px = {}
    for sym in (o.DOGEAI, o.NOS):
        with conn.cursor() as c:
            c.execute("SELECT datetime, price FROM indicators WHERE trading_symbol_id=%s AND "
                      "indicator='volumeud' AND price IS NOT NULL ORDER BY datetime", (sym,))
            rows = c.fetchall()
        px[sym] = ([r["datetime"] for r in rows], [float(r["price"]) for r in rows])
    conn.close()

    def best_upside(sym, d):
        DT, P = px[sym]
        i = bisect.bisect_right(DT, d)
        if i == 0:
            return None
        buy = P[i - 1]
        lo = bisect.bisect_left(DT, d)
        hi = bisect.bisect_right(DT, d + _dt.timedelta(minutes=FORWARD_MINUTES))
        return (max(P[lo:hi]) - buy) / buy * 100 if lo < hi else None
    return best_upside


def label_band(con, best_upside, ind, lb, calc, lo, hi):
    """Label EVERY in-scope cache datetime the companion band matches with best_upside (good/slecht/
    middel) — the honest companion cost, not just the already-firing moments."""
    conds = []
    if pd.notna(lo):
        conds.append(f"{calc} >= {lo}")
    if pd.notna(hi):
        conds.append(f"{calc} <= {hi}")
    q = (f"SELECT trading_symbol_id s, datetime d FROM read_parquet('{o.METRICS_GLOB}') "
         f"WHERE indicator='{ind}' AND lookback={lb}" + (" AND " + " AND ".join(conds) if conds else ""))
    g = s = m = 0
    for sym, d in con.execute(q).fetchall():
        bu = best_upside(int(sym), d)
        if bu is None:
            continue
        g += bu >= o.GOOD_UPSIDE
        s += bu < o.BAD_UPSIDE
        m += o.BAD_UPSIDE <= bu < o.GOOD_UPSIDE
    return g, s, m


def main():
    cands = json.load(open(JSON))
    execlong = o.load_long()
    uni = load_moment_universe()
    con = duckdb.connect()
    best_upside = _price_series()

    # base rate: the cache scope (promising periods + trades) is itself good-enriched, so a companion
    # measured over it is biased optimistic. Print it up front so every "0 slecht" is read in context.
    alldt = con.execute(f"SELECT DISTINCT trading_symbol_id s, datetime d FROM read_parquet('{o.METRICS_GLOB}')").fetchall()
    bg = bs = bm = 0
    for sym, d in alldt:
        bu = best_upside(int(sym), d)
        if bu is None:
            continue
        bg += bu >= o.GOOD_UPSIDE; bs += bu < o.BAD_UPSIDE; bm += o.BAD_UPSIDE <= bu < o.GOOD_UPSIDE
    tot = bg + bs + bm
    print(f"⚠️  CACHE-SCOPE BIAS: de {tot} in-scope datetimes zijn {100*bg/tot:.0f}% GOED / {100*bs/tot:.0f}% slecht.")
    print(f"    De companion-kost hieronder is over diezelfde scope gemeten en dus OPTIMISTISCH biased —")
    print(f"    de companion vuurt in werkelijkheid over de volle historie (~640k/coin), grotendeels")
    print(f"    NIET-goede momenten die deze scope niet bevat. Alleen een engine-refire geeft het echte getal.\n")

    for rule in RULES:
        c = top_candidate(cands, rule)
        if not c:
            print(f"rule {rule}: geen genuine split-kandidaat\n"); continue
        outlier = c["sacrificed_good"][0]
        omid = f"{outlier['sym']}|{outlier['datetime']}"
        gap = gap_bad_moments(execlong, c)
        split_drop = c["bad_dropped_by_split"]
        print(f"===== RULE {rule} — split {c['indicator']}/{c['calc']}/lb{c['lookback']} [{c['tail']}] "
              f"verwijdert {split_drop} slecht; offert {outlier['coin']} {outlier['datetime']} (bu {outlier['best_upside']}) =====")
        comp = search_companion(uni, omid, gap)
        if comp.empty:
            print("  GEEN companion-metric isoleert de outlier van de gap-slecht → goede niet te redden.\n")
            continue
        best = comp.iloc[0]
        g, s, m = label_band(con, best_upside, best["indicator"], int(best["lookback"]), best["calc"],
                             best["lo"], best["hi"])
        print(f"  beste companion-band: {best['indicator']}/{best['calc']}/lb{best['lookback']} "
              f"[{best['lo']}..{best['hi']}]")
        print(f"  matcht {g+s+m} in-scope datetimes → GOED {g} · SLECHT {s} · middel {m}")
        print(f"  → split −{split_drop} slecht, companion +{s} slecht (in-scope, biased laag) "
              f"⇒ NETTO ≈ {split_drop - s} (ondergrens; engine-refire kan dit omkeren)\n")


if __name__ == "__main__":
    main()
