#!/usr/bin/env python3
"""READ-ONLY: gemene deler 1-maart DOGEAI yes-groepen, multivariate + concreet contrast."""
import datetime as dt
import numpy as np
from parent_discover import Features, first_n_yes_groups, manual_labels

sym = 2525
d0 = dt.datetime(2025, 3, 1); d1 = dt.datetime(2025, 3, 2)
F = Features(sym)
groups, lab = first_n_yes_groups(sym, d0, d1, n=3)
good_ticks = [t for g in groups for t in g]
bad_ticks = sorted(t for t, d in lab.items() if d in ("no", "no_volume"))
day_ticks = [t for (t, p, v, vf) in F.vud_ticks(d0, d1)]
good_vecs = {t: F.at(t) for t in good_ticks}
bad_vecs = {t: F.at(t) for t in bad_ticks}
day_vecs = {t: F.at(t) for t in day_ticks}

keys = set(good_vecs[good_ticks[0]])
for t in good_ticks[1:]:
    keys &= set(good_vecs[t])

def band_stats(k):
    gv = [good_vecs[t][k] for t in good_ticks]
    glo, ghi, gmn = min(gv), max(gv), float(np.mean(gv))
    signs = {(1 if x > 1e-9 else (-1 if x < -1e-9 else 0)) for x in gv}
    bg = [day_vecs[t][k] for t in day_ticks if k in day_vecs[t]]
    bg_rate = np.mean([(glo <= x <= ghi) for x in bg]) if bg else 1.0
    bd = [bad_vecs[t][k] for t in bad_ticks if k in bad_vecs[t]]
    bad_rate = np.mean([(glo <= x <= ghi) for x in bd]) if bd else 1.0
    return glo, ghi, gmn, len(signs) == 1, bg_rate, bad_rate

# beste feature per (indicator, basis-metric) — dedupe lookbacks
best = {}
for k in keys:
    glo, ghi, gmn, sa, bg, bad = band_stats(k)
    if not sa or bg > 0.25 or bad > 0.34:
        continue
    ind, lb, metric = k.split("|")
    key2 = (ind, metric)
    if key2 not in best or bg < best[key2][1]:
        best[key2] = (k, bg, bad, glo, ghi, gmn)

ranked = sorted(best.values(), key=lambda x: x[1])
print(f"=== multivariate gemene deler (beste lookback per indicator|metric), {len(ranked)} families ===")
print(f"{'feature':46s} {'goede band':>24s} {'bg%':>5s} {'slecht%':>7s}")
for k, bg, bad, glo, ghi, gmn in ranked[:30]:
    print(f"{k:46s} [{glo:9.3f},{ghi:9.3f}] {100*bg:4.1f} {100*bad:6.1f}")

# concreet: obv + price-richting + volume per goede vs slechte tick
print("\n=== concreet per tick: obv(now) | price%-laatste-3 | volUD-teken ===")
def line(t, tag):
    f = F.at(t)
    obv = f.get("obv-x-value|L1|current_value")
    pr3 = f.get("price|L3|diff_previous_value")   # % oudste->nieuwste over 3 ticks
    vud = f.get("volumeud|L1|current_value")
    mfi = f.get("mfi|L1|current_value")
    vzo = f.get("vzo|L1|current_value")
    print(f"  {t:%H:%M:%S} {tag:5s} obv={obv:5.1f}  mfi={mfi:5.1f}  vzo={vzo:6.1f}  price3={pr3:+5.2f}%  volUD={vud:+.0f}")
for g in groups:
    for t in g:
        line(t, "GOED")
    print()
for t in bad_ticks:
    line(t, "SLECHT")
