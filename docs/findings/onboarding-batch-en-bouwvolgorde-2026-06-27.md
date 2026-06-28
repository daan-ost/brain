# Munt-onboarding batch + bouwvolgorde (Epic H → J → inladen → één run)

**Status:** Vastgelegd 2026-06-27 · **INLADEN VOLTOOID 2026-06-28** — alle 8 batch-munten ingeladen +
geverifieerd, universe = 12. Zie `docs/findings/batch-onboarding-12coins-2026-06-28.md`. Resteert: de
schone 12-munts-optimalisatie-run (stap 4). · Refines de strategie uit
[[discovery-consensus-precision]] (consensus dood) + [[coin-regime-gate-plan]] (regime = de hefboom) +
[[coins-universe-4]] (het inlaad-pad).

## Doel

Van **4 → 12 munten**, met de optimalisatie-keten die daarna **in één keer schoon en snel** doorloopt.
De analyse (2026-06-27) wees uit: de #1 hefboom is meer munten (legacy heeft er **97**, wij hebben er 4),
en de echte kwaliteitswinst zit in de **regime-filter** (dode-periodes eruit → discovery-rules gepoold
Σ +547% → +725%). Consensus en rule-verscherping bleken doodlopend op het kleine universe.

## De munt-batch — VASTGELEGD (8 munten, 4 → 12)

Gekozen uit de legacy-analyse op: data-omvang (volumeud-reeks), tijdspanne (meerdere regimes → signaal
voor de regime-gate), recentheid en trade-kwaliteit. Alle 8 hebben de `volumeud`-reeks bevestigd
(prerequisite voor fires + sell). Referentie: NOS (geladen) = 143k volumeud-ticks.

### Tier 1 — rijkste data, lange historie (meerdere regimes)
| sym | munt | volumeud | periode | dagen | %slecht | Σpl | waarom |
|---|---|---|---|---|---|---|---|
| **32** | TURBO 🔸 | 308k | mei'23→jul'25 | **796** | 51% | +131 | langste historie van állemaal; Daans turbo |
| **2157** | PONKE | 328k | apr'24→mrt'25 | 340 | 49% | +630 | meeste volumeud-ticks; sterk |
| **37** | PEPE2 🔸 | 140k | jul'23→aug'24 | 409 | 43% | +162 | lange historie; Daans pepe |
| **3168** | POPCAT | 174k | aug'24→mrt'25 | 226 | 58% | +74 | data-rijk |

### Tier 2 — recent + goede kwaliteit (verse regimes, lage verlies-ratio)
| sym | munt | volumeud | periode | dagen | %slecht | Σpl | waarom |
|---|---|---|---|---|---|---|---|
| **8601** | 1DOLLAR | 131k | jan→nov'25 | 295 | **25%** | +364 | laagste verlies-ratio; recent |
| **8652** | JELLYJELLY | 130k | feb→nov'25 | 264 | 38% | +261 | recent + lange span |
| **2587** | ATR | 107k | mrt→okt'24 | 188 | 35% | +194 | goede kwaliteit |
| **7572** | CATDOG ⚠️ | 120k | aug→dec'24 | 106 | 47% | +1627 | hoogste Σ + 1746 trades, MÁÁR korte span (eerder overgeslagen, [[coins-universe-4]]) — oordeels-keuze |

🔸 = Daans pepe & turbo · ⚠️ = CATDOG kan eruit als je strikt op tijdspanne wilt (korter = minder
regime-diversiteit); de trade-rijkdom pleit er wel vóór.

### Bewust NIET inladen
- **sym 6419 (2e MUMU)** en **sym 6216 (2e DOGEAI)** — andere listings van munten die we al hebben
  (2735/2525); ze vertroebelen het universe. Overslaan.
- **CATDOGE (3115)** — leeg in legacy ([[coins-universe-4]]).

### Tier 3 — backlog (later, kleiner/spiky)
LISTEN (8597, Σ2033 maar 21 dagen), VINU (1860, 38d), ARCSOL (522), GALAXIS (8291), ALPACA (1194),
CENTS (8552), FWOG (7635). Kandidaten voor een tweede batch zodra de eerste 8 draaien.

## Bouwvolgorde — waarom H → J → inladen → run

Beide epics zijn **pure infra** (hangen niet af van de nieuwe munten), dus eerst bouwen, dán inladen.
Allebei zijn precies wat een 12-munts-run nodig heeft: zonder J loopt auto-loosen uren vast, zonder H
tunet de rule-tuner op dode-periode-ruis (verspilde run).

### 1. Epic H eerst — [epic-H-regime-apply](../epics/epic-H-regime-apply.md)
De regime-filter (`coin_regime`-tabel + wekelijkse routine + actieve-periode-filter in `load_trades`).
**Reden om H als eerste:** hij legt de cache-regel vast (beslissing #8) — "de regime-versie moet in élke
optimalisatie-cache-vingerafdruk" (`_long_fingerprint` + `input_fingerprint`). Bouw je J eerst, dan moet
H achteraf de regime-versie in J's nieuwe rq2-cache nabouwen (makkelijk te missen). H-eerst → J's cache is
meteen regime-bewust.
- **Bouw-onafhankelijk van de munten:** de code (tabel/helper/filter/cache-versie) is nu te bouwen; de
  validatie draait op de huidige 4 munten. De regime-berekening voor de níéuwe munten gebeurt pas ná het
  inladen (stap 3), automatisch (de gate is bewezen overdraagbaar, munt-eruit-laten 90-98%).

### 2. Epic J — [epic-J-loosen-cache](../epics/epic-J-loosen-cache.md)
Auto-loosen (rq2) caching. Hing op Epic I (nu af → vrijgespeeld). Zijn rq2-cache wordt regime-bewust
gebouwd omdat H de cache-discipline al heeft gezet. Zonder J zou de 12-munts-run op auto-loosen vastlopen
(draaide al >15 min op 4 munten).

### 3. Munten inladen (de 8 batch) — koude refires, ~1,5-2u serieel
Per munt het bestaande **inlaad-pad** ([[coins-universe-4]], alle scripts in `engine/src/`):
1. `import_indicators.py <id>` — 5 indicatoren + coin-rij uit `bot_signals` (idempotent).
2. `coin_rule_settings` gericht vullen uit `bot_signals.wp_trading_symbols_rule` (min_volume per rule —
   **NIET** `seed_rules.py`, die wist de getunede rules!).
3. `compute_volume_found.py <id>`.
4. `persist_to_brain.py <id>` — eerst **20-23** (lichter); dan rules 30-34 via `apply.py --activate
   --coins <id> --rule N` in volgorde 30→31→32→34 (anders vervuilt de globale dedup de discovery-fires).
5. `import_legacy_labels.py <id>` — kwaliteit-labels.
6. `sell_promising.py <id> --run` — `coin_moment_sells`.
7. **yes-marks:** `php artisan trades:auto-ok <id> --sell=8 --run` (auto-ok genereert de yes-marks die
   `rises()` nodig heeft; terugdraaien: `DELETE FROM coin_moment_labels WHERE set_by='auto-ok'`).
8. **`coin_metrics.py`** (idempotent, alle munten) — vult `coin_daily_metrics`, de invoer die `coin_regime.py`
   nodig heeft. Draai dit vóór de regime-routine (stap 4-verificatie). **Stond niet in het oorspronkelijke
   pad — toegevoegd na TURBO** ([[turbo-onboarding-learnings]]).

**Twee valkuilen ontdekt bij TURBO (2026-06-28) — zie `docs/findings/turbo-onboarding-2026-06-28.md`:**
- **Munt zónder legacy 20-23** (bijv. TURBO): seed in stap 2 `min_volume ≈ p90 van de volumeud-reeks`
  voor 20-23 (NIET 0 → crasht; NIET de min_volume van andere legacy-rule-nummers). Verifieer ná
  `compute_volume_found` dat de kandidaat-ratio in 7-10% valt.
- **`apply.py --activate --write` globaal neveneffect:** reset `coin_strategies` van de discovery-rule
  naar kopie-rule-20 voor álle munten met een rule-20-rij. Snapshot-diff `coin_strategies` vóór/na en
  verwijder rijen op munten die je niet inlaadt (bij TURBO kreeg MUMU ongevraagd rijen → hersteld).
- **Valkuil (belangrijk):** lange persist/apply-taken worden afgebroken bij een user-turn / harness-timeout.
  Draai elke munt-refire via **`run_detached.py`** (overleeft de timeout) en poll; apply per rule is
  idempotent + hervatbaar. Reken op ~12-15 min koude refire per munt (Epic I versnelt pas de dagelijkse
  aangroei, niet de eerste build).
- **Verifieer bij de bouw** dat de 7 scripts nog bestaan/kloppen (de memory is een momentopname).

### 4. Eén schone + snelle run op alle 12
De feedback-volgorde (epic-H beslissing #7): **(1) trades → (2) regime berekenen → (3) rules tunen op
actieve trades → (4) re-fire → (5) regime herberekenen.** Concreet:
1. **Regime-routine** (`coin-regime`, wekelijks) → gate alle 12 munten (nieuwe munten krijgen automatisch
   een aan/uit-oordeel).
2. **Optimalisatie-keten** (`routines.py --trigger routine --apply`) → tunet nu op de **actieve** trades
   (H-filter) en draait **snel** (J-cache + schaalplan + Epic I).
3. Kalibreer de nieuwe munten visueel in `/coins/weekly` (groen/rode streep) tegen je oog.

## Verwachte uitkomst / waarom dit de moeite is
- **Genoeg data voor de toeval-toets:** nu faalt elke verscherping op te weinig data (532 kandidaten, 0
  toegepast). Met 12 munten kunnen er eindelijk een paar over de lat komen.
- **Schone basis:** de ~28% dode-periode-trades (straks méér, want TURBO/PEPE2/PONKE zijn gestopte munten)
  worden door H gefilterd → rule-ratio's, consensus én toeval-toets meten op de trades die we écht maken.
- **Herbevestiging regime-gate:** epic-G noteerde "herbevestigen met meer munten" — 12 munten leveren dat.

## Niet in scope (van dit draaiboek)
- De live-laag / echt traden (epic-G Fase 4, epic-09/10/11).
- Epic-M (MEXC-scan voor níéuwe, niet-legacy munten) — pas relevant als de legacy-voorraad op is.
- Epic-RDA (discovery-automatisering) — loont pas mét meer munten + ná de schone run.
