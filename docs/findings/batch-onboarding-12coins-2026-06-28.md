# Batch-onboarding 4→12 munten — voltooid (2026-06-28)

**Status:** Alle 8 batch-munten ingeladen + geverifieerd. Universe is nu **12 munten**.
Vervolg op het draaiboek [[onboarding-batch-en-bouwvolgorde-2026-06-27]] en de TURBO-test
([[turbo-onboarding-2026-06-28]]). Per-munt-lessen: memory [[turbo-onboarding-learnings]].

## Resultaat — universe (12)

DOGEAI (2525), NOS (244), FARTCOIN (8427), MUMU (2735) [bestaand] + **TURBO (32), PONKE (2157), PEPE2
(37), ATR (2587), POPCAT (3168), CATDOG (7572), 1DOLLAR (8601), JELLYJELLY (8652)** [nieuw].

## Trades + regime-filter per nieuwe munt (executed, vóór tuning)

| munt | id | executed | Σ totaal | actieve trades | **actief Σ** | inactief Σ (gefilterd) |
|---|---|---|---|---|---|---|
| TURBO | 32 | 2233 | +95,1 | 372 | +65,5 | +29,6 |
| PONKE | 2157 | 2073 | +283,8 | 917 | +260,1 | +23,5 |
| PEPE2 | 37 | 484 | +100,2 | 463 | +95,9 | +3,6 |
| ATR | 2587 | 1001 | +128,5 | 515 | +134,2 | −5,8 |
| POPCAT | 3168 | 1237 | −11,9 | **2** | −0,9 | −9,4 |
| CATDOG | 7572 | 1092 | +405,6 | 727 | +307,9 | +78,5 |
| 1DOLLAR | 8601 | 419 | +119,3 | 194 | +153,3 | −33,6 |
| JELLYJELLY | 8652 | 783 | +76,5 | 266 | +63,9 | +14,6 |

De regime-gate haalt netto-verlies uit de dode periodes (1DOLLAR −33,6 / ATR −5,8). **POPCAT** is in dit
datavenster bijna volledig inactief (2 actieve trades) — terecht door de gate als niet-verhandelbaar
gemarkeerd; blijft in het menu tot een actief regime.

## min_volume per munt (de ijk-constante)

| munt | aanpak | min_volume | kandidaat-ratio |
|---|---|---|---|
| TURBO | p90 | 3.951.626 | 9,2% |
| PONKE | p90 | 8.705 | 8,6% |
| PEPE2 | **p94** (p90 gaf 14,4%) | 32.183.322.624 | 9,7% |
| ATR | p90 | 27.088 | 10,1% |
| POPCAT | p90 | 19.207 | 9,3% |
| CATDOG | **legacy-kopie** (had 20-23) | 40.743.603 | 9,2% |
| 1DOLLAR | **p97** (p90 gaf 19,8%) | 97.322 | 8,0% |
| JELLYJELLY | p90 | 41.228 | 11,1% |

**Les:** p90 is een startpunt, niet altijd raak. Verifieer ALTIJD de kandidaat-ratio na
`compute_volume_found`; zit 'ie >~11%, tune het percentiel omhoog (compute is ~3s → goedkoop zoeken).
CATDOG was de enige met legacy 20-23 → kopieer dan de legacy min_volume i.p.v. p90.

## Werkwijze (gescript, herbruikbaar)

`scratchpad/phase{A,B,C,D}.sh` (bash — zsh splitst `$VAR met spaties` niet):
- **A:** per munt import_indicators → min_volume seeden (p90/legacy) → compute_volume_found → ratio-check.
- **B (zwaar):** discovery 30/31/32/34 `active=0` → persist 20-23 ×6 → discovery `active=1` →
  `apply --activate --coins <id> --rule N --write` ×24 (30→31→32→34) → MUMU coin_strategies opruimen.
  Discovery-toggle MINIMAAL (1× uit/aan voor de hele batch).
- **C:** import_legacy_labels → sell_promising → `php artisan trades:auto-ok --sell=8 --run` ×6.
- **D:** coin_metrics (1×) → coin_regime per munt → verificatie.

**apply.py-neveneffect** (zie [[turbo-onboarding-2026-06-28]]): het globale write-blok gaf MUMU telkens
discovery coin_strategies (kopie rule-20 ≠ globaal) → 1× opgeruimd aan het eind van fase B; controle
bevestigt discovery coin_strategies alleen op 244/2525.

## De schone 12-munts-optimalisatie-run (run #84, --apply) — GEDRAAID

Volgorde: regime-routine #83 (regime + JSON-spiegel vers, alle 12) → rule-precisie `--apply` #84.
**Geverifieerd dat de optimizer op de actieve-periode-trades tunet:** `opt_lib.load_trades(include_inactive=
False)` filtert via `regime.active_sql_clause()` (NOT EXISTS inactief interval); regime-versie zit in de
fingerprint (Epic H #8).

**Uitkomst (run #84, success):**
- **rule-optimization:** portfolio-totaal 0,07 (748 goed / 10.009 executed actieve-periode-trades over 12
  munten). Per rule: r20 0,32 · r23 0,21 · r22 0,16 sterk; discovery r30-34 0,05-0,07 zwak. 978 nieuwe
  veilige kandidaat-verscherpingen gevonden (in-sample).
- **auto-apply: 0 toegepast** — alle sterkste kandidaten per rule **gezakt op de toeval-toets**
  (Šidák-gecorrigeerd). Geen enkele verscherping was significant genoeg → niets toegepast.
- **auto-loosen: 0 versoepeld, 0 afgewezen** (full-refire-gate, 6397s).
- **0 regelwijzigingen** (`rules_history` vandaag leeg) — live rules ongewijzigd.

**Duiding:** zelfs met 12 munten haalt geen enkele losse verscherping de significantie-lat (de
anti-ruis-discipline werkt). De 978 kandidaten staan als voorstel op het scherm, niet toegepast. Dit
bevestigt het patroon uit [[parent-gate-gemene-deler]]/[[rule-discovery-statistical-discipline]]: de
hefboom blijft de trade-kwaliteitsmix, niet strengere/lossere filters. De keten zelf draait schoon op 12.
