# Koop-engine & rule-discovery — tijdlijn & uitkomsten

Chronologisch overzicht van alles wat we met de koop-regels, rule-discovery en verwante systemen hebben gedaan.
Voor de methodologie: zie [methodology/rule-discovery.md](methodology/rule-discovery.md).

---

## 2026-06-12 — Project setup

**Wat:** Eerste setup van het project op basis van basewebsite. Lege Laravel + Python engine.

---

## 2026-06-13 — Rule-21 rebuild + eerste validatie

**Wat:** Rule-21 helemaal opnieuw gebouwd in Python. Validatie tegen de oracle (`bot_signals`). Engine-screens in Laravel.

**Uitkomst:** Rule-21 fire-overeenkomst: **99,96% vs oracle**. Rebuild is bewijsbaar correct.

**Gelijktijdig:** `selling-process.md` vastgelegd (legacy-spec byte voor byte), eerste sell-engine PoC (87% P&L).

---

## 2026-06-13 — Feature-store spec

**Wat:** Specificatie vastgelegd voor de 20-lookback feature store: 29 berekeningen × lookback 1..20 per (indicator, datetime). Basis voor alle latere ML en rule-discovery.

**Document:** `docs/methodology/feature-store.md`

---

## 2026-06-14 — Rules 20/22/23 geport + feature store v1

**Wat:**
- Rules 20, 22, 23 in Python geport (generaliserend patroon voor alle regels)
- `calc.py`: alle ~29 "Test type" berekeningen geport uit legacy PHP
- Parquet feature store gebouwd (`indicator_metrics` cache)
- `futureprice` als koop-bevestiging geport (live sentinel = PASS, backtest-only look-ahead)

**Uitkomst (validatie):**
| Rule | Fire-overeenkomst |
|---|---|
| 20 | 99,6% |
| 21 | 99,96% |
| 22 | 98,8% |
| 23 | 99,7% |

---

## 2026-06-14 — Promising-momenten definitie + NOS als coin #2

**Wat:** "Goed koop-moment" geformaliseerd als code: `find_promising_trades()` op basis van prijs-pad na instap (max drawdown, beschikbare upside binnen horizon, zonder whippy pad). NOS toegevoegd als tweede coin. Trade-horizon ingesteld op 1 uur.

**Uitkomst:** 1-uur-horizon bevonden (bevinding: `trade-horizon-1hour.md`). NOS heeft 152 goede / 119 slechte trades.

---

## 2026-06-14 — Coin Explorer + UI

**Wat:** `/coin-explorer` screen met dag-navigator, shadow-fires (grijs), per-fire promising-label, grafiek met koop/verkoop/promising markers, click-through naar detail.

---

## 2026-06-14 — Eerste rule-tuning: subrules toegevoegd

**Wat:** Per-rule, per-bereken, per-lookback: kijk of goede momenten in een smalle band zitten die slechte weren. Subrules toegevoegd aan rules 20/21/22/23.

**Uitkomst:** Eerste batch subrules geverifieerd (out-of-sample check: goede trades blijven?). Bevinding: greedy stacking overfit op 2 munten — subrules op zichzelf generaliseren, gecombineerde stapels niet.

**Documenten:**
- `docs/findings/precision-overfitting.md` — greedy stacker overfit
- `docs/optimization/2026-06-14-rule-set-optimization.md`

---

## 2026-06-15 — Dagelijkse optimalisatie-routine + auto-apply

**Wat:** `daily_optimization.py` + `routines.py` (routine-runner met journaal + `/routines` scherm). Auto-apply gate: alleen toepassen als het totale slecht-count daalt. Schaal-geldigheidsbewaker (`scale_validity_guard`). `rules_history` audit-trail.

**Uitkomst:** Routines draaien dagelijks, resultaten opgeslagen. Auto-apply werkt maar is conservatief.

---

## 2026-06-15 — Nieuwe-feature discovery (eerste verkenning, 2 munten)

**Wat:** ~57 berekening-varianten getoetst op separatievermogen. Settings-only sweep op volume-params.

**Uitkomst:** GEEN feature breekt de 2-munten-muur (beste: −4 van 57 kandidaten). Kleine lever gevonden (rule-21 trigger ~0,022). **Binding constraint = 2-munten-universe.**

**Document:** `docs/optimization/2026-06-15-new-feature-discovery.md`

---

## 2026-06-16 — Recall-onderzoek: NOS no-candidate plafond

**Wat:** Waarom mist de engine ~80% van de NOS ok-momenten? Vier onderzoekspaden parallel:
1. **Worklist** — 143 no-candidate NOS-misses gesorteerd (19a/50b/74c)
2. **Volume-gate** — kan een volume-drempel de no-candidates vangen? → Werkt NIET (25/143, flood+dilutie: ratio 1.438→1.403)
3. **Lange-termijn volume-discriminator** — scheidt goede van slechte near-misses? → ZWAK (in-sample winst klapt in holdout)
4. **Seed-tighten op NOS** — strakke (min,max)-hull op 10 NOS seed-ticks → Overfit. 0 van 2429 boxes overleeft tijds-holdout. Box-definitie (~1200 AND) is de bottleneck.

**Conclusie:** NOS recall-plafond is **structureel ~20%** — ~80% van de ok-momenten zit simpelweg niet op vf=1-kandidaat-ticks. Dit is een data-vraag (handelbaarheid/coin-gedrag), niet een feature-vraag.

**Documenten:**
- `docs/findings/recall-worklist-2026-06-16.md`
- `docs/findings/recall-nocandidate-altvolume-2026-06-16.md`
- `docs/findings/recall-longvolume-discriminator-2026-06-16.md`
- `docs/findings/recall-seed-tighten-nos-2026-06-16.md`

---

## 2026-06-17 — Koop-bevestiging (futureprice): spooktrades weg

**Wat:** Engine sloeg de futureprice koop-bevestiging over → ~380 spooktrades (bijna allemaal verliezers) werden onterecht meegeteld. Fix: instap = signaalprijs (geverifieerd tegen legacy), kruising = alleen trigger.

**Uitkomst:**
- ~380 spooktrades weg
- Verliezers ~gehalveerd
- Σprofit omhoog
- Rule-22 futureprice hersteld

**Gevolg:** Sell-tuning-cijfers verouderd (hele trade-set veranderd). Buy-tuning meet-instrument (b_min afstellen) gebouwd als follow-up.

**Document:** `docs/memory/buy-confirmation-futureprice.md`

---

## 2026-06-17 — Motor omgeschakeld naar brain_volume_found

**Wat:** `brain_volume_found` als candidate-gate, vervangt het legacy `volume_check`. De brain-vlag is meteen actief voor TradingView-coins.

---

## 2026-06-19 — MEXC-marktscan + coin-rotatie

**Wat:** `/coins/mexc` scherm gebouwd: scan MEXC-markt op volatiele USDT-kandidaten. Coin-rotatie met kansrijkheid-ranking (Epic V). Epic M gedocumenteerd (klaar om te bouwen).

**Document:** `docs/findings/mexc-volatiele-coins-2026-06-19.md`

---

## 2026-06-21 — Auto-ok labeling + promising-labeler verbeteringen

**Wat:** `trades:auto-ok` command vult lege promising-momenten automatisch. Verificatie-tab voor conflict-review. Vroege-dip-grens toegevoegd ("eerst in de min" = auto-niet-ok). Filter op onze sell-winst (≥3/5/10%).

---

## 2026-06-22 — Rule-discovery engine + rule 30 (live)

**Wat:** Bottom-up rule-discovery engine gebouwd (Epic RD). `find_promising_trades()` + parent-gate + child-subrule-stacking + toeval-toets + tijds-holdout. Rule 30 gevonden en live gezet (coin-agnostisch, witte-ruimte methode).

**Uitkomst:**
- Rule 30 live: eerste automatisch gevonden regel die de systematische discovery-methode doorloopt
- Methode werkt als zeef, maar haalt de 20-23-lat nog niet op 2 munten
- Winnaar-schaarste (niet slechte-abundantie) is de rem

**Document:** `docs/methodology/rule-discovery.md` §13

---

## 2026-06-23 — Feature-kwaliteit database

**Wat:** `brain.feature_quality` tabel met per berekening het scheidingsvermogen op een brede promising-bron (1000en winnaars). 13 nieuwe berekeningen onderzocht (web-research).

**Uitkomst:**
- Gini en IQR zijn de beste nieuwe berekeningen (scheiden prijs-amplitude goed)
- Prijs-amplitude scheidt (niet richting of oscillator-niveau)
- Geen "wonderfeature" gevonden

**Document:** `docs/findings/feature-berekeningen-research-2026-06-23.md`

---

## 2026-06-23 — Rule 31 live + rule 32 inactief

**Wat:**
- Rule 31 via witte-ruimte werkwijze live gezet (generieke voorrang-regels bij activatie)
- Rule 32 op witte ruimte ontdekt, **inactief bewaard** (past niet aan 20-23-lat, maar klaar als de portfolio groeit)
- `--whitespace` vlag met dynamische voorrang

**Uitkomst:** 2 rules live (30+31), 1 inactief (32). Toeval-toets (p<0,05, Šidák) doorstaan voor alle live rules.

---

## 2026-06-23 — Oscillator-niveau geen edge (currentvalue)

**Wat:** Daans hypothese getoetst: helpt het oscillator-niveau (currentvalue) om goede van slechte trades te scheiden in de gepoolde r30/31-trades?

**Uitkomst:** NEE. `currentvalue` scheidt winst/verlies niet in de gepoolde rule-30/31-trades. Bevestigt: discovery-rules gebruiken alleen vorm-metrics, geen niveau.

---

## Huidige stand (2026-06-23)

| Component | Status |
|---|---|
| Rules 20/21/22/23 herbouwt + gevalideerd | ✅ (99,6–99,96%) |
| Feature store (29 berekeningen × 20 lookbacks) | ✅ |
| Promising-momenten definitie | ✅ |
| Rule-tuning routine (dagelijks) | ✅ Live |
| Koop-bevestiging (futureprice) | ✅ Gefixed |
| Rule-discovery engine (bottom-up) | ✅ Gebouwd |
| Rule 30 + 31 live | ✅ |
| Rule 32 inactief (klaar voor activatie) | ✅ |
| Feature-kwaliteit database | ✅ |

## Binding constraint & echte hefboom

De koop-engine heeft op **2 munten (DOGEAI + NOS)** zijn grens bereikt. Elke nieuwe aanpak (meer features, stakkere regels, andere bronnen) stoot op de 2-munten-muur. Het echte signaal wordt te klein om van toeval te onderscheiden.

**De enige echte hefboom: meer munten gelijktijdig.** Met ~10 munten kunnen de huidige rules 30/31/32 (en nieuwe) bovendrijven. De discovery-methode + harness staan klaar.

Volgende stap: **MEXC-scan epic (M)** → nieuwe volatiele munten aan het systeem toevoegen.

## Doel: ~10 rules vinden, dan stoppen

Portfolio-filosofie (zie `CLAUDE.md`): een rule hoeft NU niet aan de 20-23-lat te voldoen. We leggen elke gevonden rule inactief vast. De aan/uit-beslissing valt bij live traden — de inactieve rules zijn het keuzemenu. Met meer munten komen sommige vanzelf boven de lat.
