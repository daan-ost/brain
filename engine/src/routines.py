#!/usr/bin/env python3
"""
Routine RUNNER — runs an ordered chain of automation routines and journals every run to the
brain DB (routine_runs + routine_run_log), which the /routines screen shows. This is routine #1
(rule-optimization); add more by appending to REGISTRY — they run after each other, one journal per
chain execution.

Designed to be the body of a LOCAL Claude Code routine (or a plain cron/launchd job): it runs on
the Mac, so it reaches the local MAMP `brain` DB. It PROPOSES rule changes (logs them); it does not
apply anything unless you wire an apply-routine in explicitly.

Usage: routines.py [--no-rebuild] [--date YYYY-MM-DD] [--trigger routine|manual|api]
"""
import datetime
import hashlib
import json
import os
import subprocess
import sys

from db import brain
import daily_optimization as opt

NO_REBUILD = "--no-rebuild" in sys.argv

# A3: live progress-log. Voorheen schreef Journal pas aan het eind van de routine — bij vastlopen of
# lange subprocess-aanroepen had je nul zicht op waar de run zat. Nu schrijven we elke add() én elke
# fase-overgang line-buffered naar een append-only file in engine/out/routines/, plus stdout (flush=True).
_PROGRESS_DIR = os.path.join(os.path.dirname(os.path.abspath(__file__)), "..", "out", "routines")
os.makedirs(_PROGRESS_DIR, exist_ok=True)
_PROGRESS_PATH = os.path.join(_PROGRESS_DIR, f"progress_{datetime.datetime.now().strftime('%Y%m%d_%H%M%S')}.log")
_pf = open(_PROGRESS_PATH, "a", buffering=1)        # line-buffered → onmiddellijk leesbaar via `tail -f`


def _live(msg, set_key=None):
    ts = datetime.datetime.now().strftime("%H:%M:%S")
    line = f"[{ts}] " + (f"[{set_key}] " if set_key else "") + str(msg)
    _pf.write(line + "\n")
    print(line, flush=True)
APPLY = "--apply" in sys.argv          # actually apply safe candidates; without it, propose-only
FORCE = "--force" in sys.argv          # bypass the data-changed gate (manual preview / testing)
FIX = "--fix" in sys.argv              # data-integriteit: allow the safe cache-rebuild auto-fix
QUICK = "--quick" in sys.argv          # data-integriteit: skip the heavy fires<->rules drift reproduce
RUN_DATE = sys.argv[sys.argv.index("--date") + 1] if "--date" in sys.argv else None
TRIGGER = sys.argv[sys.argv.index("--trigger") + 1] if "--trigger" in sys.argv else "manual"
SET_ARG = sys.argv[sys.argv.index("--set") + 1] if "--set" in sys.argv else "rule-precision"


def input_fingerprint(with_labels=False, with_sell=False, with_fires=False):
    """Signature of everything that determines the analysis outcome: the raw `indicators` per coin
    (count + latest datetime) AND the active `rules` (count + latest change). New data OR a rule
    change (auto-applied last run, or a manual edit) bumps it; a converged run with no new data
    leaves it stable → the gate skips. For the RECALL set, the ok-LABELS also bump it (with_labels)
    so new promising labels re-trigger the triage. For the SELL-TUNING set (with_sell) the sell
    instelknoppen (strategies + coin_strategies), the manual trade-overrides (manual_set_at) and the
    coin count also bump it, so a knob-edit, a manual hard-sell/klasse, or a new coin re-triggers the
    tuning. For RULE-PRECISION (with_fires) the executed coin_fires DRIFT is added: count + goed/slecht
    split (= het ratio-relevante deel). coin_fires zijn afgeleid, MAAR een upstream-codewijziging (de
    futureprice-koopbevestiging van 17-6, een sell-knop) verandert de trade-set/het sell-resultaat
    ZONDER dat indicators of rules veranderen — dan miste de gate de drift en bleven de ratio's stil
    verschuiven. De handtekening is invariant onder de idempotente DELETE+INSERT-refire (timestamps/IDs
    tellen niet mee, alleen count + deterministische goed/slecht-classificatie), dus stabiel bij convergentie."""
    conn = brain()
    with conn.cursor() as c:
        c.execute("SELECT trading_symbol_id s, COUNT(*) n, MAX(datetime) mx FROM indicators GROUP BY trading_symbol_id ORDER BY s")
        ind = c.fetchall()
        c.execute("SELECT COUNT(*) n, MAX(updated_at) mx FROM rules WHERE active=1")
        rules = c.fetchone()
        lab = sell = fires = None
        if with_labels:
            c.execute("SELECT COUNT(*) n, MAX(updated_at) mx FROM coin_moment_labels WHERE decision='yes'")
            lab = c.fetchone()
        if with_sell:
            c.execute("SELECT COUNT(*) n, MAX(updated_at) mx FROM strategies")
            st = c.fetchone()
            c.execute("SELECT COUNT(*) n, MAX(updated_at) mx FROM coin_strategies")
            cst = c.fetchone()
            c.execute("SELECT COUNT(*) n, MAX(manual_set_at) mx FROM coin_moment_labels "
                      "WHERE source='manual' AND manual_set_at IS NOT NULL")
            mov = c.fetchone()
            c.execute("SELECT COUNT(*) n FROM coins")
            co = c.fetchone()
            sell = (f"#strat:{st['n']}:{st['mx']}#cstrat:{cst['n']}:{cst['mx']}"
                    f"#mov:{mov['n']}:{mov['mx']}#coins:{co['n']}")
        if with_fires:
            # count + goed/slecht-split + een CLASSIFICATIE-checksum (Fase 0). De ratio is g/s, dus count
            # en de g/s-split hertriggeren. NIET MAX(updated_at) (verandert door de DELETE+INSERT elke
            # refire → nooit meer skippen) en NIET Σprofit_loss (verschuift binnen een klasse zonder dat de
            # ratio wijzigt → nutteloze re-runs). De cls_xor sluit de resterende blindspot: een sell-override
            # of code-wijziging kan trades RUILEN (A goed→slecht, B slecht→goed) bij GELIJKE counts — dan
            # verandert bad_edge_conditions (die van de per-trade-classificatie afhangt) wél, maar count+g+s
            # niet. SUM(CRC32(coin+datetime+rule+cls)) is orde-onafhankelijk, vangt elke ruil/set-wijziging,
            # en is invariant onder magnitude-drift binnen een klasse (de cls-code blijft gelijk). NB: SUM,
            # niet BIT_XOR — CRC32 is lineair over XOR, dus een goed↔slecht-ruil tussen twee trades van
            # gelijke string-lengte cancelt onder XOR (door test_opt_lib gevangen); integer-SUM cancelt niet.
            c.execute("SELECT COUNT(*) n, COALESCE(SUM(profit_loss>=3),0) g, COALESCE(SUM(profit_loss<0),0) s, "
                      "COALESCE(SUM(CRC32(CONCAT(trading_symbol_id,'|',datetime,'|',rule,'|',"
                      "CASE WHEN profit_loss>=3 THEN 'g' WHEN profit_loss<0 THEN 'b' ELSE 'm' END))),0) cx "
                      "FROM coin_fires WHERE is_executed=1 AND profit_loss IS NOT NULL")
            fires = c.fetchone()
    conn.close()
    sig = "|".join(f"{r['s']}:{r['n']}:{r['mx']}" for r in ind) + f"#rules:{rules['n']}:{rules['mx']}"
    if lab:
        sig += f"#labels:{lab['n']}:{lab['mx']}"
    if sell:
        sig += sell
    if fires:
        sig += f"#fires:{fires['n']}:{fires['g']}:{fires['s']}:{fires['cx']}"
    return hashlib.md5(sig.encode()).hexdigest()


class Journal:
    """Collects log lines for one routine, then the runner persists them in order."""
    def __init__(self, key):
        self.key = key
        self.lines = []

    def add(self, message, level="info", rule=None, data=None):
        self.lines.append({"level": level, "rule_number": rule, "message": message, "data": data})
        # A3: ook live wegschrijven zodat lange routines een leesbare voortgang hebben.
        if level in ("change", "result", "finding", "error", "warn"):
            _live(message, set_key=self.key)


# --------------------------------------------------------------------------- routines
def routine_rule_optimization(j):
    """Daily rule-precision scan: ratios per rule + any NEW safe tightening (proposal, not applied)."""
    res = opt.run_optimization(rebuild=not NO_REBUILD)
    ratios, new = res["ratios"], res["new"]

    # PORTFOLIO-TOTAAL is het primaire succescijfer: één trade hoort onder single-position-dedup bij
    # precies één rule, dus de som over rules telt elke trade één keer (dedup-schoon). De per-rule
    # ratio is wél dedup-gevoelig — een aanscherping kan een slechte trade naar een andere rule
    # verschuiven i.p.v. 'm te elimineren — dus die staat erbij als detail, niet als kopcijfer.
    g_tot = sum(g for g, _ in ratios.values())
    s_tot = sum(s for _, s in ratios.values())
    tot = f"{g_tot/s_tot:.2f} ({g_tot}/{s_tot})" if s_tot else f"{g_tot}/0"
    parts = []
    for rule in sorted(ratios):
        g, s = ratios[rule]
        parts.append(f"r{rule} {g/s:.2f} ({g}/{s})" if s else f"r{rule} {g}/0")
    j.add(f"Portfolio-totaal (executed, dedup-schoon): {tot}", level="result",
          data={"total": [g_tot, s_tot], "ratios": {str(k): v for k, v in ratios.items()}})
    j.add("Per rule (dedup-gevoelig, detail): " + ", ".join(parts), level="result",
          data={"ratios": {str(k): v for k, v in ratios.items()}})

    if not new:
        j.add("Geen nieuwe veilige aanscherpingen — rules stabiel.", level="finding")
        return f"stabiel · totaal {tot} · {', '.join(parts)}"

    by_rule = {}
    for c in new:
        by_rule.setdefault(c["rule"], []).append(c)
    for rule in sorted(by_rule):
        cs = sorted(by_rule[rule], key=lambda x: -x["drop_insample"])
        top = cs[0]
        bnd = "≥" if top["bound"] == "lower" else "≤"
        j.add(f"rule {rule}: {len(cs)} nieuwe veilige kandida(a)t(en). Sterkste: "
              f"{top['indicator']}/{top['calc']}/lb{top['lookback']} {bnd} {round(top['threshold'], 5)} "
              f"— dropt ~{top['drop_insample']} slecht (in-sample), out-of-sample SAFE. "
              f"VOORSTEL — niet toegepast.", level="finding", rule=rule,
              data={"candidates": cs[:10]})
    return f"{len(new)} nieuwe kandidaten · totaal {tot} · {', '.join(parts)}"


def routine_auto_apply(j):
    """Apply the strongest new safe candidate per rule (engine-refire gated). Only acts with --apply;
    otherwise it stays propose-only so the on-screen 'Nu draaien' button never mutates the rules."""
    if not APPLY:
        j.add("Auto-apply: uit (geen --apply) — kandidaten alleen voorgesteld, niets toegepast.", level="info")
        return "apply uit"
    import auto_apply
    return auto_apply.apply_safe(lambda m, level="change", rule=None, data=None: j.add(m, level, rule, data))


def routine_auto_loosen(j):
    """RQ2: loosen an existing band to admit MORE good without new slecht (raises the numerator).
    Only acts with --apply; a loosening adds fires so it is gated by a full-history re-fire (0 new
    slecht both coins) + a portfolio confirm (good rises, slecht does not). Propose-only otherwise."""
    if not APPLY:
        j.add("Auto-loosen (rq2): uit (geen --apply) — draait alleen in de geplande run.", level="info")
        return "loosen uit"
    import auto_loosen
    return auto_loosen.loosen_safe(lambda m, level="change", rule=None, data=None: j.add(m, level, rule, data))


def routine_integrity(j):
    """DATA-INTEGRITEIT: run every read-only consistency check (integrity.py) and journal a PASS/FAIL
    line per check. The ONLY auto-fix is rebuilding the indicator_metrics cache for affected coins, and
    it runs solely with --fix; without it the routine reports but changes nothing. Never destructive."""
    import integrity
    conn = brain()
    try:
        results = integrity.run_all(conn=conn, quick=QUICK)
    finally:
        conn.close()

    lvl = {"ok": "result", "warn": "finding", "fail": "error"}
    fix_coins = set()
    n = {"ok": 0, "warn": 0, "fail": 0}
    for r in results:
        n[r.status] += 1
        j.add(f"[{r.status.upper()}] {r.title} — {r.summary}", level=lvl[r.status], data=r.as_dict())
        if r.fixable and r.status == "fail":
            fix_coins.update(r.fix_coins)

    if fix_coins and not FIX:
        j.add(f"Veilige auto-fix beschikbaar (cache herbouwen voor coin(s) {sorted(fix_coins)}) — "
              f"draai met --fix om toe te passen. Nu alleen gerapporteerd.", level="finding")
    elif fix_coins and FIX:
        import daily_optimization as opt
        for cid in sorted(fix_coins):
            opt.run("build_indicator_metrics.py", cid)        # idempotent per symbol; only the CACHE
        j.add(f"Cache herbouwd voor coin(s) {sorted(fix_coins)} (veilige auto-fix; geen data verwijderd).",
              level="change")
        conn = brain()
        try:
            ctx = integrity.Context(conn)
            for chk in (integrity.check_laag2_coverage, integrity.check_cache_freshness):
                rr = chk(ctx)
                j.add(f"na fix · [{rr.status.upper()}] {rr.title} — {rr.summary}",
                      level=lvl[rr.status], data=rr.as_dict())
                if rr.status == "ok":
                    n["fail"] = max(0, n["fail"] - 1)         # reflect the repaired check in the tally
        finally:
            conn.close()

    overall = integrity.worst(results) if not (fix_coins and FIX) else ("fail" if n["fail"] else ("warn" if n["warn"] else "ok"))
    return f"{overall.upper()} · {n['fail']} fail / {n['warn']} warn / {n['ok']} ok"


def routine_recall_triage(j):
    """RECALL-TRIAGE: when new promising labels come in, re-fill the worklist and PROPOSE bounded tweaks
    for the feature-missed groups. PROPOSE-ONLY — it never touches brain.rules; recall_loop only writes
    the worklist (status/tried). The human (or a later holdout-validated routine) decides on applying;
    recall tweaks are in-sample and overfit-risky, so they are never auto-applied."""
    import os
    here = opt.HERE
    py = sys.executable
    for script, args in (("recall_worklist.py", []), ("recall_loop.py", ["--write"])):
        r = subprocess.run([py, os.path.join(here, script), *args], cwd=here, capture_output=True, text=True)
        if r.returncode != 0:
            j.add(f"{script} faalde: {(r.stderr or r.stdout)[-400:]}", level="error")
            return f"FOUT in {script}"
    conn = brain()
    with conn.cursor() as c:
        c.execute("SELECT trading_symbol_id sym, COUNT(*) n, SUM(caught) caught, "
                  "SUM(status='proposed_catch') proposed, SUM(status='needs_new_rule') needs, "
                  "SUM(blocker='no_candidate') nocand FROM promising_recall_state GROUP BY sym ORDER BY sym")
        rows = c.fetchall()
        c.execute("SELECT home_rule, COUNT(*) n FROM promising_recall_state WHERE status='needs_new_rule' "
                  "GROUP BY home_rule ORDER BY n DESC")
        needs = c.fetchall()
    conn.close()
    parts = []
    for r in rows:
        rec = round(100 * r["caught"] / r["n"]) if r["n"] else 0
        parts.append(f"r{r['sym']} {rec}%")
        j.add(f"coin {r['sym']}: {int(r['n'])} promising-groepen · recall {rec}% · voorstel-vangbaar "
              f"{int(r['proposed'] or 0)} · needs_new_rule {int(r['needs'] or 0)} · no_candidate "
              f"{int(r['nocand'] or 0)} (engine-niveau, niet rule-fixbaar)", level="result",
              data={"groups": int(r["n"]), "caught": int(r["caught"] or 0)})
    if needs:
        j.add("needs_new_rule homet op: " + ", ".join(f"rule {r['home_rule']}: {int(r['n'])}" for r in needs)
              + " — kandidaten voor child-varianten (pas na holdout-validatie + meer data).", level="finding")
    return "recall-triage · " + ", ".join(parts)


def routine_buy_tuning(j):
    """BUY-TUNING: meet read-only de futureprice b_min-drempels (welke trades worden bevestigd of
    afgeblazen) en pas de veilige voorstellen toe. b_min zit op rule-niveau (geldt voor alle coins),
    dus de gate checkt het gecombineerde effect. Alleen muterend met --apply."""
    import buy_tuning
    import buy_apply
    report = buy_tuning.measure(write_json=True, verbose=False)
    safe = [p for p in report["proposals"] if p["verdict"] == "SAFE"]
    j.add(f"Buy-tuning gemeten: {len(safe)} veilige voorstellen · {len(report['proposals'])} doorgerekend.",
          level="result", data={"safe": len(safe), "report_path": report.get("report_path")})
    summary = buy_apply.apply_safe(lambda m, level="change", rule=None, data=None: j.add(m, level, rule, data),
                                    apply=APPLY, report=report)
    if not APPLY:
        j.add("Auto-apply: uit (geen --apply) — drempels alleen voorgesteld, niets gewijzigd.", level="info")
    return f"buy-tuning · {summary}"


def routine_sell_discovery(j):
    """SELL-DISCOVERY: zoek verbeteringen in rule-101 subrule-parameters (previous_value b_min,
    sell_negative_volume vc) en pas de beste veilige wijziging toe. Muteert brain.rules (niet
    coin_strategies) en refired alle coins. Alleen muterend met --apply."""
    import sell_discovery
    import sell_discovery_apply
    report = sell_discovery.measure(write_json=True, verbose=False)
    safe = [p for p in report["proposals"] if p["combined_verdict"] == "SAFE"]
    j.add(f"Sell-discovery gemeten: {len(safe)} veilige voorstellen · {len(report['proposals'])} doorgerekend.",
          level="result", data={"safe": len(safe), "report_path": report.get("report_path")})
    summary = sell_discovery_apply.apply_safe(
        lambda m, level="change", rule=None, data=None: j.add(m, level, rule, data),
        apply=APPLY, report=report)
    if not APPLY:
        j.add("Auto-apply: uit (geen --apply) — rule-101 wijzigingen alleen voorgesteld, niets gewijzigd.", level="info")
    return f"sell-discovery · {summary}"


def routine_sell_tuning(j):
    """SELL-TUNING: meet read-only per (munt, regel) of een andere instelknop betere verkopen geeft
    (holdout leidend, meetlat netto Σprofit) en PAS de veilige voorstellen toe achter de echte
    herreken-poort (Σprofit niet omlaag, verliezers niet omhoog). Schrijft altijd het rapport
    (out/opt/sell_tuning_<date>.json) voor het scherm. Alleen muterend met --apply; zonder --apply
    blijft het propose-only zodat de 'Nu draaien'-knop nooit live instellingen wijzigt."""
    import sell_tuning
    import sell_apply
    report = sell_tuning.measure(write_json=True, verbose=False)   # read-only meting + rapport voor het scherm
    safe = [p for p in report["proposals"] if p["verdict"] == "SAFE"]
    overfit = [p for p in report["proposals"] if p["verdict"] == "OVERFIT"]
    j.add(f"Sell-tuning gemeten: {len(safe)} veilige voorstellen · {len(overfit)} overfit afgekeurd "
          f"(train wint, holdout zakt) · {len(report['proposals'])} doorgerekend.", level="result",
          data={"safe": len(safe), "overfit": len(overfit), "report_path": report.get("report_path")})
    summary = sell_apply.apply_safe(lambda m, level="change", rule=None, data=None: j.add(m, level, rule, data),
                                    apply=APPLY, report=report)
    if not APPLY:
        j.add("Auto-apply: uit (geen --apply) — instellingen alleen voorgesteld, niets gewijzigd.", level="info")
    return f"sell-tuning · {summary}"


def routine_mexc_scan(j):
    """MEXC-SCAN: scan de hele MEXC-spotmarkt op volatiele, handelbare USDT-kandidaten; schrijf
    snapshot naar mexc_market_scan. Read-only ontdekking — muteert nooit rules/trades/bot_signals.
    Niet gegated: draait elke dag (de markt verandert dagelijks, los van engine-data)."""
    import mexc_scan
    res = mexc_scan.run(verbose=False)
    j.add(f"MEXC-scan: {res['fetched']} paren opgehaald, {res['written']} geschreven, "
          f"{res['kept']} kandidaten (mcap>10M & vol>100k & age>=7d). "
          f"mcap-dekking: {res['with_mcap']}/{res['fetched']}.",
          level="result", data=res)
    for c in res.get("top", [])[:5]:
        j.add(f"  {c['symbol']}: volat {c['volat_pct']:.1f}% | "
              f"24u-vol ${c['vol24h_usd']:,.0f} | "
              f"mcap ${c.get('mcap_usd', 0):,.0f} | "
              f"{c.get('age_days', '?')}d ({c.get('age_source', '?')})",
              level="finding")
    return f"mexc-scan | {res['fetched']} paren | {res['kept']} kandidaten"


def routine_coin_metrics(j):
    """COIN-METRICS: meet read-only per (munt, dag) de KANSRIJK-score (up_pct = % momenten met >=3%
    stijging binnen 60 min) + beweeglijkheid (vol_pct) + liquiditeit (n_ticks), en schrijf naar
    coin_daily_metrics. Geeft de actuele kansrijkheid-ranking (coins gesorteerd op laatste up_7d) in
    het journaal. Muteert NOOIT rules/trades — alleen de metrics-tabel. Niet gegated: draait elke dag,
    ook zonder nieuwe trades."""
    import coin_metrics
    res = coin_metrics.run(force=False, verbose=False)
    rank = " > ".join(f"{r['sym']} ({r['up_7d']:.0f}%)" for r in res["ranking"])
    j.add(f"Coin-metrics bijgewerkt: {res['days_added']} dagen voor {res['coins']} coin(s). "
          f"Kansrijkheid-ranking (up_7d): {rank}", level="result", data=res)
    for i, r in enumerate(res["ranking"], 1):
        j.add(f"  #{i} coin {r['sym']}: kansrijk={r['up_7d']:.1f}% · beweeglijkheid={r['vol_7d']:.2f} · "
              f"ticks={r['n_ticks']} ({r['date']})", level="finding")
    return f"coin-metrics · {res['days_added']} dagen · {res['coins']} coins"


# A SET is a named chain of routines with a shared goal. This set = eliminate existing bad trades
# from the rules (tighten existing rules now; outlier-split into new rules = 2b, coming). Append
# routines below; they run after each other in one journaled run, under this set's name.
SET_KEY = "rule-precision"
SET_NAME = "Rule-precisie — bestaande slechte trades elimineren"
REGISTRY = [
    ("rule-optimization", routine_rule_optimization),   # sweep all calcs×lookbacks → tighten existing rules
    ("auto-apply", routine_auto_apply),                 # apply the strongest safe tightening (engine-gated)
    ("auto-loosen", routine_auto_loosen),               # rq2: loosen a band to admit more good (gated)
    # ("outlier-split", routine_outlier_split),          # 2b: pull an outlier good trade into a new rule
]

# A SECOND set: data-integriteit. Periodic read-only health checks of the brain data. Runs on its own
# schedule, NOT gated by the data-changed fingerprint (a health check must run even when nothing
# changed), and it yields to the rule-precision chain (concurrency guard in main) so a --fix cache
# rebuild never races the hourly rule run (we saw a 1412 + snapshot-drift when they overlapped).
INTEGRITY_SET_KEY = "data-integriteit"
INTEGRITY_SET_NAME = "Data-integriteit — consistentie & veilige cache-fix"
REGISTRY_INTEGRITY = [
    ("data-integriteit", routine_integrity),
]

# A THIRD set: recall-triage. Fires when new promising LABELS come in (the fingerprint includes the
# ok-labels for this set) → re-fill the worklist + PROPOSE bounded tweaks. Propose-only (never touches
# brain.rules); the human/holdout decides on applying. Different objective + trigger than rule-precision.
RECALL_SET_KEY = "recall-triage"
RECALL_SET_NAME = "Recall-triage — promising-groepen vangen (voorstellen)"
REGISTRY_RECALL = [
    ("recall-triage", routine_recall_triage),
]

# A FOURTH set: sell-tuning. Fires when new trades, a manual trade-override (manual_set_at), a knob-edit
# (strategies/coin_strategies) or a new coin/rule changes the input (the fingerprint includes all four).
# Measures per (coin,rule) and — in the scheduled --apply run — auto-applies the safe instelknoppen
# behind the real re-fire gate. The on-screen 'Nu draaien' button runs without --apply = propose-only.
SELL_SET_KEY = "sell-tuning"
SELL_SET_NAME = "Sell-tuning — per-munt instelknoppen afstellen"
REGISTRY_SELL = [
    ("sell-tuning", routine_sell_tuning),
]

# A FIFTH set: buy-tuning. Fires when rules (futureprice b_min) or trade-data changes. Measures per
# rule (b_min is rule-niveau, geldt voor alle coins) en past de veilige voorstellen toe achter de
# gecombineerde herreken-poort. Uses the sell fingerprint (includes rules + trades).
BUY_SET_KEY = "buy-tuning"
BUY_SET_NAME = "Buy-tuning — futureprice koop-drempels afstellen"
REGISTRY_BUY = [
    ("buy-tuning", routine_buy_tuning),
]

# A SIXTH set: sell-discovery. Zoekt verbeteringen in rule-101 subrule-parameters (b_min, vc) en past
# de beste veilige wijziging toe (muteert brain.rules, refired alle coins). Uses the sell fingerprint
# (includes rules + trades + overrides) — a rule-101 change or new trades re-trigger the discovery.
SELL_DISC_SET_KEY = "sell-discovery"
SELL_DISC_SET_NAME = "Sell-discovery — rule-101 structuur-verbetering"
REGISTRY_SELL_DISC = [
    ("sell-discovery", routine_sell_discovery),
]

# A SEVENTH set: coin-metrics. Meet per (munt,dag) de kansrijkheid (up_pct) + beweeglijkheid + liquiditeit
# en houdt de cross-coin ranking bij. Read-only meet-fundament voor coin-rotatie. NIET gegated — moet elke
# dag draaien, ook zonder nieuwe trades (de prijsdata verandert dagelijks, los van trades).
MEXC_SET_KEY = "mexc-scan"
MEXC_SET_NAME = "MEXC-markt — volatiele kandidaten (dagelijks)"
REGISTRY_MEXC = [
    ("mexc-scan", routine_mexc_scan),
]

VOL_SET_KEY = "coin-metrics"
VOL_SET_NAME = "Coin-metrics — kansrijkheid per munt meten + ranken"
REGISTRY_VOL = [
    ("coin-metrics", routine_coin_metrics),
]

SETS = {
    SET_KEY: (SET_NAME, REGISTRY, True),                 # gated by the data-changed fingerprint
    INTEGRITY_SET_KEY: (INTEGRITY_SET_NAME, REGISTRY_INTEGRITY, False),
    RECALL_SET_KEY: (RECALL_SET_NAME, REGISTRY_RECALL, True),   # gated; fingerprint includes ok-labels
    SELL_SET_KEY: (SELL_SET_NAME, REGISTRY_SELL, True),        # gated; fingerprint includes sell knobs + overrides
    BUY_SET_KEY: (BUY_SET_NAME, REGISTRY_BUY, True),          # gated; fingerprint includes rules + trades
    SELL_DISC_SET_KEY: (SELL_DISC_SET_NAME, REGISTRY_SELL_DISC, True),  # gated; fingerprint includes rules + trades
    VOL_SET_KEY: (VOL_SET_NAME, REGISTRY_VOL, False),         # NIET gegated — draait elke dag
    MEXC_SET_KEY: (MEXC_SET_NAME, REGISTRY_MEXC, False),     # NIET gegated — draait elke dag
}


# --------------------------------------------------------------------------- runner
def _state(conn, key):
    with conn.cursor() as c:
        c.execute("SELECT fingerprint FROM routine_state WHERE set_key=%s", (key,))
        return c.fetchone()


def _save_state(conn, key, fp, now, ran, outcome):
    with conn.cursor() as c:
        c.execute(
            "INSERT INTO routine_state (set_key, fingerprint, last_checked_at, last_ran_at, last_outcome, "
            "created_at, updated_at) VALUES (%s,%s,%s,%s,%s,%s,%s) ON DUPLICATE KEY UPDATE "
            "fingerprint=VALUES(fingerprint), last_checked_at=VALUES(last_checked_at), "
            "last_ran_at=COALESCE(VALUES(last_ran_at), routine_state.last_ran_at), "
            "last_outcome=VALUES(last_outcome), updated_at=VALUES(updated_at)",
            (key, fp, now, ran, outcome, now, now))
    conn.commit()


def _active_recent_run(conn, exclude_id=None, minutes=30):
    """An OTHER routine_runs row still 'running' and started within `minutes` (i.e. genuinely active,
    not a long-stale abandoned run). Used by the integrity set to YIELD to the rule-precision chain so
    a --fix cache rebuild never races the hourly rule run."""
    with conn.cursor() as c:
        c.execute("SELECT id, set_key, started_at FROM routine_runs WHERE status='running' "
                  "AND started_at >= (NOW() - INTERVAL %s MINUTE) AND (%s IS NULL OR id <> %s) "
                  "ORDER BY started_at LIMIT 1", (minutes, exclude_id, exclude_id))
        return c.fetchone()


def main():
    now = datetime.datetime.now()
    run_date = RUN_DATE or now.date().isoformat()
    conn = brain()

    if SET_ARG not in SETS:
        conn.close()
        sys.exit(f"onbekende set '{SET_ARG}' — kies uit: {', '.join(SETS)}")
    set_key = SET_ARG
    set_name, registry, gated = SETS[set_key]

    fp = input_fingerprint(with_labels=(set_key == RECALL_SET_KEY),   # recall fires on new ok-labels too
                           with_sell=(set_key in (SELL_SET_KEY, BUY_SET_KEY, SELL_DISC_SET_KEY)),  # sell/buy/discovery fire on knob/rule/trade changes
                           # rule-precision EN sell/buy/discovery meten direct op de trade-P&L → coin_fires-drift
                           # moet hen óók hertriggeren. Anders mist de gate een upstream trade-set-wijziging die
                           # niet via data/regel/knop kwam (code-deploy + handmatige refire, bv. de futureprice-fix):
                           # vingerafdruk blijft gelijk → set overgeslagen → rapport-JSON niet ververst (stale scherm).
                           with_fires=(set_key in (SET_KEY, SELL_SET_KEY, BUY_SET_KEY, SELL_DISC_SET_KEY)))
    prev = _state(conn, set_key)            # huidige opgeslagen fingerprint (nodig voor de retry-na-fail logica)
    if gated:
        # DATA-CHANGED GATE: skip the (expensive) chain if nothing that affects the outcome changed.
        if prev and prev["fingerprint"] == fp and not FORCE:
            _save_state(conn, set_key, fp, now, None, "geen wijziging — overgeslagen")
            conn.close()
            print(f"[{run_date}] geen data- of rule-wijziging sinds laatste run — overgeslagen (gebruik --force om toch te draaien).")
            return
    else:
        # CONCURRENCY GUARD (integrity): never run alongside ANY other active routine — we saw a 1412 +
        # snapshot-drift when the integrity checks/cache-fix overlapped the hourly rule chain.
        active = _active_recent_run(conn)
        if active and not FORCE:
            _save_state(conn, set_key, fp, now, None, f"overgeslagen — run #{active['id']} ({active['set_key']}) actief")
            conn.close()
            print(f"[{run_date}] andere routine actief (run #{active['id']}, set {active['set_key']}) — "
                  f"data-integriteit overgeslagen (gebruik --force om toch te draaien).")
            return

    # MUTEX: één run per set tegelijk. MySQL user-level lock is per-connectie → atomair, geen TOCTOU.
    # conn.close() in de finally hieronder releaest de lock automatisch (MySQL-garantie).
    lock_name = f"routine:{set_key}"
    with conn.cursor() as c:
        c.execute("SELECT GET_LOCK(%s, 0) got", (lock_name,))
        lock_acquired = bool(c.fetchone()["got"])
    if not lock_acquired:
        outcome = f"overgeslagen — andere {set_key}-run actief (lock)"
        # Bewaar de VORIGE fingerprint (de actieve run schrijft zo zijn eigen eind-staat). Is er nog
        # geen state (allereerste run die de lock-race verliest), schrijf dan de retry-sentinel i.p.v.
        # de huidige fp — anders zou de volgende run de nog-nooit-gedraaide data als "verwerkt" zien
        # en 'm voorgoed overslaan tot de data verandert.
        _save_state(conn, set_key, prev["fingerprint"] if prev else "retry-after-fail", now, None, outcome)
        conn.close()
        print(f"[{run_date}] andere {set_key}-run actief (lock) — overgeslagen.")
        return

    with conn.cursor() as c:
        c.execute("INSERT INTO routine_runs (set_key, set_name, run_date, started_at, status, `trigger`, "
                  "created_at, updated_at) VALUES (%s,%s,%s,%s,'running',%s,%s,%s)",
                  (set_key, set_name, run_date, now, TRIGGER, now, now))
        run_id = c.lastrowid
    conn.commit()

    _live(f"=== START set '{set_key}' (run #{run_id}, trigger={TRIGGER}) ===")
    _live(f"progress-log: {_PROGRESS_PATH}")
    seq = 0
    summaries = []
    status = "success"
    try:
        for key, fn in registry:
            j = Journal(key)
            _live(f"-> routine '{key}' gestart", set_key=set_key)
            t0 = datetime.datetime.now()
            try:
                summary = fn(j)
                _live(f"<- routine '{key}' klaar in {(datetime.datetime.now()-t0).total_seconds():.0f}s: {summary}",
                      set_key=set_key)
            except (Exception, SystemExit) as e:  # one routine failing must not lose the journal.
                # SystemExit (geen Exception-subclass!) komt uit de refire-helpers en opt.run; zonder
                # deze vangst ontsnapt die crash en wordt de run alsnog als 'success' gejournald.
                j.add(f"FOUT in routine {key}: {e}", level="error")
                _live(f"!! routine '{key}' GEFAALD: {e}", set_key=set_key)
                summary = f"FOUT: {e}"
                status = "failed"
            with conn.cursor() as c:
                for line in j.lines:
                    seq += 1
                    c.execute(
                        "INSERT INTO routine_run_log (routine_run_id, routine_key, seq, level, "
                        "rule_number, message, data, created_at) VALUES (%s,%s,%s,%s,%s,%s,%s,%s)",
                        (run_id, key, seq, line["level"], line["rule_number"], line["message"],
                         json.dumps(line["data"], default=str) if line["data"] is not None else None,
                         datetime.datetime.now()))
            conn.commit()
            summaries.append(f"{key}: {summary}")
    finally:
        end = datetime.datetime.now()
        with conn.cursor() as c:
            c.execute("UPDATE routine_runs SET finished_at=%s, status=%s, n_routines=%s, summary=%s, "
                      "updated_at=%s WHERE id=%s",
                      (end, status, len(registry), " | ".join(summaries), end, run_id))
        conn.commit()
        # store the START fingerprint of this executed run: if a routine changed the rules, the next
        # run's fingerprint differs (→ re-runs, compounding); if nothing changed, it matches (→ skips).
        # ALLEEN bij succes: een gefaalde run mag de fingerprint NIET als 'verwerkt' opslaan, anders
        # skipt de volgende run (zelfde data → zelfde fp) de retry. Bij falen schrijven we een sentinel
        # die nooit gelijk is aan een echte (md5-)fingerprint → de gate ziet een verschil → retry.
        saved_fp = fp if status == "success" else "retry-after-fail"
        _save_state(conn, set_key, saved_fp, end, end, " | ".join(summaries)[:160])
        conn.close()

    print(f"routine-run #{run_id} [{status}] {run_date}")
    for s in summaries:
        print("  " + s)


if __name__ == "__main__":
    main()
