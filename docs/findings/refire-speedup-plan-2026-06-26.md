# Refire-versnelling — bouwplan (concept, ter beoordeling)

**Datum:** 2026-06-26 · **Status:** plan, niets gebouwd · **Doel:** `persist_to_brain` (de refire) van
~13 min/zware-munt naar seconden-tot-enkele-minuten, **bit-identiek** (de uitkomst `coin_fires` is de
complete trade-set — één afwijking schuift alle cijfers eronder).

## Diagnose (gemeten, niet gegokt)

De refire-tijd zit vrijwel volledig in `rule_engine.fires()`. Gemeten op NOS (244), 143.190 ticks:

| Onderdeel | Tijd | Waarom |
|---|---|---|
| RuleEngine()+SellEngine() init | ~7s | series laden |
| Gated rules 20-23 | ~26s totaal (r22 alleen 18,5s) | alleen op vf=1-kandidaatticks (~8.248) |
| **Discovery rules 30/31/32/34** | **>2,5 min PER STUK** | **ongepoort → elke tick (143.190), per subregel window-metric herberekend** |

Op MUMU (meer ticks + meer trades) loopt dit op tot ~13 min. De discovery-rules zijn ~90% van de refire.

**Hot loop:** `fires(rule)` loopt over alle ticks; voor ongepoorde discovery-rules draait `_fire_at` op
**elke** tick en herberekent per subregel `subrule_value(name, vals, prices)` (skewness/std/… over een
as-of-venster). O(ticks × subregels × window-compute).

## Waarom de eerste hypothese (cache-lookup) NIET werkt

De `indicator_metrics`-cache lijkt de oplossing (heeft (indicator,lookback,calc)→waarde per datetime,
zelfde cache als de validatie). **Maar gemeten:** NOS-cache = **6.923 datetimes**, terwijl de
discovery-rules **143.190 ticks** evalueren. De cache dekt alleen de kandidaat-/trade-ticks, niet de
volle tick-reeks. Een per-tick opzoek-truc helpt dus alleen de goedkope gated rules (26s), niet de
discovery-rules (de bottleneck). **Dit pad is een doodlopen** tenzij we de cache 20× groter maken (alle
ticks) — duur en niet de elegante route.

## De elegante route: memoïseer `fires()` op wat het écht bepaalt

Kern-inzicht: **`fires()` (de koop-kant) hangt ALLEEN af van (a) de rule-definities (banden/active) en
(b) de indicator-reeksen. NIET van de verkoop-knoppen (`sl_settings`).**

Gevolg voor de pijnlijkste use-case (sell-tuning): een sell-tuning-refire wijzigt alleen een verkoop-knop
→ **welke trades vuren verandert niet**, alleen de sell-engine-P&L erna. Toch herberekent `persist_to_brain`
elke keer `all_fires` vanaf nul (~10 min weggegooid). Idem voor auto-loosen-revert-refires en elke refire
waar de koop-rules niet wijzigden.

### Plan A — fires-memoïsatie (aanbevolen, laagste risico)

Cache `all_fires` (de lijst (datetime, rule) vóór dedup/sell) per munt, gesleuteld op een fingerprint van
**alleen de fires-bepalende inputs**:
- rule-definities (rule_number, subrules, b_min/b_max, active, min_volume) — checksum
- de indicator-reeksen per munt (count + max(datetime) + waarde-checksum) — de bestaande
  `_coin_fingerprint`-bouwstenen hergebruiken, maar **zonder** de sell-strategies en CHANGELOG_REASON
  (die mogen de fires-cache NIET invalideren).

In `persist_to_brain`: als de fires-fingerprint matcht → laad `all_fires` uit de cache (parquet of een
brain-tabel `coin_fires_cache`), sla de hele `for rule … fires()`-lus over. Mismatch → bereken + schrijf
cache weg (atomic, zoals load_long_cached). De dedup + koop-bevestiging + sell-engine-P&L-lus draait
altijd (die hangt wél van de knoppen af, maar is de goedkope ~seconden-stap).

**Effect:** sell-tuning-apply (15 voorstellen × refire-gate) en auto-loosen-reverts vallen van ~13 min/munt
naar de sell-loop-tijd (seconden). De koude eerste refire (rules gewijzigd) blijft ~13 min — daarvoor Plan B.

### Plan B — vectoriseer `fires()` voor de koude cache (optioneel, hoger risico)

Voor de eerste refire ná een rule-wijziging: vervang de per-tick Python-lus door één numpy-pass per
(indicator, lookback, calc) over de volle tick-reeks, dan de AND-condities vectorized. Zelfde
schaalplan-truc, nu op de firing. Hoger risico (kern-motor) → alleen ná Plan A en met dezelfde orakel-test.

### Niet doen
- ❌ `indicator_metrics` opblazen naar alle ticks (20× groter; lost alleen de lookup op, niet de
  fundamentele per-tick-kost; Plan B is goedkoper).
- ❌ Discovery-rules gaten (gated maken) — verandert de semantiek/uitkomst.
- ❌ Parallelle refire opnieuw — botst op InnoDB gap-locks (al teruggedraaid naar serieel, commit `0ad1b3f`).

## Vangnet — bit-identieke orakel-test (VERPLICHT, bouw eerst)

Vóór één regel aan `fires()`/`persist_to_brain` verandert: een test die de OUDE en NIEUWE fires-uitkomst
vergelijkt over **alle 4 munten × alle rules**, en eist dat de lijst fire-datetimes **exact gelijk** is
(zelfde set, zelfde volgorde). Plus: na een volledige refire de `coin_fires` (datetime, rule, is_executed,
profit_loss, klasse) bit-vergelijken met een snapshot van vóór de wijziging. Pas mergen als 100% gelijk.
Hergebruik het patroon van `test_opt_lib.py`.

## Te verifiëren vóór de bouw (open vragen)

1. **Sell-loop-aandeel meten** — bevestig dat de dedup+sell-engine-lus (na `all_fires`) echt de goedkope
   stap is (verwacht seconden). Zo niet, dan moet Plan A ook daar kijken.
2. **fires-fingerprint compleet?** — welke inputs bepalen `fires()` precies? Alles in `RuleEngine.__init__`
   (rules-query + indicator-series + minvol/relvol_base). De sell-strategies zitten daar NIET in → goed.
   Dubbelcheck dat geen verborgen input (config-constante, gate_col) ontbreekt.
3. **Cache-opslag** — brain-tabel vs parquet. Parquet (zoals load_long_cached) is consistent met het
   schaalplan en buiten de DB-lock-druk; brain-tabel is makkelijker transactioneel met de refire.
4. **Interactie met A1** (`_coin_fingerprint` skip-on-unchanged) — A1 slaat de héle refire over als niets
   wijzigt; Plan A werkt binnen een refire die wél draait (sell-knop wijzigde) maar waar de fires gelijk
   zijn. Beide naast elkaar, niet dubbelop.

## Volgorde

1. Orakel-test bouwen (oude vs nieuwe fires + coin_fires-snapshot).
2. Sell-loop-aandeel meten (open vraag 1) — bevestigt dat Plan A de juiste hefboom is.
3. Plan A (fires-memoïsatie) bouwen achter de test. Meten op sell-tuning-apply (moet van ~2u naar minuten).
4. Pas als de koude refire nog knelt: Plan B (vectorisatie), zelfde test.

Zie ook [[routine-4coin-runtime-wall]] (de live-run bevindingen + de twee al-gefixte knelpunten) en het
schaalplan `docs/findings/optimize-scaling-plan-2026-06-25.md` (zelfde cache+orakel-aanpak op de validatie).
