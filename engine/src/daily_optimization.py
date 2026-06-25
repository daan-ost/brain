#!/usr/bin/env python3
"""
DAILY rule-optimisation routine — one command, deterministic, read-only on the rules (proposes,
never applies). Meant to run every day (launchd / cron). Pipeline, in the order the cache demands:

  1. persist_to_brain   (re-fire the rules over the FULL history of both coins — picks up any newly
                          imported indicators / promising periods; idempotent if nothing changed)
  2. build_indicator_metrics  (rebuild the calc cache to match the current trades + periods)
  3. rq1_tighten all    (find SAFE tightenings, scale-guard active)
  4. compare the SAFE candidates against the already-applied tuned subrules
       -> report only what is NEW and actionable; otherwise "stable, niets nieuws".

Writes a dated report docs/optimization/daily/YYYY-MM-DD.md and the machine output under
engine/out/opt/. Applies NOTHING — a human (or a Claude review step) decides whether to adopt.

Usage: daily_optimization.py [--no-rebuild] [--date YYYY-MM-DD]
  --no-rebuild : skip steps 1-2 (analysis-only, fast) — use when the data did not change.
  --date       : stamp the report with this date (launchd passes the real date; default today).
"""
import json
import os
import subprocess
import sys

import opt_lib as o
from db import brain

HERE = o.HERE
OUT = os.path.join(HERE, "..", "out", "opt")
REPORT_DIR = os.path.join(HERE, "..", "..", "docs", "optimization", "daily")
from coins import optimize_coin_ids
COINS = optimize_coin_ids()                   # snel pad: alleen DOGEAI+NOS; env OPTIMIZE_COINS overruled
PY = sys.executable                       # the venv python running this script
NO_REBUILD = "--no-rebuild" in sys.argv
RUN_DATE = (sys.argv[sys.argv.index("--date") + 1] if "--date" in sys.argv else None)


def run(script, *args):
    """Run an engine script with the same interpreter; raise on failure (the daily log shows why)."""
    cmd = [PY, os.path.join(HERE, script), *map(str, args)]
    r = subprocess.run(cmd, cwd=HERE, capture_output=True, text=True)
    if r.returncode != 0:
        raise SystemExit(f"FAILED: {' '.join(cmd)}\n{r.stdout[-2000:]}\n{r.stderr[-2000:]}")
    return r.stdout


def today():
    if RUN_DATE:
        return RUN_DATE
    # Date.now() is unavailable here only inside the workflow sandbox; this is a normal script.
    import datetime
    return datetime.date.today().isoformat()


def current_ratios():
    conn = brain()
    with conn.cursor() as c:
        # Classify executed trades on PROFIT_LOSS (realized) to mirror CoinFire::klasseKey() + the UI.
        c.execute("SELECT rule, SUM(profit_loss>=3) goed, SUM(profit_loss<0) slecht "
                  "FROM coin_fires WHERE is_executed=1 AND profit_loss IS NOT NULL GROUP BY rule ORDER BY rule")
        rows = c.fetchall()
    conn.close()
    return {r["rule"]: (int(r["goed"]), int(r["slecht"])) for r in rows}


def applied_subrules():
    """Set of (rule, indicator, calc, lookback) already in the live rules (any source)."""
    conn = brain()
    with conn.cursor() as c:
        c.execute("SELECT rule_number, indicator, subrulename, def1_value FROM rules WHERE active=1")
        rows = c.fetchall()
    conn.close()
    return {(r["rule_number"], r["indicator"], r["subrulename"],
             int(r["def1_value"]) if r["def1_value"] is not None else None) for r in rows}


def new_safe_candidates():
    """SAFE rq1 singles not already applied and not scale-unsafe — the actionable list."""
    with open(os.path.join(OUT, "rq1_tighten_all.json")) as f:
        report = json.load(f)
    have = applied_subrules()
    out = []
    for rule, blk in report.items():
        rule = int(rule)
        for c in blk.get("singles", []):
            if c["verdict"] != "SAFE":
                continue
            key = (rule, c["indicator"], c["calc"], int(c["lookback"]))
            if key in have:
                continue
            out.append(c)
    return out


def run_optimization(rebuild=True):
    """Run the pipeline and return structured results — the entry point for routines.py.
    {ratios: {rule: (good, bad)}, new: [candidate dicts], rebuilt: bool}. Applies nothing."""
    if rebuild:
        # A4: parallel coin-refires. persist_to_brain.py is per coin onafhankelijk (verschillende
        # trading_symbol_id → geen lock-conflict op coin_fires/coin_periods); MySQL handelt het parallelle
        # schrijven prima. SSL is uit (A2), dus de connecties zijn stabiel. Snelheidswinst is N-voudig
        # (was sequentieel ~5-15 min/coin → nu ~ langste enkele refire). A1's fingerprint-skip blijft
        # actief: een coin die niets is veranderd refired binnen seconden.
        from concurrent.futures import ThreadPoolExecutor, as_completed
        workers = min(len(COINS), 4)
        errors = []
        with ThreadPoolExecutor(max_workers=workers) as pool:
            futs = {pool.submit(run, "persist_to_brain.py", c): c for c in COINS}
            for f in as_completed(futs):
                try:
                    f.result()
                except SystemExit as e:
                    errors.append(f"coin {futs[f]}: {e}")
        if errors:
            raise SystemExit("FAILED parallel refire:\n" + "\n".join(errors))
        run("build_indicator_metrics.py")
    run("rq1_tighten.py", "all", 5)
    return {"ratios": current_ratios(), "new": new_safe_candidates(), "rebuilt": rebuild}


def main():
    os.makedirs(REPORT_DIR, exist_ok=True)
    date = today()
    log = [f"# Dagelijkse rule-optimalisatie — {date}", ""]
    log.append("**Data ververst:** re-fire + cache herbouwd over beide coins." if not NO_REBUILD
               else "**Analyse-only** (`--no-rebuild`): data niet ververst.")

    res = run_optimization(rebuild=not NO_REBUILD)
    ratios, new = res["ratios"], res["new"]

    log += ["", "## Ratio per rule (executed, profit_loss-klasse: goed>=3% / slecht<0%)", "",
            "Het **portfolio-totaal** is het primaire cijfer (dedup-schoon: elke trade hoort onder "
            "single-position-dedup bij één rule). De per-rule ratio is dedup-gevoelig — een aanscherping "
            "kan een slechte trade naar een andere rule verschuiven — dus detail, geen kopcijfer.", "",
            "| rule | goed | slecht | ratio |", "|---|---|---|---|"]
    g_tot = sum(g for g, _ in ratios.values())
    s_tot = sum(s for _, s in ratios.values())
    log.append(f"| **totaal** | **{g_tot}** | **{s_tot}** | **{g_tot/s_tot:.2f}** |" if s_tot
               else f"| **totaal** | **{g_tot}** | **0** | **—** |")
    for rule in sorted(ratios):
        g, s = ratios[rule]
        log.append(f"| {rule} | {g} | {s} | {g/s:.2f} |" if s else f"| {rule} | {g} | 0 | — |")

    log += ["", "## Nieuwe veilige aanscherpingen (nog niet toegepast)", ""]
    if not new:
        log.append("Geen. De rules zijn stabiel — geen nieuwe SAFE-kandidaat sinds de laatste toepassing.")
    else:
        log.append("Per kandidaat: out-of-sample SAFE op alle datasplits, drempel op de slechte rand. "
                   "**Niet automatisch toegepast** — review eerst (engine-refire) voordat je 'm adopteert.")
        log += ["", "| rule | ADD subrule | drempel | drop in-sample |", "|---|---|---|---|"]
        for c in sorted(new, key=lambda x: (x["rule"], -x["drop_insample"])):
            bnd = "≥" if c["bound"] == "lower" else "≤"
            log.append(f"| {c['rule']} | `{c['indicator']}/{c['calc']}/lb{c['lookback']}` "
                       f"| {bnd} {round(c['threshold'], 5)} | {c['drop_insample']} |")
        log.append("")
        log.append("> Adopteren? Zet 'm in `add_tuned_subrules.py`, draai dat + `persist_to_brain.py` "
                   "(echte engine-refire), en controleer 0 goede trades verloren. Pas dán committen.")

    report_path = os.path.join(REPORT_DIR, f"{date}.md")
    with open(report_path, "w") as f:
        f.write("\n".join(log) + "\n")
    # console summary (this is what the launchd log shows)
    print(f"[{date}] ratios: " + ", ".join(f"r{r}={g}/{s}" for r, (g, s) in sorted(ratios.items())))
    print(f"[{date}] nieuwe veilige aanscherpingen: {len(new)}")
    print(f"[{date}] rapport: {os.path.relpath(report_path, os.path.join(HERE, '..', '..'))}")


if __name__ == "__main__":
    main()
