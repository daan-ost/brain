#!/usr/bin/env python3
"""
recall_volagg — test of een LANGE-PERIODE volume-aggregaat (som-volume / aantal-negatieve-ticks /
netto-helling over lange vensters 30/60/120 ticks) als RULE-SPECIFIEKE discriminator de GOEDE
bijna-rakers scheidt van de SLECHTE collateral die het loosenen van die rule binnenhaalt.

READ-ONLY: mutates NOTHING (niet brain, niet rules, niet volume.py). Bouwt voort op recall_shadow
(de exacte in-memory full re-fire met single-position dedup + best_upside) en recall_loop (loosen_of).

WAT NIEUW IS t.o.v. de 31 window-metrics en new_feat_lib (lookbacks 3..20): de aggregaten hier zijn
over LANGE vensters (30/60/120 ticks), leak-vrij as-of op de RAW volumeud-reeks (eng._vals("volumeud")).
Per venster zowel RAW (scale-afhankelijk, NOS!=DOGEAI) als SCALE-FREE varianten (voor cross-coin):

  sum_raw      = som van de ruwe volumeud-waarden over N ticks            (raw)
  cnt_neg      = aantal ticks met value<0 over N                          (count, scale-free)
  cnt_neg_frac = cnt_neg / N                                              (scale-free)
  slope_raw    = OLS-helling van de ruwe reeks (oud->nieuw)               (raw)
  slope_z      = OLS-helling van de z-gescoorde reeks                     (scale-free)
  net_pos_frac = som(value)/som(|value|)  in [-1,1]  (netto richting)     (scale-free)
  relvol       = value[0] / mediaan(|value| over N)                       (scale-free)

De GATE: een aggregaat-conditie {rule:(agg,lo,hi)} die — bovenop een loosen — de fires van die rule
extra filtert. evaluate_gated() draait de echte full re-fire met loosen + gate, dedup en al, en geeft
de pooled good/bad terug. Zo meten we de ECHTE precisie-kost (niet alleen in-sample separatie).

Subcommando's:
  probe                 — aggregaten zijn berekenbaar + reproduceer de baseline
  separate [rule]       — per feature-target op die rule: flood (new_good vs new_bad), en de
                          in-sample separatie (Cohen's d + beste threshold-gap) van elk aggregaat
  gate <sym> <rule> ... — (handmatig) een gated re-fire
"""
import bisect
import datetime as _dt
import json
import sys
import numpy as np

from db import brain
from recall_shadow import RecallEval, caught_at, COINS, RULES, GOOD_EDGE, BAD_EDGE

WINDOWS = (30, 60, 120)
AGGS = ("sum_raw", "cnt_neg", "cnt_neg_frac", "slope_raw", "slope_z", "net_pos_frac", "relvol")


def _ols(y):
    """OLS slope of y indexed oldest->newest."""
    n = len(y)
    if n < 2:
        return 0.0
    t = np.arange(n, dtype=float)
    tm = t.mean()
    den = ((t - tm) ** 2).sum()
    if den == 0:
        return 0.0
    return float(((t - tm) * (y - y.mean())).sum() / den)


def agg_values(vals_newest_first):
    """All long-period aggregates from a newest-first raw volumeud list. Returns {agg: float|None}."""
    x = np.asarray(vals_newest_first, dtype=float)
    out = {a: None for a in AGGS}
    if x.size < 2:
        return out
    y = x[::-1]                       # oldest->newest for slope
    absx = np.abs(x)
    out["sum_raw"] = float(x.sum())
    out["cnt_neg"] = float((x < 0).sum())
    out["cnt_neg_frac"] = float((x < 0).mean())
    out["slope_raw"] = _ols(y)
    sd = y.std()
    out["slope_z"] = _ols((y - y.mean()) / sd) if sd > 0 else 0.0
    s_abs = float(absx.sum())
    out["net_pos_frac"] = float(x.sum() / s_abs) if s_abs > 0 else 0.0
    med = float(np.median(absx))
    out["relvol"] = float(x[0] / med) if med > 0 else None
    return out


class VolAggEval(RecallEval):
    """RecallEval + long-period volume aggregates at every candidate tick + a gated re-fire."""

    def __init__(self, sym):
        super().__init__(sym)
        self._agg = {}                # dt -> {window -> {agg -> val}}
        for dt in self.cand:
            per = {}
            for N in WINDOWS:
                vals, _ = self.eng._vals("volumeud", N, dt)   # leak-free as-of, RAW newest-first
                per[N] = agg_values(vals)
            self._agg[dt] = per

    def agg_at(self, dt, N, agg):
        return self._agg.get(dt, {}).get(N, {}).get(agg)

    # ---- gated fires: loosen-override AND an aggregate band on the rule ----------
    def fires_for_gated(self, rule, ov, gate):
        """gate = (N, agg, lo, hi) or None. A candidate fires iff the (overridden) feature subrules
        pass, volume_check passes, AND the aggregate sits in [lo,hi] (None side = unbounded)."""
        mv = self.minvol[rule]
        from volume import volume_settings, check_volumeud_3
        vset = volume_settings(rule)
        cols = self.sub[rule]
        out = []
        for dt in self.cand:
            ok = True
            for col in cols:
                if col.get("vol"):
                    continue
                bmin, bmax = ov.get(col["i"], (col["b_min"], col["b_max"]))
                from recall_shadow import _passes
                if _passes(col["val"][dt], bmin, bmax) is False:
                    ok = False
                    break
            if not ok:
                continue
            if gate is not None:
                N, agg, lo, hi = gate
                v = self.agg_at(dt, N, agg)
                if v is None:
                    continue
                if (lo is not None and v < lo) or (hi is not None and v > hi):
                    continue
            if check_volumeud_3(self._volrows_cache_get(dt), mv, vset):
                out.append(dt)
        return out

    def evaluate_gated(self, overrides=None, gates=None):
        """overrides={rule:{i:(bmin,bmax)}}, gates={rule:(N,agg,lo,hi)}. Full re-fire + dedup."""
        overrides = overrides or {}
        gates = gates or {}
        fires = []
        for rule in RULES:
            for dt in self.fires_for_gated(rule, overrides.get(rule, {}), gates.get(rule)):
                fires.append((dt, rule))
        fires.sort()
        open_until = None
        g = b = n = 0
        exec_good, exec_bad, exec_all = set(), set(), set()
        holds = []
        for dt, rule in fires:
            buy = self.price_at(dt)
            if open_until is not None and dt <= open_until:
                continue
            sres = self.sell.sell(dt, buy, rule) if buy else None
            open_until = sres["selling_date"] if sres else dt
            holds.append((dt, open_until))
            n += 1
            exec_all.add(dt)
            bu = self.best_upside(dt, buy)
            if bu is not None:
                if bu >= GOOD_EDGE:
                    g += 1; exec_good.add(dt)
                elif bu < BAD_EDGE:
                    b += 1; exec_bad.add(dt)
        return {"good": g, "bad": b, "exec": n, "exec_good": exec_good,
                "exec_bad": exec_bad, "exec_all": exec_all, "holds": holds}


# ---------------------------------------------------------------------------
# separation analysis
# ---------------------------------------------------------------------------
def cohens_d(a, b):
    a, b = np.asarray(a, float), np.asarray(b, float)
    if len(a) < 2 or len(b) < 2:
        return None
    va, vb = a.var(ddof=1), b.var(ddof=1)
    sp = np.sqrt((va + vb) / 2)
    if sp == 0:
        return 0.0
    return float((a.mean() - b.mean()) / sp)


def best_gap(good, bad):
    """Best single threshold separating good from bad. Returns (purity, direction, thr) where purity
    is the fraction correctly classified at the best threshold. direction '>=' means good is high."""
    g, b = np.asarray(good, float), np.asarray(bad, float)
    g, b = g[~np.isnan(g)], b[~np.isnan(b)]
    if len(g) == 0 or len(b) == 0:
        return None
    cands = np.unique(np.concatenate([g, b]))
    best = (0.0, None, None)
    tot = len(g) + len(b)
    for thr in cands:
        # good high
        corr = (g >= thr).sum() + (b < thr).sum()
        if corr / tot > best[0]:
            best = (corr / tot, ">=", float(thr))
        # good low
        corr = (g <= thr).sum() + (b > thr).sum()
        if corr / tot > best[0]:
            best = (corr / tot, "<=", float(thr))
    return best


def load_targets():
    conn = brain()
    with conn.cursor() as c:
        c.execute("SELECT id, trading_symbol_id sym, group_lead, group_to, max_up_pct, home_rule, "
                  "home_rule_fails, candidate_rules FROM promising_recall_state "
                  "WHERE caught=0 AND blocker='feature' AND home_rule_fails BETWEEN 1 AND 3 "
                  "ORDER BY home_rule, home_rule_fails, max_up_pct DESC")
        rows = c.fetchall()
    conn.close()
    out = []
    for r in rows:
        cr = json.loads(r["candidate_rules"])
        out.append({"id": r["id"], "sym": r["sym"], "lead": r["group_lead"], "up": float(r["max_up_pct"]),
                    "home": r["home_rule"], "fails": r["home_rule_fails"],
                    "T": _dt.datetime.strptime(cr["T"], "%Y-%m-%d %H:%M:%S"),
                    "subs": cr["home_fail_subrules"]})
    return out


def loosen_of(target):
    EPS = 1e-6
    rule = target["home"]
    ov = {}
    for s in target["subs"]:
        if s["value"] in (None, "PASS"):
            continue
        v = float(s["value"])
        bmin = s["b_min"] if s["b_min"] is None else float(s["b_min"])
        bmax = s["b_max"] if s["b_max"] is None else float(s["b_max"])
        if s["side"] == "below_min":
            bmin = v - EPS
        else:
            bmax = v + EPS
        ov[s["i"]] = (bmin, bmax)
    return {rule: ov}


def separate(only_rule=None):
    """Per feature-target: loosen the home rule, read the flood (new_good vs new_bad) via the real
    re-fire, then measure how well each long-period volume aggregate separates the new_good from the
    new_bad — pooled across coins, per rule."""
    evs = {s: VolAggEval(s) for s in COINS}
    base = {s: evs[s].evaluate_gated({}, {}) for s in COINS}
    targets = load_targets()
    if only_rule:
        targets = [t for t in targets if t["home"] == int(only_rule)]

    # accumulate, per rule, the union of flood good/bad ticks with their aggregate values
    per_rule = {}                  # rule -> {agg: {N: {"good":[..],"bad":[..]}}}
    flood_summary = []
    dump = {"targets": [], "ticks": []}
    for t in targets:
        sym = t["sym"]
        ev = evs[sym]
        loos = loosen_of(t)
        cand = ev.evaluate_gated(loos, {})
        new_good = sorted(cand["exec_good"] - base[sym]["exec_good"])
        new_bad = sorted(cand["exec_bad"] - base[sym]["exec_bad"])
        flood_summary.append((t, len(new_good), len(new_bad)))
        dump["targets"].append({"id": t["id"], "sym": sym, "lead": str(t["lead"]), "up": t["up"],
                                "home": t["home"], "fails": t["fails"],
                                "n_good": len(new_good), "n_bad": len(new_bad)})
        pr = per_rule.setdefault(t["home"], {a: {N: {"good": [], "bad": [], "gsym": [], "bsym": []}
                                                 for N in WINDOWS} for a in AGGS})
        for kl, dts in (("good", new_good), ("bad", new_bad)):
            for d in dts:
                rec = {"home": t["home"], "sym": sym, "dt": str(d), "klasse": kl, "target_id": t["id"]}
                for agg in AGGS:
                    for N in WINDOWS:
                        v = ev.agg_at(d, N, agg)
                        rec[f"{agg}_{N}"] = v
                        if v is not None:
                            pr[agg][N][kl].append(v); pr[agg][N][kl[0] + "sym"].append(sym)
                dump["ticks"].append(rec)
    json.dump(dump, open("../out/opt/recall_volagg_flood.json", "w"), indent=1, default=str)
    print("-> ../out/opt/recall_volagg_flood.json", flush=True)

    print("=== FLOOD per target (new executed good vs bad bij loosen van de home-rule) ===", flush=True)
    for t, ng, nb in sorted(flood_summary, key=lambda x: (x[0]["home"], -x[0]["up"])):
        print(f"  r{t['home']} sym{t['sym']} {t['lead']} up{t['up']}% f{t['fails']}: "
              f"+good={ng} +bad={nb}", flush=True)

    print("\n=== SEPARATIE per rule: long-period volume-aggregaat scheidt flood-good van flood-bad? ===",
          flush=True)
    print("(pooled over alle targets van die rule; |d|>=0.8 = sterk, purity = beste in-sample "
          "threshold-classificatie)\n", flush=True)
    for rule in sorted(per_rule):
        pr = per_rule[rule]
        # dedup good/bad ticks pooled (a tick can repeat across targets) — use the first occurrence
        ng = len(set().union(*[]) ) if False else None
        # report total distinct good/bad pooled
        any_agg = pr["cnt_neg"][WINDOWS[0]]
        print(f"--- rule {rule}: flood-good~{len(any_agg['good'])} flood-bad~{len(any_agg['bad'])} "
              f"(niet-ontdubbeld over targets) ---", flush=True)
        rows = []
        for agg in AGGS:
            for N in WINDOWS:
                gd, bd = pr[agg][N]["good"], pr[agg][N]["bad"]
                d = cohens_d(gd, bd)
                gap = best_gap(gd, bd)
                if d is None or gap is None:
                    continue
                rows.append((abs(d), d, gap[0], agg, N, gap[1], gap[2],
                             float(np.median(gd)), float(np.median(bd))))
        rows.sort(reverse=True)
        for absd, d, pur, agg, N, dirn, thr, mg, mb in rows[:6]:
            print(f"  {agg:>13} N={N:>3} | d={d:+.2f} purity={pur:.2f} "
                  f"thr {dirn}{thr:.4g} | med good={mg:.4g} bad={mb:.4g}", flush=True)
        print(flush=True)

    for ev in evs.values():
        ev.close()


def probe():
    print("=== probe: VolAggEval reproduceert de baseline + aggregaten berekenbaar ===", flush=True)
    conn = brain()
    for sym in COINS:
        ev = VolAggEval(sym)
        r = ev.evaluate_gated({}, {})
        with conn.cursor() as c:
            c.execute("SELECT COUNT(*) n, SUM(best_upside>=3) g, SUM(best_upside<0.5) b "
                      "FROM coin_fires WHERE trading_symbol_id=%s AND is_executed=1", (sym,))
            db = c.fetchone()
        match = (r["good"] == int(db["g"]) and r["bad"] == int(db["b"]) and r["exec"] == int(db["n"]))
        # aggregate coverage
        nfull = sum(1 for dt in ev.cand if ev.agg_at(dt, 120, "sum_raw") is not None)
        print(f"  sym {sym}: shadow {r['good']}/{r['bad']}/{r['exec']} vs DB {db['g']}/{db['b']}/{db['n']} "
              f"MATCH={match} | cand={len(ev.cand)} met 120-tick agg={nfull}", flush=True)
        ev.close()
    conn.close()


if __name__ == "__main__":
    cmd = sys.argv[1] if len(sys.argv) > 1 else "probe"
    if cmd == "probe":
        probe()
    elif cmd == "separate":
        separate(sys.argv[2] if len(sys.argv) > 2 else None)
    else:
        print(__doc__)
