# TURBO (id 32) onboarding — testmunt 5e coin (2026-06-28)

**Status:** Voltooid + geverifieerd. Eerste munt van de 4→12-batch
([[onboarding-batch-en-bouwvolgorde-2026-06-27]]). Doel: verifiëren dat de hele keten (incl. de net
gebouwde Epic H regime-gate + Epic J loosen-cache) werkt met een nieuwe munt.

## Belangrijkste beslissing: min_volume voor een munt zónder legacy rules 20-23

TURBO gebruikte in legacy alleen rules 5/11/12/13/28/29/101 — **geen 20-23**. Er was dus geen legacy
min_volume te kopiëren voor onze rule-set.

**Geverifieerd (was eerst fout aangenomen):**
- `min_volume` is een **per-munt ijk-constante**, de normalisator in `relvol = volumeud / min_volume`
  (`volume.py:72`). GEEN optimizer-output — alleen `seed_rules.py` schrijft het, geen enkele routine.
- `min_volume=0` **crasht** de engine (`volume.py:72` deelt zonder nul-guard).
- Vereist vóór `compute_volume_found.py` (zonder → `brain_volume_found=0` → geen kandidaat-ticks → geen
  trades).

**Heuristiek (gemeten over de 4 bestaande munten):** min_volume zit op het **~90e percentiel** van de
volumeud-verdeling; 7,3–10,2% van de ticks wordt kandidaat (`brain_volume_found=1`).

**Gekozen:** p90 van TURBO's volumeud = **3.951.626** voor rules 20-23.
**Empirisch bevestigd:** kandidaat-ratio TURBO = **9,2%** — midden in de band (FARTCOIN 7,3 / DOGEAI 8,2 /
MUMU 9,4 / **TURBO 9,2** / NOS 10,2%).

## Inlaadpad (7 stappen + 1 ontbrekende prerequisite)

1. `import_indicators.py 32` — 1.438.138 rijen, outlier-guard ving 5 corrupte ticks.
2. `coin_rule_settings` gericht: rules 20-23 = min_volume 3.951.626 (p90). Rule 101 niet nodig
   (sell-engine leest coin_rule_settings/min_volume niet).
3. `compute_volume_found.py 32` — 28.226/308.367 = 9,2% kandidaat.
4. **discovery tijdelijk uit → persist 20-23 → discovery aan → apply per rule** (zie hieronder):
   - 20-23: 579 executed, Σ +72,8% (r21 negatief −16,8% — dode-periode-ruis, regime filtert).
   - 30/31/32/34: +29 / −11 / +8 / −4% Σ, 67-70% slecht (gevestigd loser-zwaar patroon).
   - Totaal TURBO: **2233 executed, Σ +95,1%**.
5. `import_legacy_labels.py 32` — 137 legacy-labels, 0 ok/niet-ok (legacy ok_trade leeg).
6. `sell_promising.py 32 --run` — 21.953 coin_moment_sells.
7. `php artisan trades:auto-ok 32 --sell=8 --run` — **426 yes-marks** (set_by='auto-ok').
8. **PREREQUISITE die in het draaiboek ontbrak:** `coin_metrics.py` vult `coin_daily_metrics` (up_pct
   etc.) — vereist door de regime-gate. Zonder deze stap heeft `coin_regime.py` geen invoer. Draai
   `coin_metrics.py` (idempotent, alle munten) vóór `coin_regime.py`.

## De discovery-volgorde-valkuil (bevestigd uit [[coins-universe-4]])

`persist_to_brain.py` vuurt **alle globaal-actieve rules** (`rule_eng.rules.keys()`, regel 192) — incl.
discovery 30/31/32/34 — en doet greedy single-position dedup over alles samen. `apply.py --activate`
schrijft discovery-fires juist apart in de **idle-gaten van 20-23**. Door elkaar = vervuiling.

**Correcte volgorde (gebruikt):** discovery 30/31/32/34 op `active=0` → `persist_to_brain.py 32` (schoon
20-23, ~10× lichter) → discovery weer `active=1` → `apply.py --activate --coins 32 --rule N --write` in
volgorde 30→31→32→34. Rule 33 blijft inactief.

## apply.py-neveneffect op andere munten (NIEUW ontdekt — gefixt)

`apply.py --activate --write` heeft een **globaal** write-blok (regel 264-275) dat ongeacht `--coins`
de `strategies` + `coin_strategies` voor de discovery-rule reset naar een kopie van rule 20, voor **alle**
munten met een rule-20 coin_strategies-rij. Gevolg: MUMU (2735) kreeg nieuwe discovery coin_strategies
(kopie van MUMU's getunede rule-20, die afwijkt van de globale default) — een ongevraagde
gedragswijziging op MUMU.

**Gefixt:** de 4 MUMU-rijen (30/31/32/34) verwijderd → MUMU exact hersteld (diff schoon). TURBO heeft
zelf geen rule-20 coin_strategies en valt dus terug op globale `strategies` (net als MUMU/FARTCOIN).
Snapshot-diff vóór/na bevestigt: geen enkele andere munt gewijzigd.

## Verificatie (alle 4 geslaagd)

1. **Regime-gate:** TURBO = 4 intervallen (2 actief / 2 inactief). Gestopte munt → inactieve periodes
   aanwezig. Periodes: act jun-jul'23 → **inact** jul'23-aug'24 → act aug'24 → **inact** aug-nov'24.
2. **coin_fires:** 2233 executed, Σ +95,1%.
3. **Regime-filter:** haalt **1861 trades** (Σ +29,6%) uit inactieve periodes → **372 actieve trades,
   Σ +65,5%**. Actief = +0,18%/trade vs inactief +0,016%/trade → gate concentreert op winst-dichte
   periodes. **100% trade-dekking** (0 trades na 2024-11-24; TURBO's volume werd daarna te dun).
4. **Optimalisatie-keten** (`routines.py --trigger test --dry-run`): run #82 success in ~36 min,
   portfolio-totaal 0,08 (437/5437) over 5 munten, 978 kandidaten, geen fingerprint/cache-fouten.

## Conclusie

De hele keten werkt met een 5e munt, inclusief Epic H (regime) en Epic J (cache). Geen blokkades voor de
resterende 7 batch-munten. Twee documentatie-gaten gedicht: (a) min_volume-heuristiek voor legacy-loze
munten, (b) `coin_metrics.py` als regime-prerequisite, (c) apply.py globaal-neveneffect.
