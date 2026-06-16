#!/usr/bin/env python3
"""
DATA-INTEGRITEIT — read-only consistency checks for the brain DB.

Every check returns a Result (ok / warn / fail) with concrete details. The module mutates NOTHING:
it only SELECTs. The ONLY safe auto-fix is "rebuild the indicator_metrics cache" (build_indicator_
metrics.py per affected coin), and that is driven by the runner behind an explicit flag — never here,
never destructive (build is idempotent per symbol and only touches the CACHE table, not real data).

Scope = the coins that actually live in brain (the `coins` table = the coins with brain indicators;
today DOGEAI 2525 + NOS 244). Legacy labels exist for ~74 coins, but laag-2 is only ever BUILT for
the brain coins, so the coverage/label checks scope to those — otherwise they'd flood false FAILs for
coins that have no ticks by design.

Run standalone for a report:  integrity.py [--quick]
  --quick  : skip the heavy coin_fires<->rules drift reproduce (check 2).

The checks are consumed by routines.py (set "data-integriteit"); see brain-routines.
"""
import bisect
import re
import sys
from datetime import timedelta

from db import brain
from config import FORWARD_MINUTES

BUY_RULES = (20, 21, 22, 23)
INDICATORS = ["vzo", "phobos", "obv-x-value", "mfi", "volumeud"]
N_LOOKBACKS = 20                       # build_indicator_metrics: lookback 1..20
STUCK_RUN_MINUTES = 30                 # a 'running' routine_runs row older than this = stuck/abandoned
GAP_WARN_MINUTES = 120                 # a volumeud time-gap larger than this is flagged (info-level)


class Result:
    """One check outcome. status in {ok, warn, fail}. fix_coins = coins a cache-rebuild would repair."""
    __slots__ = ("key", "title", "status", "summary", "details", "fixable", "fix_coins")

    def __init__(self, key, title, status, summary, details=None, fixable=False, fix_coins=None):
        self.key = key
        self.title = title
        self.status = status
        self.summary = summary
        self.details = details or {}
        self.fixable = fixable
        self.fix_coins = sorted(set(fix_coins or []))

    def as_dict(self):
        return {"key": self.key, "title": self.title, "status": self.status, "summary": self.summary,
                "details": self.details, "fixable": self.fixable, "fix_coins": self.fix_coins}


# --------------------------------------------------------------------------- shared context
class Context:
    """Loads, once, the per-coin data the checks share (volumeud ticks, the expected laag-2 scope,
    the cached datetime set). Read-only."""
    def __init__(self, conn):
        self.conn = conn
        self.coins = self._scope_coins()
        self.symbol = {}            # id -> symbol
        self.vdt = {}               # id -> sorted list of volumeud datetimes
        self.vprice = {}            # id -> list of prices aligned to vdt (may contain None)
        self.scope = {}             # id -> set of in-scope datetimes (trades + promising ticks + ok ticks)
        self.cached = {}            # id -> set of datetimes present in indicator_metrics
        self.trade_dts = {}         # id -> set of coin_fires datetimes
        for cid in self.coins:
            self._load_coin(cid)

    def q(self, sql, args=()):
        with self.conn.cursor() as c:
            c.execute(sql, args)
            return c.fetchall()

    def _scope_coins(self):
        return [r["id"] for r in self.q("SELECT id FROM coins ORDER BY id")]

    def _load_coin(self, cid):
        row = self.q("SELECT symbol FROM coins WHERE id=%s", (cid,))
        self.symbol[cid] = row[0]["symbol"] if row else str(cid)

        vrows = self.q("SELECT datetime, price FROM indicators WHERE trading_symbol_id=%s "
                       "AND indicator='volumeud' AND value IS NOT NULL ORDER BY datetime", (cid,))
        vdt = [r["datetime"] for r in vrows]
        self.vdt[cid] = vdt
        self.vprice[cid] = [r["price"] for r in vrows]

        # in-scope datetimes — EXACT replica of build_indicator_metrics' scope logic, so a mismatch
        # against indicator_metrics is real staleness and not a definition difference.
        scope = set(r["datetime"] for r in
                    self.q("SELECT datetime FROM coin_fires WHERE trading_symbol_id=%s", (cid,)))
        self.trade_dts[cid] = set(scope)
        for p in self.q("SELECT period_from, period_to FROM coin_periods WHERE trading_symbol_id=%s", (cid,)):
            lo = bisect.bisect_left(vdt, p["period_from"])
            hi = bisect.bisect_right(vdt, p["period_to"])
            scope.update(vdt[lo:hi])
        for r in self.q("SELECT datetime FROM coin_moment_labels WHERE trading_symbol_id=%s "
                        "AND decision='yes'", (cid,)):
            i = bisect.bisect_right(vdt, r["datetime"])
            if i > 0:
                scope.add(vdt[i - 1])
        self.scope[cid] = scope

        self.cached[cid] = set(r["datetime"] for r in
                               self.q("SELECT DISTINCT datetime FROM indicator_metrics WHERE trading_symbol_id=%s", (cid,)))

    def ok_moment_ticks(self, cid):
        """The volumeud tick at/just before each ok-moment (decision='yes'), snapped like build does."""
        vdt = self.vdt[cid]
        out = set()
        for r in self.q("SELECT datetime FROM coin_moment_labels WHERE trading_symbol_id=%s "
                        "AND decision='yes'", (cid,)):
            i = bisect.bisect_right(vdt, r["datetime"])
            if i > 0:
                out.add(vdt[i - 1])
        return out


# --------------------------------------------------------------------------- checks
def check_laag2_coverage(ctx):
    """1. Heeft elke TRADE (coin_fires) en elk OK-moment (coin_moment_labels decision='yes', gesnapt op
    de volumeud-tick) zijn berekeningen in indicator_metrics? Dit is de aanleiding-check: niets dat er
    HOORT te staan mag ontbreken. Cache-rebuild repareert het."""
    missing_total = 0
    per_coin = {}
    fix = []
    for cid in ctx.coins:
        want = set(ctx.trade_dts[cid]) | ctx.ok_moment_ticks(cid)
        have = ctx.cached[cid]
        miss = sorted(want - have)
        per_coin[ctx.symbol[cid]] = {"trades+ok": len(want), "missing": len(miss),
                                     "sample": [str(d) for d in miss[:5]]}
        missing_total += len(miss)
        if miss:
            fix.append(cid)
    if missing_total == 0:
        return Result("laag2_coverage", "Laag-2 dekking (trades + ok-momenten)", "ok",
                      "Alle trades en ok-momenten hebben hun laag-2 berekeningen.", per_coin)
    return Result("laag2_coverage", "Laag-2 dekking (trades + ok-momenten)", "fail",
                  f"{missing_total} trade/ok-moment(en) zonder laag-2 — cache herbouwen.",
                  per_coin, fixable=True, fix_coins=fix)


def check_fires_drift(ctx, quick=False):
    """2. Reproduceert de huidige rule-engine exact de coin_fires (rule, datetime)-set? Drift = stale
    coin_fires (bv. na een afgebroken run). Read-only: draait RuleEngine.fires in-memory, schrijft NIETS.
    Zwaar (alle volumeud-ticks x 4 rules) — sla over met --quick."""
    if quick:
        return Result("fires_drift", "coin_fires ↔ rules consistentie", "warn",
                      "Overgeslagen (--quick) — draai zonder --quick voor de volledige reproduce.", {})
    from rule_engine import RuleEngine
    per_coin = {}
    status = "ok"
    for cid in ctx.coins:
        eng = RuleEngine(cid)
        try:
            expected = set()
            for rule in BUY_RULES:
                for dt in eng.fires(rule):
                    expected.add((rule, dt))
        finally:
            eng.close()
        actual = set((r["rule"], r["datetime"]) for r in
                     ctx.q("SELECT rule, datetime FROM coin_fires WHERE trading_symbol_id=%s "
                           "AND rule IN (20,21,22,23)", (cid,)))
        stale = actual - expected          # in coin_fires but the rules no longer produce it
        missing = expected - actual        # rules produce it but coin_fires lacks it
        per_coin[ctx.symbol[cid]] = {
            "expected_fires": len(expected), "actual_fires": len(actual),
            "stale_rows": len(stale), "missing_fires": len(missing),
            "sample_stale": [f"r{r}@{d}" for r, d in sorted(stale)[:5]],
            "sample_missing": [f"r{r}@{d}" for r, d in sorted(missing)[:5]]}
        if stale or missing:
            status = "fail"
    summary = ("coin_fires reproduceert exact uit de huidige rules." if status == "ok"
               else "coin_fires wijkt af van de rules — stale fires; een re-fire (persist_to_brain) is nodig.")
    return Result("fires_drift", "coin_fires ↔ rules consistentie", status, summary, per_coin)


def check_executed_nulls(ctx):
    """3. Geen NULLs op executed trades waar ze horen: best_upside (bij een buy_price), selling_price,
    selling_datetime, profit_loss."""
    rows = ctx.q(
        "SELECT trading_symbol_id sym, "
        "SUM(is_executed=1 AND buy_price IS NOT NULL AND best_upside IS NULL) bu, "
        "SUM(is_executed=1 AND selling_price IS NULL) sp, "
        "SUM(is_executed=1 AND selling_datetime IS NULL) sd, "
        "SUM(is_executed=1 AND profit_loss IS NULL) pl, "
        "SUM(is_executed=1) n_exec "
        "FROM coin_fires GROUP BY trading_symbol_id")
    per_coin = {}
    bad = 0
    for r in rows:
        sym = ctx.symbol.get(r["sym"], r["sym"])
        d = {"executed": int(r["n_exec"]), "best_upside_null": int(r["bu"]),
             "selling_price_null": int(r["sp"]), "selling_datetime_null": int(r["sd"]),
             "profit_loss_null": int(r["pl"])}
        per_coin[sym] = d
        bad += d["best_upside_null"] + d["selling_price_null"] + d["selling_datetime_null"] + d["profit_loss_null"]
    if bad == 0:
        return Result("executed_nulls", "Executed trades: geen ontbrekende waarden", "ok",
                      "best_upside / selling_price / selling_datetime / profit_loss volledig.", per_coin)
    return Result("executed_nulls", "Executed trades: geen ontbrekende waarden", "fail",
                  f"{bad} ontbrekende waarde(n) op executed trades.", per_coin)


def check_labels(ctx):
    """4. Snappen coin_moment_labels-datetimes op een echte volumeud-tick? Wezen (geen tick in de
    buurt)? Is de legacy +5s-alignment toegepast (anders staat het label 5s naast de tick)? Alleen
    voor brain-coins (andere coins hebben per definitie geen ticks)."""
    per_coin = {}
    status = "ok"
    for cid in ctx.coins:
        ticks = set(ctx.vdt[cid])
        vdt = ctx.vdt[cid]
        rows = ctx.q("SELECT datetime, source FROM coin_moment_labels WHERE trading_symbol_id=%s", (cid,))
        off_tick = orphan = plus5 = 0
        for r in rows:
            dt = r["datetime"]
            if dt in ticks:
                continue
            off_tick += 1
            # +5s un-aligned? legacy buys = signal tick + 5s; if dt+5s lands on a tick, the −5s snap
            # was never applied (see align.py).
            if (dt + timedelta(seconds=5)) in ticks:
                plus5 += 1
            # orphan: no tick within 60s either side
            i = bisect.bisect_right(vdt, dt)
            near = []
            if i > 0:
                near.append(vdt[i - 1])
            if i < len(vdt):
                near.append(vdt[i])
            if not near or min(abs((t - dt).total_seconds()) for t in near) > 60:
                orphan += 1
        per_coin[ctx.symbol[cid]] = {"labels": len(rows), "off_tick": off_tick,
                                     "orphan": orphan, "plus5s_unaligned": plus5}
        # FAIL only when a label demonstrably skipped the −5s align (dt+5s IS a tick): that is a
        # fixable mistake (re-run import_legacy_labels for the coin). Orphans in a genuine volumeud
        # gap, or sub-60s jitter, are unavoidable → WARN, so the routine isn't permanently red.
        if plus5:
            status = "fail"
        elif (orphan or off_tick) and status == "ok":
            status = "warn"
    summary = {"ok": "Alle labels zitten op een echte volumeud-tick.",
               "warn": "Sommige labels staan naast een tick (jitter of in een volumeud-gat) — geen +5s-fout.",
               "fail": "Labels met niet-toegepaste +5s-alignment — her-import nodig."}[status]
    return Result("labels", "Labels op volumeud-tick + +5s-alignment", status, summary, per_coin)


def check_promising_periods(ctx):
    """5. period_from <= period_to en binnen de indicator-datumrange van de coin."""
    per_coin = {}
    status = "ok"
    for cid in ctx.coins:
        rng = ctx.q("SELECT MIN(datetime) mn, MAX(datetime) mx FROM indicators WHERE trading_symbol_id=%s", (cid,))[0]
        bad_order = ctx.q("SELECT COUNT(*) n FROM coin_periods WHERE trading_symbol_id=%s AND period_from > period_to", (cid,))[0]["n"]
        out_range = ctx.q("SELECT COUNT(*) n FROM coin_periods WHERE trading_symbol_id=%s "
                          "AND (period_from < %s OR period_to > %s)", (cid, rng["mn"], rng["mx"]))[0]["n"]
        per_coin[ctx.symbol[cid]] = {"reversed_from_to": int(bad_order), "outside_indicator_range": int(out_range)}
        if bad_order or out_range:
            status = "fail"
    return Result("promising_periods", "Promising-periodes geldig", status,
                  "Alle periodes zijn geordend en binnen de datumrange." if status == "ok"
                  else "Er zijn omgekeerde of buiten-bereik periodes.", per_coin)


def check_rules_provenance(ctx):
    """6. Komt de laatste rules_history-snapshot per rule overeen met de huidige brain.rules? Drift =
    de rules zijn gewijzigd zonder dat de provenance is bijgewerkt."""
    import rules_history as h
    current = h.current_rules()
    drift = {}
    no_history = []
    for rn in sorted(current):
        _, snap = h._last(ctx.conn, rn)
        if snap is None:
            no_history.append(rn)
            continue
        d = h._diff(snap, current[rn])
        if d["added"] or d["removed"] or d["modified"]:
            drift[rn] = {"added": len(d["added"]), "removed": len(d["removed"]), "modified": len(d["modified"])}
    details = {"drifted_rules": drift, "rules_without_history": no_history}
    if drift:
        return Result("rules_provenance", "rules_history provenance", "fail",
                      f"{len(drift)} rule(s) wijken af van hun laatste snapshot — record() ontbreekt.", details)
    if no_history:
        return Result("rules_provenance", "rules_history provenance", "warn",
                      f"{len(no_history)} rule(s) zonder enige history-snapshot.", details)
    return Result("rules_provenance", "rules_history provenance", "ok",
                  "Elke rule komt overeen met zijn laatste snapshot.", details)


def check_cache_freshness(ctx):
    """7. Is de indicator_metrics datetime-set GELIJK aan de huidige scope (trades + promising-ticks +
    ok-ticks)? Detecteert BEIDE: ontbrekende rijen (nieuwe trades) én stale rijen (datetimes die na een
    re-fire niet meer in scope zijn). Cache-rebuild repareert het."""
    per_coin = {}
    status = "ok"
    fix = []
    for cid in ctx.coins:
        want = ctx.scope[cid]
        have = ctx.cached[cid]
        missing = want - have
        stale = have - want
        per_coin[ctx.symbol[cid]] = {
            "scope": len(want), "cached": len(have), "missing": len(missing), "stale": len(stale),
            "sample_missing": [str(d) for d in sorted(missing)[:5]],
            "sample_stale": [str(d) for d in sorted(stale)[:5]]}
        if missing or stale:
            status = "fail"
            fix.append(cid)
    return Result("cache_freshness", "Cache-versheid (indicator_metrics = scope)", status,
                  "De cache-datetimeset is exact gelijk aan de huidige scope." if status == "ok"
                  else "De cache wijkt af van de scope — cache herbouwen.", per_coin,
                  fixable=(status == "fail"), fix_coins=fix)


def check_indicators(ctx):
    """8. Geen grote gaten / NULL-prijzen die features breken; volumeud aanwezig per coin."""
    per_coin = {}
    status = "ok"
    for cid in ctx.coins:
        present = set(r["indicator"] for r in
                      ctx.q("SELECT DISTINCT indicator FROM indicators WHERE trading_symbol_id=%s", (cid,)))
        missing_ind = [i for i in INDICATORS if i not in present]
        vdt = ctx.vdt[cid]
        null_price = sum(1 for p in ctx.vprice[cid] if p is None)
        max_gap = 0.0
        gap_at = None
        for a, b in zip(vdt, vdt[1:]):
            g = (b - a).total_seconds() / 60.0
            if g > max_gap:
                max_gap, gap_at = g, a
        per_coin[ctx.symbol[cid]] = {
            "volumeud_ticks": len(vdt), "missing_indicators": missing_ind,
            "null_prices": null_price, "max_gap_min": round(max_gap, 1),
            "max_gap_after": str(gap_at) if gap_at else None}
        if missing_ind or null_price:
            status = "fail"
        elif max_gap > GAP_WARN_MINUTES and status == "ok":
            status = "warn"
    summary = {"ok": "Alle indicatoren aanwezig, geen NULL-prijzen, geen grote gaten.",
               "warn": f"Een tijdgat > {GAP_WARN_MINUTES} min in de volumeud-reeks (kan normaal zijn).",
               "fail": "Ontbrekende indicator of NULL volumeud-prijs."}[status]
    return Result("indicators", "Indicatoren compleet (volumeud, prijzen, gaten)", status, summary, per_coin)


def check_rule_settings(ctx):
    """9. min_volume aanwezig per coin × actieve buy-rule (20-23)."""
    have = set((r["trading_symbol_id"], r["rule_number"]) for r in
               ctx.q("SELECT trading_symbol_id, rule_number FROM coin_rule_settings "
                     "WHERE rule_number IN (20,21,22,23) AND min_volume IS NOT NULL"))
    missing = []
    for cid in ctx.coins:
        for rule in BUY_RULES:
            if (cid, rule) not in have:
                missing.append({"coin": ctx.symbol[cid], "rule": rule})
    if missing:
        return Result("rule_settings", "coin_rule_settings min_volume compleet", "fail",
                      f"{len(missing)} coin×rule combinatie(s) zonder min_volume.", {"missing": missing})
    return Result("rule_settings", "coin_rule_settings min_volume compleet", "ok",
                  "Elke coin × buy-rule heeft een min_volume.", {})


def check_routine_state(ctx):
    """10. Fingerprint plausibel (32-hex per set); geen vastgelopen 'running' routine_runs."""
    states = ctx.q("SELECT set_key, fingerprint FROM routine_state")
    bad_fp = [s["set_key"] for s in states
              if not (s["fingerprint"] and re.fullmatch(r"[0-9a-f]{32}", s["fingerprint"]))]
    stuck = ctx.q(
        "SELECT id, set_key, started_at FROM routine_runs WHERE status='running' "
        "AND started_at < (NOW() - INTERVAL %s MINUTE) ORDER BY started_at", (STUCK_RUN_MINUTES,))
    details = {"implausible_fingerprints": bad_fp,
               "stuck_running": [{"id": r["id"], "set": r["set_key"], "started_at": str(r["started_at"])} for r in stuck]}
    if bad_fp or stuck:
        return Result("routine_state", "routine_state / geen vastgelopen runs", "fail",
                      f"{len(stuck)} vastgelopen run(s), {len(bad_fp)} implausibele fingerprint(s).", details)
    return Result("routine_state", "routine_state / geen vastgelopen runs", "ok",
                  "Fingerprints plausibel, geen vastgelopen runs.", details)


def check_sell_record(ctx):
    """11. Heeft elke executed buy (is_executed=1) een GELDIGE verkoop: selling_price + selling_datetime
    aanwezig, selling_datetime NA de koop en binnen FORWARD_MINUTES (60 min), profit_loss berekend?
    Een executed buy zonder geldige sell-record is een defect."""
    per_coin = {}
    status = "ok"
    for cid in ctx.coins:
        r = ctx.q(
            "SELECT SUM(is_executed=1) n_exec, "
            "SUM(is_executed=1 AND (selling_price IS NULL OR selling_datetime IS NULL OR profit_loss IS NULL)) missing, "
            "SUM(is_executed=1 AND selling_datetime IS NOT NULL AND selling_datetime <= datetime) not_after, "
            "SUM(is_executed=1 AND selling_datetime IS NOT NULL "
            "    AND selling_datetime > datetime + INTERVAL %s MINUTE) past_horizon "
            "FROM coin_fires WHERE trading_symbol_id=%s", (FORWARD_MINUTES, cid))[0]
        d = {"executed": int(r["n_exec"] or 0), "missing_sell": int(r["missing"] or 0),
             "sell_not_after_buy": int(r["not_after"] or 0), "sell_past_horizon": int(r["past_horizon"] or 0)}
        per_coin[ctx.symbol[cid]] = d
        if d["missing_sell"] or d["sell_not_after_buy"] or d["sell_past_horizon"]:
            status = "fail"
    return Result("sell_record", "Verkoop-record per executed trade", status,
                  "Elke executed buy heeft een geldige verkoop binnen het horizon." if status == "ok"
                  else "Er zijn executed buys zonder geldige verkoop.", per_coin)


# --------------------------------------------------------------------------- run-all
CHECKS = [
    ("laag2_coverage", check_laag2_coverage),
    ("fires_drift", check_fires_drift),          # accepts quick=
    ("executed_nulls", check_executed_nulls),
    ("labels", check_labels),
    ("promising_periods", check_promising_periods),
    ("rules_provenance", check_rules_provenance),
    ("cache_freshness", check_cache_freshness),
    ("indicators", check_indicators),
    ("rule_settings", check_rule_settings),
    ("routine_state", check_routine_state),
    ("sell_record", check_sell_record),
]


def run_all(conn=None, quick=False):
    """Run every check and return a list[Result]. Read-only. Opens its own brain conn if none given."""
    own = conn is None
    conn = conn or brain()
    try:
        ctx = Context(conn)
        results = []
        for key, fn in CHECKS:
            if key == "fires_drift":
                results.append(fn(ctx, quick=quick))
            else:
                results.append(fn(ctx))
        return results
    finally:
        if own:
            conn.close()


def worst(results):
    order = {"ok": 0, "warn": 1, "fail": 2}
    return max((r.status for r in results), key=lambda s: order[s], default="ok")


def _print_report(results):
    icon = {"ok": "✓", "warn": "⚠", "fail": "✗"}
    print("=== DATA-INTEGRITEIT ===")
    for r in results:
        print(f"  {icon[r.status]} [{r.status.upper():4}] {r.title} — {r.summary}")
        for coin, d in (r.details.items() if isinstance(r.details, dict) else []):
            if isinstance(d, dict):
                flags = ", ".join(f"{k}={v}" for k, v in d.items() if v not in (0, [], None, ""))
                if flags:
                    print(f"        {coin}: {flags}")
    print(f"  -> totaal: {worst(results).upper()}")


if __name__ == "__main__":
    res = run_all(quick="--quick" in sys.argv)
    _print_report(res)
    sys.exit(0 if worst(res) != "fail" else 1)
