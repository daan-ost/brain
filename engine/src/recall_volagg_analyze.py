#!/usr/bin/env python3
"""
recall_volagg_analyze — offline analyse van de flood-dump (recall_volagg_flood.json). READ-ONLY.

De BESLISSENDE test (projectprincipe 2, bad-edge): zet de gate-drempel net voorbij de meest-extreme
GOEDE flood-tick, zodat 100% van de goede bijna-rakers behouden blijft, en meet hoeveel van de SLECHTE
collateral de gate dan nog tegenhoudt. Een bruikbare discriminator houdt (bijna) alle goede én weert de
meeste slechte. Plus het succescriterium kept_good >= 2x kept_bad, en een temporele holdout.

Raw-aggregaten (sum_raw/slope_raw/relvol) zijn coin-afhankelijk -> per coin. Scale-free
(cnt_neg/cnt_neg_frac/slope_z/net_pos_frac) -> ook pooled.
"""
import json
import numpy as np

D = json.load(open("../out/opt/recall_volagg_flood.json"))
WINDOWS = (30, 60, 120)
AGGS = ("sum_raw", "cnt_neg", "cnt_neg_frac", "slope_raw", "slope_z", "net_pos_frac", "relvol")
SCALE_FREE = {"cnt_neg", "cnt_neg_frac", "slope_z", "net_pos_frac"}   # poolable cross-coin


def col(ticks, agg, N):
    key = f"{agg}_{N}"
    g = [t[key] for t in ticks if t["klasse"] == "good" and t.get(key) is not None]
    b = [t[key] for t in ticks if t["klasse"] == "bad" and t.get(key) is not None]
    return np.asarray(g, float), np.asarray(b, float)


def bad_edge(g, b):
    """Keep 100% of good at the bad edge; report fraction of bad rejected and the 2x bar.
    Tries both directions (good-high keeps >=min(good); good-low keeps <=max(good))."""
    if len(g) == 0 or len(b) == 0:
        return None
    out = []
    # good is HIGH: keep ticks >= min(good) -> all good kept; bad rejected = bad < min(good)
    thr = g.min()
    kept_bad = int((b >= thr).sum())
    out.append((">=", float(thr), len(b) - kept_bad, kept_bad))
    # good is LOW: keep ticks <= max(good)
    thr = g.max()
    kept_bad = int((b <= thr).sum())
    out.append(("<=", float(thr), len(b) - kept_bad, kept_bad))
    # pick the direction that rejects the most bad
    best = max(out, key=lambda x: x[2])
    dirn, thr, rej, kept = best
    return {"dir": dirn, "thr": thr, "n_good": len(g), "n_bad": len(b),
            "bad_rejected": rej, "bad_kept": kept,
            "reject_frac": rej / len(b), "ratio_after": (len(g) / kept) if kept else float("inf")}


def report(ticks, label):
    ng = sum(1 for t in ticks if t["klasse"] == "good")
    nb = sum(1 for t in ticks if t["klasse"] == "bad")
    if ng == 0 or nb == 0:
        print(f"\n### {label}: good={ng} bad={nb} — te weinig om te scheiden"); return
    base = nb / (ng + nb)
    print(f"\n### {label}: flood good={ng} bad={nb} | base-rate(meerderheid=slecht)={base:.2f}")
    print("  bad-edge (houd 100% goed): hoeveel slecht geweerd? + ratio goed/rest-slecht")
    rows = []
    for agg in AGGS:
        for N in WINDOWS:
            r = bad_edge(*col(ticks, agg, N))
            if r:
                rows.append((r["reject_frac"], r["ratio_after"], agg, N, r))
    rows.sort(key=lambda x: (-x[0], -x[1]))
    for rf, ra, agg, N, r in rows[:6]:
        bar = "  <-- 2x gehaald" if r["ratio_after"] >= 2 and r["bad_rejected"] > 0 else ""
        print(f"    {agg:>13} N={N:>3} | weert {r['bad_rejected']:>3}/{r['n_bad']:<3} "
              f"({rf*100:>3.0f}%) slecht, houdt alle {r['n_good']} goed | "
              f"ratio na {r['ratio_after']:.2f} thr {r['dir']}{r['thr']:.4g}{bar}")


def holdout(ticks, agg, N, label):
    """Temporal split per coin: fit the bad-edge threshold on the early half, apply to the late half."""
    key = f"{agg}_{N}"
    rows = [t for t in ticks if t.get(key) is not None]
    rows.sort(key=lambda t: t["dt"])
    if len(rows) < 8:
        print(f"  holdout {agg} N={N} ({label}): te weinig ticks ({len(rows)})"); return
    mid = len(rows) // 2
    train, test = rows[:mid], rows[mid:]
    tg = np.array([t[key] for t in train if t["klasse"] == "good"], float)
    if len(tg) == 0:
        print(f"  holdout {agg} N={N} ({label}): geen goede in train"); return
    # use the better direction from train bad-edge
    r = bad_edge(tg, np.array([t[key] for t in train if t["klasse"] == "bad"], float))
    if not r:
        print(f"  holdout {agg} N={N} ({label}): geen bad in train"); return
    dirn, thr = r["dir"], r["thr"]
    teg = [t[key] for t in test if t["klasse"] == "good"]
    teb = [t[key] for t in test if t["klasse"] == "bad"]
    keep = (lambda v: v >= thr) if dirn == ">=" else (lambda v: v <= thr)
    kg = sum(1 for v in teg if keep(v)); kb = sum(1 for v in teb if keep(v))
    print(f"  holdout {agg} N={N} ({label}): train thr {dirn}{thr:.4g} | "
          f"test goed behouden {kg}/{len(teg)}, slecht doorgelaten {kb}/{len(teb)} | "
          f"test-ratio {kg/kb if kb else float('inf'):.2f}")


def main():
    print("=== FLOOD-OMVANG per rule ===")
    for rule in (20, 21, 22, 23):
        tk = [t for t in D["ticks"] if t["home"] == rule]
        for sym in (2525, 244):
            ts = [t for t in tk if t["sym"] == sym]
            g = sum(1 for t in ts if t["klasse"] == "good"); b = sum(1 for t in ts if t["klasse"] == "bad")
            if g + b:
                print(f"  r{rule} sym{sym}: good={g} bad={b}")

    for rule in (20, 21, 22, 23):
        tk = [t for t in D["ticks"] if t["home"] == rule]
        # scale-free pooled
        sf = [{**t} for t in tk]
        report(sf, f"rule {rule} — POOLED scale-free")
        # per coin (incl raw)
        for sym in (2525, 244):
            ts = [t for t in tk if t["sym"] == sym]
            if sum(1 for t in ts if t["klasse"] == "good") and sum(1 for t in ts if t["klasse"] == "bad"):
                report(ts, f"rule {rule} — sym {sym} (incl raw)")

    # holdout op de rule die het meest belooft (r21 = de prijs/muur) — beste scale-free aggregaten
    print("\n=== HOLDOUT (temporeel, per coin) — rule 21 (de muur) ===")
    for sym in (2525, 244):
        ts = [t for t in D["ticks"] if t["home"] == 21 and t["sym"] == sym]
        for agg in ("cnt_neg", "cnt_neg_frac", "slope_z"):
            holdout(ts, agg, 60, f"sym{sym}")

    # de enige ogenschijnlijk-sterke in-sample separators (kleine floods, enkel-coin) — holdout-check
    print("\n=== HOLDOUT — de schijnbaar-sterke kleine gevallen (r22 slope_z NOS, r23 relvol DOGEAI) ===")
    holdout([t for t in D["ticks"] if t["home"] == 22 and t["sym"] == 244], "slope_z", 30, "r22 sym244")
    holdout([t for t in D["ticks"] if t["home"] == 23 and t["sym"] == 2525], "relvol", 120, "r23 sym2525")


if __name__ == "__main__":
    main()
