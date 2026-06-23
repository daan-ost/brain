#!/usr/bin/env python3
"""
AUTO-APPLY the strongest NEW safe rq1 candidate per rule — but ONLY behind the real engine gate:
add the subrule, re-fire the rule over the FULL history of both coins, and KEEP it only if
(a) total executed GOOD trades are preserved (0 opportunity lost) AND (b) total executed SLECHT
strictly drops. Otherwise revert it. A tightening can only remove fires, so it never creates a new
bad trade; the only risk is dropping a good one, which the gate catches. Cumulative: each kept
subrule becomes the new baseline for the next.

Applied subrules get source='auto-applied' (distinct from manual 'tuned-precision'); both seed_rules
and add_tuned_subrules preserve them. Records every batch in rules_history. Rebuilds the cache at the
end if anything changed. Drives via an emit(message, level, rule, data) callback so the routine
journals each line.

Usage (standalone): auto_apply.py        — apply now, print the journal.
"""
import os
import subprocess
import sys

from db import brain
import daily_optimization as opt
import opt_lib as o
import rules_history

COINS = opt.COINS
PY = sys.executable
HERE = opt.HERE
# principle 1: add at most ONE subrule per rule per run (gradual, reviewable) — the strongest one.

PERM_ALPHA = 0.05         # family-wise significance the toeval-toets must clear (Šidák-corrected)
PERM_MAX = 4000           # cap on shuffles per candidate (resolution vs cost)


def _toeval_filter(strongest, n_hyp, emit):
    """Keep only candidates whose bad-drop survives the toeval-toets after a Šidák correction over the
    whole SAFE family rq1 produced (n_hyp). Family-size-aware: n_perm scales so the resolution floor
    beats the corrected threshold; if even PERM_MAX shuffles can't resolve it (too many candidates for
    2 coins), NOTHING certifies — that is the honest signal that the lever is data-bound (more coins),
    not a filter to relax. emit(message, level, rule, data)."""
    p_req = o.required_raw_p(n_hyp, PERM_ALPHA)
    n_perm = PERM_MAX if p_req <= 0 else min(PERM_MAX, max(400, int(4.0 / p_req)))
    floor = 1.0 / (n_perm + 1)
    long = o.load_long()
    kept = {}
    for rule in sorted(strongest):
        c = strongest[rule]
        label = f"{c['indicator']}/{c['calc']}/lb{c['lookback']}"
        if floor > p_req:
            emit(f"rule {rule}: toeval-toets kan {label} niet certificeren — {n_hyp} SAFE-kandidaten op "
                 f"2 munten vragen p<{p_req:.5f}, maar {n_perm} schudbeurten halen maar p>={floor:.5f}. "
                 f"Niet toegepast (meer munten/data nodig, geen ruis live).", "info", rule, {"candidate": c})
            continue
        pr = o.permutation_pvalue(long, rule, c["indicator"], int(c["lookback"]), c["calc"], c["bound"],
                                  n_perm=n_perm)
        if pr is None:
            emit(f"rule {rule}: te weinig apart-gehouden testdata voor de toeval-toets ({label}) — "
                 f"niet toegepast (conservatief).", "info", rule, {"candidate": c})
            continue
        p_corr = o.sidak(pr["p"], n_hyp)
        c["perm_p"], c["perm_p_corr"], c["perm_n_hyp"] = pr["p"], p_corr, n_hyp
        if p_corr < PERM_ALPHA:
            emit(f"rule {rule}: toeval-toets DOORSTAAN ({label}) — p={pr['p']:.4f} "
                 f"(Šidák×{n_hyp}={p_corr:.3f}<0.05), slecht-drop {pr['obs_drop']} op de testperiode "
                 f"({pr['n_te_bad']} slecht/{pr['n_te_good']} goed).", "info", rule,
                 {"candidate": c, "p": pr["p"], "p_corr": p_corr, "n_hyp": n_hyp})
            kept[rule] = c
        else:
            emit(f"rule {rule}: kandidaat {label} AFGEWEZEN door toeval-toets — p={pr['p']:.4f} "
                 f"(Šidák×{n_hyp}={p_corr:.3f}>=0.05): de slecht-scheiding is niet te onderscheiden van "
                 f"toeval. Niet toegepast.", "info", rule,
                 {"candidate": c, "p": pr["p"], "p_corr": p_corr, "n_hyp": n_hyp})
    return kept


def _refire():
    for c in COINS:
        r = subprocess.run([PY, os.path.join(HERE, "persist_to_brain.py"), str(c)],
                           cwd=HERE, capture_output=True, text=True)
        if r.returncode != 0:
            raise SystemExit(f"persist {c} faalde:\n{r.stderr[-1500:]}")


def _totals():
    """(total executed good, total executed slecht, {rule: (good, slecht)}) over both coins,
    classified on PROFIT_LOSS (realized) — mirrors CoinFire::klasseKey() and what the UI shows.
    Thresholds: goed pl>=3, slecht pl<0 (UI mirror)."""
    conn = brain()
    with conn.cursor() as c:
        c.execute("SELECT rule, SUM(profit_loss>=3) g, SUM(profit_loss<0) s "
                  "FROM coin_fires WHERE is_executed=1 AND profit_loss IS NOT NULL GROUP BY rule")
        rows = c.fetchall()
    conn.close()
    per = {r["rule"]: (int(r["g"]), int(r["s"])) for r in rows}
    return sum(g for g, _ in per.values()), sum(s for _, s in per.values()), per


def _insert(cand):
    """Insert the candidate subrule (source='auto-applied'); return its id."""
    lower = cand["bound"] == "lower"
    b_min = cand["threshold"] if lower else None
    b_max = None if lower else cand["threshold"]
    conn = brain()
    with conn.cursor() as c:
        c.execute("SELECT COALESCE(MAX(sort),0)+1 s FROM rules WHERE rule_number=%s", (cand["rule"],))
        sort = c.fetchone()["s"]
        c.execute("INSERT INTO rules (rule_number, sort, indicator, subrulename, def1_value, b_min, "
                  "b_max, active, source, created_at, updated_at) "
                  "VALUES (%s,%s,%s,%s,%s,%s,%s,1,'auto-applied',NOW(),NOW())",
                  (cand["rule"], sort, cand["indicator"], cand["calc"], cand["lookback"], b_min, b_max))
        sid = c.lastrowid
    conn.commit()
    conn.close()
    return sid


def _delete(sid):
    conn = brain()
    with conn.cursor() as c:
        c.execute("DELETE FROM rules WHERE id=%s", (sid,))
    conn.commit()
    conn.close()


def apply_safe(emit):
    """Try to apply the strongest new safe candidate per rule. emit(message, level, rule, data)."""
    cands = opt.new_safe_candidates()
    if not cands:
        emit("Geen nieuwe veilige kandidaten om toe te passen.", "info", None, None)
        return "niets toe te passen"

    strongest = {}
    for c in cands:
        if c["rule"] not in strongest or c["drop_insample"] > strongest[c["rule"]]["drop_insample"]:
            strongest[c["rule"]] = c

    # TOEVAL-TOETS pre-filter: rq1's SAFE verdict only proves GOOD survives out-of-sample; it never
    # tests whether the bad-drop itself beats chance. Sweeping all (indicator×calc×lookback) and
    # picking winners is exactly "scan tovert edges uit ruis" — so before we mutate any rule, each
    # strongest candidate must pass a permutation test, Šidák-corrected over the whole SAFE family.
    strongest = _toeval_filter(strongest, len(cands), emit)
    if not strongest:
        emit("Geen kandidaat doorstond de toeval-toets — niets toegepast.", "info", None, None)
        return "0 toegepast (toeval-toets)"

    base_good, base_slecht, base_per = _totals()
    applied, rejected = [], 0
    for rule in sorted(strongest):
        cand = strongest[rule]
        bnd = "≥" if cand["bound"] == "lower" else "≤"
        label = f"{cand['indicator']}/{cand['calc']}/lb{cand['lookback']} {bnd} {round(cand['threshold'], 5)}"
        sid = _insert(cand)
        try:
            _refire()
        except SystemExit as _e:
            # Subrule is in DB maar refire mislukt — verwijder subrule en probeer te refire
            emit(f"KRITIEK: refire mislukte na insert ({_e}) — subrule terugdraaien.", "error", rule,
                 {"candidate": cand})
            _delete(sid)
            try:
                _refire()
            except SystemExit as _e2:
                # De staat is nu niet meer te vertrouwen: subrule weg uit de rules-tabel maar de
                # coin_fires-refire is mislukt. Doorgaan zou volgende kandidaten tegen een verouderde
                # nullijn over een kapotte trade-set meten en subrules op die staat stapelen. Afbreken.
                emit(f"KRITIEK: ook revert-refire mislukte ({_e2}). Subrule verwijderd uit DB maar "
                     f"coin_fires inconsistent — run AFGEBROKEN (geen verdere kandidaten op een "
                     f"onbetrouwbare staat). Handmatig herstel: persist_to_brain.py.",
                     "error", rule, {"candidate": cand})
                raise SystemExit("auto-apply afgebroken: revert-refire mislukt na insert; coin_fires inconsistent")
            rejected += 1
            continue

        now_good, now_slecht, now_per = _totals()
        slecht_before = base_per.get(rule, (0, 0))[1]
        slecht_after = now_per.get(rule, (0, 0))[1]
        # GATE: keep only if NO good lost overall AND TOTAL slecht strictly drops (a tightening can
        # reshuffle bad onto another rule via the single-position dedup; the total is what counts).
        if now_good >= base_good and now_slecht < base_slecht:
            emit(f"rule {rule}: subrule toegevoegd ({label}). Slecht {slecht_before}→{slecht_after} "
                 f"op deze rule; totaal slecht {base_slecht}→{now_slecht}, goede trades behouden "
                 f"({base_good}→{now_good}).", "change", rule,
                 {"candidate": cand, "good_total": [base_good, now_good], "slecht_total": [base_slecht, now_slecht]})
            base_good, base_slecht, base_per = now_good, now_slecht, now_per   # cumulative baseline
            applied.append((rule, cand, slecht_before, slecht_after))
        else:
            _delete(sid)
            try:
                _refire()
            except SystemExit as _e:
                # Afwijzing-revert mislukt → coin_fires onbetrouwbaar. Niet doorgaan met de lus
                # (zie de insert-revert-tak hierboven): afbreken zodat geen subrules op een kapotte
                # staat worden gestapeld.
                emit(f"KRITIEK: revert-refire mislukte ({_e}). Subrule verwijderd uit DB maar "
                     f"coin_fires inconsistent — run AFGEBROKEN. Handmatig: persist_to_brain.py.",
                     "error", rule, {"candidate": cand})
                raise SystemExit("auto-apply afgebroken: revert-refire mislukt na afwijzing; coin_fires inconsistent")
            reason = (f"zou {base_good - now_good} goede trade(s) verliezen" if now_good < base_good
                      else "totaal slechte trades daalt niet (dedup verschuift evenveel bad)")
            emit(f"rule {rule}: kandidaat {label} AFGEWEZEN door engine-refire — {reason}. Teruggedraaid.",
                 "info", rule, {"candidate": cand})
            rejected += 1

    if applied:
        subprocess.run([PY, os.path.join(HERE, "build_indicator_metrics.py")], cwd=HERE,
                       capture_output=True, text=True)   # keep the cache current
        toel = {rule: f"Auto-toegepast: {c['indicator']}/{c['calc']}/lb{c['lookback']} "
                      f"({bnd_for(c)} {round(c['threshold'], 5)}), slecht {sb}→{sa}, 0 goede verloren."
                for rule, c, sb, sa in applied}
        rules_history.record(toel, source="auto-applied", author="routine")

    return f"{len(applied)} toegepast, {rejected} afgewezen"


def bnd_for(c):
    return "≥" if c["bound"] == "lower" else "≤"


if __name__ == "__main__":
    apply_safe(lambda m, level="change", rule=None, data=None: print(f"[{level}] {m}"))
