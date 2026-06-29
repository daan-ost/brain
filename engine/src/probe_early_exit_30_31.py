#!/usr/bin/env python3
"""
READ-ONLY probe: kan een VROEGE-EXIT (op het pad NÁ instap) de verliezers van rule 30/31 afkappen
ZONDER de winnaars te raken? Gebruikt alleen wat al per trade in coin_fires staat:
  - lowest10  = de vroege dip (% onder instap, eerste ~10 ticks) = MAE-proxy
  - horizons[h].up = max stijging (MFE) binnen h min ná instap (h in 5/10/15/30/45/60)
  - profit_loss = de gerealiseerde uitkomst (de ENIGE succes-maat)
Splitst PUUR (geen 20-23-fire in het hold-window) vs OVERLAP (wel) — die zijn economisch verschillend.

Geen DB-mutatie, geen refire. Puur SELECT + numpy.
Draai: engine/.venv/bin/python probe_early_exit_30_31.py
"""
import bisect
import json
from collections import defaultdict

import numpy as np

from db import brain

RULES = (30, 31)
conn = brain()
c = conn.cursor()

# 30/31 executed trades BINNEN regime
c.execute("""
SELECT cf.trading_symbol_id sym, cf.symbol, cf.rule, cf.datetime buy_dt, cf.selling_datetime sell_dt,
       cf.profit_loss pl, cf.lowest10 low10, cf.horizons
FROM coin_fires cf
WHERE cf.rule IN (30,31) AND cf.is_executed=1 AND cf.profit_loss IS NOT NULL
  AND NOT EXISTS (SELECT 1 FROM coin_regime r WHERE r.trading_symbol_id=cf.trading_symbol_id
                  AND r.state='inactive' AND cf.datetime>=r.period_from AND cf.datetime<r.period_to + INTERVAL 1 DAY)
""")
trades = c.fetchall()

# 20-23 fire-datetimes per munt (elke status) — voor de overlap-bepaling
c.execute("SELECT trading_symbol_id sym, datetime dt FROM coin_fires WHERE rule IN (20,21,22,23)")
f2023 = defaultdict(list)
for r in c.fetchall():
    f2023[r["sym"]].append(r["dt"])
for k in f2023:
    f2023[k].sort()
conn.close()


def has_overlap(sym, buy_dt, sell_dt):
    lst = f2023.get(sym, [])
    i = bisect.bisect_right(lst, buy_dt)          # eerste 20-23-fire ná de instap
    return i < len(lst) and lst[i] <= (sell_dt or buy_dt)


def early_up(hz, h):
    if not hz:
        return None
    d = json.loads(hz) if isinstance(hz, str) else hz
    v = d.get(str(h))
    return v["up"] if v else None


def kl(pl):
    return "slecht" if pl < 0 else ("middel" if pl < 3 else "goed")


rows = []
for t in trades:
    pl = float(t["pl"])
    rows.append({
        "rule": t["rule"], "pl": pl, "kl": kl(pl),
        "low10": float(t["low10"]) if t["low10"] is not None else None,
        "up5": early_up(t["horizons"], 5), "up10": early_up(t["horizons"], 10),
        "overlap": has_overlap(t["sym"], t["buy_dt"], t["sell_dt"]),
    })

R = [r for r in rows if r["low10"] is not None]


def pctl(xs, ps):
    a = np.asarray(xs, float)
    return [round(float(np.percentile(a, p)), 2) for p in ps] if len(a) else []


def perm_diff(group_a, group_b, n=5000, seed=42):
    """Permutatie-toets op verschil in gemiddelde tussen twee groepen."""
    a, b = np.asarray(group_a, float), np.asarray(group_b, float)
    if len(a) < 3 or len(b) < 3:
        return None, None
    obs = a.mean() - b.mean()
    pool = np.concatenate([a, b])
    na = len(a)
    rng = np.random.default_rng(seed)
    cnt = 0
    for _ in range(n):
        rng.shuffle(pool)
        if abs(pool[:na].mean() - pool[na:].mean()) >= abs(obs):
            cnt += 1
    return round(float(obs), 3), round((cnt + 1) / (n + 1), 4)


print("=" * 78)
print("VROEGE-EXIT PROBE rule 30/31 — read-only, alleen coin_fires pad-data")
print(f"trades binnen regime: {len(rows)} (met lowest10: {len(R)})")
print("=" * 78)

# 1) PUUR vs OVERLAP — aantallen, klasse, marge
print("\n[1] PUUR vs OVERLAP (overlap = 20-23-fire in het hold-window)")
for grp in ("puur", "overlap"):
    sub = [r for r in R if (r["overlap"] == (grp == "overlap"))]
    if not sub:
        continue
    n = len(sub)
    g = sum(r["kl"] == "goed" for r in sub)
    m = sum(r["kl"] == "middel" for r in sub)
    b = sum(r["kl"] == "slecht" for r in sub)
    avg = np.mean([r["pl"] for r in sub])
    print(f"  {grp:8s}: n={n:5d} ({n/len(R)*100:4.1f}%) | goed {g} / middel {m} / slecht {b} "
          f"({b/n*100:4.1f}% slecht) | gem {avg:+.3f}%/trade")

# 2) Vroege dip (lowest10): scheidt hij winnaars van verliezers?
print("\n[2] VROEGE DIP (lowest10, % onder instap) per uitkomst — pctl [10,25,50,75,90]")
for k in ("goed", "middel", "slecht"):
    xs = [r["low10"] for r in R if r["kl"] == k]
    print(f"  {k:7s}: n={len(xs):5d} | mediaan {np.median(xs):+.2f}% | pctl {pctl(xs,[10,25,50,75,90])}")
win = [r["low10"] for r in R if r["pl"] >= 0]
los = [r["low10"] for r in R if r["pl"] < 0]
obs, p = perm_diff(win, los)
print(f"  winnaars (pl>=0) gem dip {np.mean(win):+.2f}%  vs  verliezers {np.mean(los):+.2f}%  "
      f"| Δ={obs} p_perm={p}")

# 3) DE KERNVRAAG: dippen overlap-WINNAARS net zo diep als pure VERLIEZERS?
print("\n[3] FLIP-RISICO: overlap-winnaars vs pure-verliezers (vroege dip)")
ow = [r["low10"] for r in R if r["overlap"] and r["pl"] >= 0]
pv = [r["low10"] for r in R if (not r["overlap"]) and r["pl"] < 0]
if ow and pv:
    print(f"  overlap-winnaars : n={len(ow):4d} | mediaan dip {np.median(ow):+.2f}% | pctl {pctl(ow,[10,25,50,75,90])}")
    print(f"  pure-verliezers  : n={len(pv):4d} | mediaan dip {np.median(pv):+.2f}% | pctl {pctl(pv,[10,25,50,75,90])}")
    obs2, p2 = perm_diff(ow, pv)
    print(f"  Δ gem dip = {obs2}  p_perm={p2}   (groot+significant verschil = ruimte; overlap = ruis)")

# 4) Vroege follow-through (up5/up10): komt er bij winnaars vroege stijging die bij verliezers uitblijft?
print("\n[4] VROEGE STIJGING (horizons up5 / up10) per uitkomst — mediaan")
for k in ("goed", "middel", "slecht"):
    u5 = [r["up5"] for r in R if r["kl"] == k and r["up5"] is not None]
    u10 = [r["up10"] for r in R if r["kl"] == k and r["up10"] is not None]
    print(f"  {k:7s}: up5 mediaan {np.median(u5):+.2f}% (n={len(u5)}) | up10 mediaan {np.median(u10):+.2f}% (n={len(u10)})")

# 5) RUWE flip-cost van een drempel-exit op lowest10 (BOVENGRENS-benadering)
print("\n[5] RUWE flip-cost: exit zodra de vroege dip drempel D raakt  (≈ realiseert ~D%)")
print("    BENADERING (bovengrens): low10<=D => uitkomst ~D; anders ongewijzigd. Geen echte timing-sim.")
base_sum = sum(r["pl"] for r in R)
base_los = sum(r["pl"] < 0 for r in R)
print(f"    huidig: Σ {base_sum:+.0f}% | verliezers {base_los}/{len(R)} ({base_los/len(R)*100:.1f}%)")
for D in (-1.0, -1.5, -2.0, -3.0, -4.0):
    cut = [r for r in R if r["low10"] is not None and r["low10"] <= D]
    new_sum = sum((D if (r["low10"] is not None and r["low10"] <= D) else r["pl"]) for r in R)
    winners_hit = sum(1 for r in cut if r["pl"] >= 3)             # goede trades die we afkappen
    losers_avoided = sum(1 for r in cut if r["pl"] < 0)
    ow_hit = sum(1 for r in cut if r["overlap"] and r["pl"] >= 0)  # overlap-winnaars geraakt
    print(f"    D={D:+.1f}%: raakt {len(cut):4d} trades | Σ {base_sum:+.0f}→{new_sum:+.0f}% | "
          f"vermijdt {losers_avoided} verliezers, kapt {winners_hit} GOEDE (waarvan {ow_hit} overlap-winnaars)")

print("\nKLAAR. Conclusie-leidraad: [2]/[3] groot+significant verschil = vroege-exit kan scheiden; "
      "[4] vroege stijging alleen bij winnaars = scratch-exit kansrijk; [5] Σ omhoog met weinig GOEDE = winst.")
