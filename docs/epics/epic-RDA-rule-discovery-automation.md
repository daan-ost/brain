# EPIC RDA: Rule-Discovery Automatisering — autonome ontdek-loop + routine-integratie

**Phase:** 2 — Na Epic RD (de engine) + meer munten (Epic 07)
**Status:** TE BOUWEN. Verfijnt/operationaliseert E06 (autonomous rule-discovery) en E08 (daily
orchestration) met de bewezen methodiek uit Epic RD + [[docs/methodology/rule-discovery.md]].
**Depends on:** **Epic RD** (de discovery-engine), Epic 07 (meer munten = de data-hefboom), Epic L
(promising-definitie voor auto-labelen), `routines.py` (de routine-runner + gate-stack).

## Epic Specification

Maak van de handmatige discovery-engine (Epic RD) een **periodiek, autonoom proces**: ververs de feature
store, label promising momenten automatisch over **alle** munten, draai segmentatie + funnel + validatie,
en stel KEEPER-rules voor — **eerst human-gated, later auto-promote** — met volledige audit
(`rules_history`) en journaling naar `/routines`. Plus: de **bestaande routines aanpassen** zodat ze op de
correcte maat (`profit_loss`, 20-23-lat) en gate-logica draaien.

## Rationale

De methodiek is bewezen-correct (het is letterlijk Subgroup Discovery + Rule Induction + López de Prado's
backtest-overfitting-statistiek — zie [[rule-discovery-prior-art]]). De bottleneck is **data + engineering**,
niet de aanpak. Compute is begrensd: de feature store is een **eenmalige/incrementele** kost; de zoektocht
snoeit (beam-search), en permutatie/PBO is N× een snelle zoektocht — een **nachtelijke job**, geen
rekenmonster. De linchpin voor schaal is **auto-labelen**: zonder dat moet elke munt handmatig afgevinkt,
en dat schaalt niet.

## Beslissingen

| # | Vraag | Beslissing |
|---|---|---|
| 1 | Grondwaarheid bij schaal | **Auto-label** promising momenten uit het forward-prijspad (+X% binnen Ymin, geen vroege dip) — de promising-definitie van Epic L, toegepast over alle munten. Handmatige yes-marks blijven leidend waar aanwezig. |
| 2 | Wanneer auto-promote | **Eerst propose-only (human-gated)**, auto-promote pas als de gate zich bewezen heeft. |
| 3 | Validatie-gate | CPCV/PBO (Epic RD) + de **incrementele refire-gate** (base 20-23 vs base+nieuw, één-positie). |
| 4 | Universe | Modelleer **de hele munt-universe** (López de Prado), niet per los effect — vereist Epic 07. |
| 5 | Audit | Elke voorgestelde/toegepaste rule via `rules_history` (zoals `auto_apply` nu). |

## Features (5)

### 1. Auto-labeler (promising-definitie over alle munten)
**Status:** Approved. Formaliseer "promising moment" als code (Epic L-definitie: forward max-up ≥ drempel,
geen vroege dip) en draai over alle munten → auto-grondwaarheid die meeschaalt. Handmatige marks > auto.
**Acceptance Criteria**
- [ ] Genereert promising-groepen per munt zonder handwerk; handmatige yes-marks overschrijven auto.
- [ ] Reproduceert Daans handmatige groepen op DOGEAI/NOS binnen redelijke marge (kalibratie).

### 2. Incrementele feature-store refresh (routine)
**Status:** Approved. Nieuwe ticks → store bijwerken; per munt, leak-free. Voedt de discovery-engine.
**Acceptance Criteria**
- [ ] Dagelijkse incrementele update; geen volledige herbouw nodig.

### 3. Discovery-routine (nieuwe SET in `routines.py`)
**Status:** Approved. Nieuwe set `rule-discovery`: auto-label → segmentatie → funnel → validatie → propose.
Journalt naar `/routines` met de compacte rapportage per munt. Eigen fingerprint (labels + store + coins).
**Acceptance Criteria**
- [ ] `routines.py --set rule-discovery` draait de hele keten en journalt.
- [ ] Propose-only tot `--apply`; KEEPER-criteria = Epic RD §4.

### 4. Apply-gate + auto-promote
**Status:** Approved. KEEPER → nieuw `rule_number` via `discovery/apply.py`, gated op de incrementele
refire (Σprofit niet omlaag, verliezers niet omhoog, selectiviteit op 20-23-schaal), met `rules_history`.
**Acceptance Criteria**
- [ ] Een toegevoegde rule verslechtert 20-23 niet (engine-refire-gate) en is volledig auditeerbaar.

### 5. Orchestratie (E08-haak)
**Status:** Approved. Dagelijkse cron-keten: store-refresh → (per munt) discovery → validatie → rapport.
**Acceptance Criteria**
- [ ] Eén scheduled run dekt alle munten; rapport zichtbaar in `/routines`.

## Routine-aanpassingen aan de HUIDIGE routines (belangrijk)

Onze learnings + de critical-eye op de bestaande `rule-precision`-keten vragen deze fixes:

| Routine / bestand | Aanpassing | Waarom |
|---|---|---|
| `auto_loosen.py` (gate) | **gate-asymmetrie fixen**: gate 1 classificeert op `best_upside`, gate 2 op `profit_loss` met `<=`; maak beide `profit_loss` + strikt `<` (zoals `auto_apply`). | Critical-eye: een verbreding kan nu netto verliezers toevoegen. |
| `opt_lib.py` docstrings + `daily_optimization.py` | Alles consequent op **`profit_loss`** (best_upside-tekst verwijderen; header al gefixt). | Code stuurt al op profit_loss; docs logen nog best_upside ([[feedback-hard-numbers-only]]). |
| gate-stack (`auto_apply`/`auto_loosen`) | **Selectiviteit (%ticks) als gate** toevoegen — nu nergens gemeten. | "Te los" is de #1 faalmode; loosen/nieuwe-rules hebben een rem nodig. |
| succescriterium in de tuning-routine | Van `#goed≥2×#slecht` op best_upside → **20-23 `profit_loss`-lat** ([[rule-success-criterion]] vervangen). | Eén consistente lat; best_upside afgeschaft als oordeel. |
| `routines.py` `SETS` | **Nieuwe set `rule-discovery`** toevoegen (Feature 3); fingerprint incl. labels + store. | De discovery-keten naast de bestaande tuning-keten. |
| holdout overal | Upgrade naar **CPCV + purging/embargo** waar nu enkel time-split. | Strenger OOS, verdeling i.p.v. één split (López de Prado). |

## Aanbevolen Implementatie Volgorde

1. **Eerst Epic 07 (meer munten)** + **Feature 1 (auto-labeler)** — zonder data + auto-labels heeft de rest geen zin.
2. Routine-aanpassingen aan de bestaande keten (de tabel hierboven) — laaghangend, maakt de basis correct.
3. Feature 2 (store-refresh-routine) + Feature 3 (discovery-routine, propose-only).
4. Feature 4 (apply-gate) + Feature 5 (orchestratie), human-gated → auto-promote.

## Niet in scope

- **De discovery-engine zelf** → Epic RD.
- **Order-executie** → Epic 10.
- **Auto-promote vanaf dag 1** — bewust eerst human-gated tot de gate zich bewijst.
