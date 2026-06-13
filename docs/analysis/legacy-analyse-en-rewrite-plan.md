# Legacy-analyse & Rewrite-plan — bot_signals trading bot

**Datum:** 2026-06-13
**Auteur:** senior trading-systeem architect (analyse op basis van diepe codebase- en DB-inspectie)
**Scope:** analyse van de huidige business-rule-/discovery-methodiek + concreet rewrite- en ML-uitbreidingsplan.
**Harde randvoorwaarde:** de `bot_signals` database is een **read-only bron** — er wordt NOOIT in geschreven. Alle nieuwe werk gebeurt in een aparte database/slice.

---

## Beslissingen (door Daan, 2026-06-13)

| # | Vraag | Beslissing |
|---|---|---|
| 1 | Filter-balans / risico | **Behoudend** — houd ≥90% van de goede trades, filter binnen die grens zoveel mogelijk slechte weg. Bepaalt de threshold-tuning. |
| 2 | Scope zelf-leren | **Ook nieuwe strategieën** — het systeem mag niet alleen subrules tunen binnen de 8 active rule_numbers, maar ook compleet nieuwe rule_numbers/indicator-combinaties verzinnen. |
| 3 | PoC-slice | **DOGEAI 5m (`id=2525`), 25 feb 2025** — door Daan aangewezen; geverifieerd 5 goed vs 20 slecht (zie §4.1). |
| 4 | Werkwijze nu | Eerst visie delen (wat maakt dit succesvol buiten de formules), daarna bouwen via roadmap.md + epics in `/docs`. |
| 5 | Slice-omvang | **Lean** — basis goed krijgen zonder constant veel data. Train op gelabelde DOGEAI 5m dagen, showcase op 25 feb. |
| 6 | `result=2` (middel) | Mag meegaan, maar Daan deed er niets actiefs mee → PoC traint primair goed (1) vs slecht (3). |
| 7 | Indicator-bron | Base-indicators (vzo, phobos, obv-x-value, mfi, volumeud) komen via **webhook uit TradingView** — black-box, TV-instellingen buiten scope. Afgeleide features (skewness, volatility, reversal count… uit de "Test type"-lijst) berekenen wij zelf in Python. |
| 8 | Oude code | **Inspiratie, geen fundament.** Nieuwe tabellen/methodes mogen vrij. De waardevolle formules (skewness e.d.) overnemen, de rest niet. |
| 9 | Dode fitness-functie (`showEffect.php:577`) | Onbekend of bewust uitgezet — Daan zoekt op. Voorlopig als inspiratie behandelen. |
| 10 | Rule-focus | Alleen de gebruikte rules **11, 12, 20, 21, 23** — de rest is troep en wordt genegeerd. |
| 11 | PoC-aanpak | **Eén coin, één rule, één periode** eerst. Start: **rule 20 op DOGEAI** (31 goed / 128 slecht — slechtste false-positive-ratio = meeste winst). Daarna 21 → 23 → tweede coin. Geen 100M rijen. |
| 12 | Twee hoofddoelen | (a) **Weten wanneer te stoppen met een coin** = volatility gating (E05). (b) **Verbeteren wat er al is** = de bestaande rules scherper filteren (E03/E06), niet vervangen. |

---

## 0. Eén-zin-samenvatting van het kernprobleem

> Het systeem **koopt de goede trades wél aan** (recall op goede trades is hoog), maar **pakt te veel slechte mee** (precision is te laag). De kern van de rewrite is een **binaire classificatie met precision-recall-tradeoff**: behoud recall op `result=1` (goed), verhoog precision door `result=3` (slecht) automatisch weg te filteren — en laat het systeem dat filter zélf leren in plaats van met de hand.

---

## 1. Analyse van de kern: hoe bepaalt het systeem nu business rules?

### 1.1 De drielaagse architectuur

| Laag | Tabel | Wat het is | Hoe gevuld |
|---|---|---|---|
| **Ruwe feed** | `wp_trading_indicator` (~101M rijen, 7,2 GB, maand-gepartitioneerd) | 1 rij = 1 indicator-tick van TradingView per coin/minuut | webhook `retrieve_signal_tv.php` |
| **Feature-match** | `wp_trading_simulation_trades_indicator` (~11M rijen) | 1 rij = 1 subrule geëvalueerd op 1 tijdstip; `result_ok` 0/1 | `rule_engine()` |
| **Trade-uitkomst + label** | `wp_trading_simulation` (15.262 rijen) | 1 rij = 1 gevonden trade met `profit_loss` + handmatig `result`-label | backtest + mens |

### 1.2 De rule-engine (de "business rules")

De kern is `rule_engine()` in `managesignal/functions_br.php:268` (8 parameters — **dit is de productieversie**; de variant in `functions_br1.php:331` is legacy en mag genegeerd worden).

**Datamodel van een rule:**
- Een **rule** (`rule_number`) is een geordende verzameling **subrules** — rijen in `wp_trading_rules`.
- Subrules worden geladen met: `SELECT * FROM wp_trading_rules WHERE active=1 AND rule_number='$rule' ORDER BY sort ASC`.
- `subrulename` kiest het **check-type** via een ~50-case `switch` (regel 460). Belangrijkste types:
  - `currentvalue` (r1992): indicatorwaarde moet binnen band `[b_min, b_max]` liggen — **de meest gebruikte koopvoorwaarde**.
  - `action` (r3313): indicator stijgt/daalt (`up`/`down`).
  - `lowest`/`highest` (r3593/r3788): min/max over `def1_value` minuten.
  - `diff`/`higherthan` (r4051/r4161): vergelijkt twee **eerdere subrule-resultaten** (`def1_value`/`def2_value` zijn dan **verwijzingen naar subrule-ID's**, geen getallen).
- **AND-semantiek:** `$result_br` start op `true`; elke subrule kan hem op `false` zetten. In productie breekt de lus af bij de eerste fail. Geen OR/AND-boom — combineren gebeurt via (a) sequentiële AND en (b) cross-subrule-referenties via het array `$result_rule_number[$subrule_number]`.
- **Voorwaardelijke laag** (`operator` + `value_condition` + `condition_rule`, r2033-2166): adaptieve drempels. Bv. `lowerthan`/`higherthan` activeert alternatieve grenzen `b_min_alt`/`b_max_alt` als een referentie-subrule onder/boven een drempel valt; `time_ago` is een verse-data-gate.

**Eindbeslissing:** `$rule_engine_result['result']` = koopsignaal ja/nee (zie `save_subrule.php:936/944`).

### 1.3 Classificatie: goed/middel/slecht (de ground truth)

`wp_trading_simulation.result` is een **handmatig** kwaliteitslabel (NIET berekend — komt rechtstreeks uit `$_POST['result']` in `add_simulate_trade.php`). De labels correleren sterk met de werkelijke uitkomst — geverifieerd in de DB:

| `result` | Betekenis | Aantal | Avg profit_loss | Min | Max |
|---|---|---|---|---|---|
| 1 | **Goed** | 948 | **+5,97%** | -4,81 | +174,84 |
| 2 | Middel | 710 | +0,96% | -4,62 | +16,15 |
| 3 | **Slecht** | 2.503 | **-0,53%** | -6,87 | +12,69 |
| NULL | Ongelabeld | 10.356 | +1,22% | -15,17 | +133,92 |
| 99 / 999 / 4 / 12 | speciaal/legacy (samen 8 rijen) | 8 | — | — | — |

Dit is **de meest waardevolle asset van het hele project**: een met-de-hand gecureerde, schaarse ground-truth dataset met menselijke NL-`remark`-annotaties ("10% mogelijk, opnieuw bekijken", "1h timeframe not ok").

> **Let op de naam-dubbelzinnigheid:** `result` op `wp_trading_simulation` = handmatig goed/middel/slecht. `result` op `wp_trading_simulation_trades_result` = geautomatiseerd "alle voorwaarden voldaan?" (0/1, want `result=1` alleen als `amount_bad=0`). Dit zijn TWEE verschillende dingen die toevallig dezelfde kolomnaam delen.

### 1.4 Discovery: hoe worden grenzen nu "gevonden"?

Vandaag is dit **half-geautomatiseerd en browser-gedreven** (geen cron). Drie gescheiden assen:

1. **`def1_value` (lookback-periode)** — de enige as die echt geloopt wordt: brute-force `period_test` 1..30 + 60 in `trades_volume_analysis.php`.
2. **`b_min`/`b_max` bij nieuwe subrule** — NIET gezocht, maar **afgeleid uit de data-envelop**: neem de indicatorwaarden van de goede trades, knip 1 uitschieter per kant weg (`removeExtremes($arr,1)`), neem min/max. Dit is **per definitie overfitting** op de historie.
3. **`b_min`/`b_max` verruimen** (de echte self-tuning) — `inc_save_promissing_trades.php`: vind goede trades die net buiten de grens vielen, verruim greedy 1 stap richting de dichtstbijzijnde gemiste trade, valideer met `showEffect()`, schrijf direct weg.

**De live fitness-metric is zwak:** `showEffect()` telt alleen "aantal trades met exact 1 blokkerende indicator (`amount_bad=1`) dat door de nieuwe grens gered wordt" (`counter_found > 0`). De rijkere 8-regel profit-scoring (r577-619) staat in een `if(1==2)` **dead-code blok** en draait niet.

**Twee handmatige stappen blijven over:** (a) iemand klikt "Start automation - all" en houdt een browsertab open; (b) een geheel nieuwe subrule vereist een menselijke klik op "Add rule".

### 1.5 De huidige rule-set in cijfers (geverifieerd in DB)

- `wp_trading_rules`: 900 subrules, **776 active**. Meest gebruikte indicatoren: vzo (199), obv-x-value (153), mfi (131), volumeud (119), phobos (105).
- `wp_trading_allrules`: 29 strategieën, **8 active** (rule_number 10, 11, 12, 18, 20, 21, 22, 23). Elk met `SL_settings` JSON (`min_sl`, `minutes_in_trade`, `minimal_profit`, `hp_settings`).
- `wp_trading_symbols`: 8.360 totaal, **105 active** — vooral low-cap meme/AI coins (FARTCOIN, PNUT, BERT, JELLYJELLY…) + BTC als referentie, timeframe 5 of 60 min.

### 1.6 Het goed-moment/volume-filter

De **eerste, grove filter** is het volumeprofiel (`check_volumeud_1/2` in `functions_br.php:5452/5593`): een goed koopmoment vereist een volume-spike (`current >= min_volume`) ná een aanloop van netto verkoopvolume, met som-volume >= 4× drempel. De prijs-window-scoring (zak <1%, daarna >5% upside binnen x min) zit deels hardcoded in `trades_volatility_analysis.php` (4h/5%) en deels in `calc_abs_diff_percentage()` (max drop + max upside binnen window). De magic numbers (5%, 0,3%, 4×, multipliers 3.7/3.4/-4/6/-11) staan verspreid hardcoded.

---

## 2. Herschrijven & verbeteren — moderne architectuur

### 2.1 Frame het kernprobleem expliciet als ML

| Aspect | Invulling |
|---|---|
| **Taak** | Binaire classificatie: `slechte trade?` (positieve klasse = `result=3`). Of 3-klasse (1/2/3) of regressie op `profit_loss`. |
| **Trainings-X** | De indicator-features per koopmoment (vzo, obv-x-value, mfi, volumeud, phobos, tsi + skewness/volatility-windows) uit `wp_trading_indicator` rond `datetime`. |
| **Trainings-y** | De 15K handmatige labels (`wp_trading_simulation.result`) + `profit_loss` als secundaire/regressie-target. |
| **Metric** | **Behoud recall ≥ X% op goede trades, maximaliseer precision** (= weinig slechte trades doorlaten). Optimaliseer op **precision-recall AUC**, niet accuracy (klassen zijn onbalanced: 948 goed vs 2.503 slecht vs 10K ongelabeld). |
| **Threshold-tuning** | Het model geeft een kans `P(slecht)`. De **beslisdrempel** wordt apart getuned op een hold-out window om de gewenste recall/precision-balans te halen — niet hard op 0,5. |

### 2.2 Stack-verdeling

**Laravel (app / orchestratie / API / labelbeheer):**
- Behoud de read-only Eloquent-laag (`bot-laravel/`) met de prefix-conventie (connectie doet `wp_`, `$table` zonder prefix). Behoud de query-scopes en `json_result`-cast.
- Vervang magic-int statussen door **PHP enums** (single source of truth, conform globale standaard): `TradeQuality {Good=1, Medium=2, Bad=3}`, `SignalMatched` (bool), `SubruleType`, `ConditionOperator`, `IndicatorType`. Ruim de `'obv'` vs `'obv-x-value'` / `'phobos'` vs `'phobosrange'` synoniemen op tot een canonieke enum.
- Orchestratie via **queue-jobs + scheduler** (Horizon), niet browser-tabs met `window.location`-reloads.
- API conform `basewebsite/docs/api/conventions.md`: `auth:sanctum` + `throttle:api-v2` + `track-api-token` op álle trading-routes, `{"data":...}` wrapper, ULID's, bedragen in centen, ISO 8601, `{"error":...,"message":...}` bij faal.

**Python (feature engineering + ML):**
- **Feature store:** pandas/numpy + ta-lib. Bouw per koopmoment een feature-vector uit het indicator-tijdvenster.
- **Model:** **gradient boosting — LightGBM** (eerste keus, snel op tabulaire data) of **XGBoost** als baseline. Tabulaire indicator-features → klasse/kans. De bestaande `b_min/b_max`-banden zijn ideale **feature-bins / monotone constraints / init**.
- **Validatie:** **time-series / walk-forward cross-validation** (geen random split — voorkom leakage in een tijdreeks). Train op verleden, test op toekomst.
- **Threshold-tuning:** kies de drempel op de validatie-fold die recall op goed behoudt en precision maximaliseert.
- **Exit-policy apart:** de stop-loss/`SL_settings` blijft een **aparte, expliciet geparametriseerde exit-policy** (later eventueel Bayesian/RL-getuned) — NIET vermengen met het entry-model.

### 2.3 Evaluatie-engine herontwerp (van switch naar strategy-pattern)

Vervang de monolithische 4000-regel `switch` door een **Strategy-pattern**: één klasse per `SubruleType` met `evaluate(MarketContext): SubruleResult`. Een `RuleEvaluator` draait ze op `sort`-volgorde, voert AND-semantiek uit en exposeert een `result_rule_number`-achtige context-bag. Elk check-type wordt los testbaar — **verplicht test op het error-pad per type**, niet alleen happy path (conform globale standaard).

De zware/numerieke checks (volatility, correlation, volumeprofiel) verhuizen naar de Python-service; Laravel roept aan en cachet.

### 2.4 Wat behouden, wat slopen

**Behouden (hard verdiende domeinkennis):**
- Het config-driven rule/subrule-concept (strategie zonder herdeploy).
- De drielaagse datamodel-scheiding (ruwe tick → feature-match → trade-uitkomst).
- De verkoopsimulatie met minuut-voor-minuut trailing stop-loss (`process_sell_simulation_trade`) — levert ook MFE/MAE/drawdown.
- De `amount_bad=1`-isolatie van `showEffect` (marginale gevallen) — bruikbaar als feature-importance-signaal.
- De indicator-semantiek (imi≥70 top, rsi<2 sell, phobos<-50 oversold).
- De maand-partitionering van de indicator-tabel.

**Slopen:** dubbele `rule_engine`-definities, `|| 1==1`, `die;`, dode cases, display-HTML in evaluatielogica, `wp_trading_ranges` (leeg) + dode kolommen `fsvrbd`/`cryonshape`/`cryonblue`, string-geïnterpoleerde SQL (SQL-injectie in `update_subrule_field.php`, `showEffect.php`), de throwaway test-controllers en `/hash-password`/`/debug-password` debug-routes in `bot-laravel/`, en de gebroken `analyzeVolume()`-signatuur-mismatch (service neemt `int + array`, controllers geven `model + scalars` — crasht nu).

---

## 3. 100% automatiseerbaar — de zelf-lerende zoek/leer-loop

Doel: het systeem verzint ZELF nieuwe rules/berekeningen zonder mens. Vier vereiste veranderingen t.o.v. nu:

### 3.1 Haal de mens uit de loop
- Vervang de browser-keten door **Laravel scheduler/queue-jobs**.
- Vervang de "Add rule"-klik door **automatische insert** zodra een kandidaat een fitness-drempel haalt op out-of-sample data.

### 3.2 Echte zoekmachine i.p.v. 1D period-loop + envelop
Een **guided search** over de gezamenlijke parameter-ruimte `(indicator, def1_value, def2_value, b_min, b_max, value_condition)`:
- **Bayesian optimization** (Optuna) — efficiënt, weinig evaluaties.
- of een **evolutionair/genetisch algoritme** (DEAP): de subrule is het genotype; mutatie = grens verschuiven / window wijzigen / indicator wisselen; crossover = subrules combineren tot een nieuwe rule. **Dit is hoe het systeem "nieuwe berekeningen verzint".**
- Grid-search alleen als baseline.

### 3.3 Eén expliciete fitness-functie (single source of truth)
De live `counter_found > 0` is te zwak. Definieer één fitness, geïmplementeerd als Python-module op de simulatie-tabellen:

```
fitness = netto_added_profit_op_holdout
        - λ1 * (#nieuwe_slechte_trades_toegelaten)
        - λ2 * (overfit_penalty: train_score - holdout_score)
```

De doordachte (nu dode) 8-regel scoring in `showEffect.php:577-619` (meer positief dan negatief, max 2 negatief, added_profit>10, ratio's) is een **goede specificatie-basis** voor deze fitness — mits eerst bevestigd of die bewust uitgezet was.

### 3.4 De loop (volledig autonoom)
```
1. Pull: nieuwe gelabelde + ongelabelde trades uit read-only bron → slice-DB.
2. Feature-build (Python): indicator-windows → feature-vectoren.
3. Train LightGBM op walk-forward folds; tune threshold.
4. Search (Bayesian/GA): genereer kandidaat-subrules/rules.
5. Backtest elke kandidaat op een HOLD-OUT window (leakage-veilig).
6. Fitness-gate: kandidaten boven drempel → automatisch insert + activate.
7. Audit-trail: log elke auto-gewijzigde rule met fitness-score, window,
   model-versie en SHAP-redenen (zodat mens kan terugrollen en zien WAAROM).
8. Loop terug naar 1 (scheduled).
```

**Overfit-bewaking is verplicht:** de huidige envelop-methode trekt de grens exact om de geobserveerde goede trades — kandidaat-grenzen moeten op een hold-out periode bewezen worden vóór activatie.

---

## 4. Concrete eerste stap — het 1-2-coins data-slice plan

Geen 100M-rijen-gedoe in het begin. Kopieer een kleine, beheersbare slice naar een **nieuwe database** en bouw daarop een proof-of-concept.

### 4.1 Coin- en periode-keuze — VASTGESTELD: DOGEAI 5m, 25 feb 2025

Door Daan aangewezen als goede analysedag. Geverifieerd in de DB — dit is het precision-recall-probleem in het klein:

- **Coin:** DOGEAI 5m, `trading_symbol_id=2525` (coinpair `DOGEAIUSDT`). Er bestaat ook een 60m-variant (`id=6216`) die we optioneel als tweede slice meenemen.
- **Dag:** 2025-02-25 — **5 goede trades (result=1, gem. +20%, top +68%)** vs **20 slechte (result=3, gem. −0,9%)** + 4 middel + 15 ongelabeld. ~4.656 indicator-rijen.
- **Beschikbare features die dag:** `obv-x-value` (1178 ticks), `mfi` (1177), `vzo` (1172), `volumeud` (843), `phobos` (286).
- **Cruciale observatie:** dezelfde rules (20, 21) vuren op zowel goede als slechte trades — de strategie onderscheidt de momenten niet, dus een fijnere entry-filter (ML) is de juiste oplossing. De goede trades hebben hoge `highest_profit_loss` (5–83%), de slechte blijven onder ~0,75%.
- **Let op:** trade 12044 is handmatig als "goed" gelabeld met slechts +0,5% top → de labels zijn menselijk oordeel, geen mechanische drempel. Een auto-label-regel zou hiervan afwijken (zie open vraag #2).
- Eventueel een rustige tweede dag of BTC als referentie toevoegen voor walk-forward validatie.

### 4.2 Welke tabellen kopiëren (read-only bron → `bot_signals_slice`)
1. `wp_trading_indicator` — gefilterd op `trading_symbol_id IN (...)` AND `datetime` in de 2-daagse window (+ 3u marge ervoor voor lookback-windows).
2. `wp_trading_simulation` — zelfde symbol-filter + window (de labels = ground truth).
3. `wp_trading_simulation_trades_indicator` — de feature-match-rijen voor die simulaties.
4. `wp_trading_simulation_trades_result` — geaggregeerde signaal-match.
5. `wp_trading_rules` (alle 776 active — klein, volledig kopiëren) + `wp_trading_allrules` (8 active, met `SL_settings`).
6. `wp_trading_symbols` (alleen de 1-2 gekozen rijen).

Kopie-aanpak (read-only blijft gegarandeerd — alleen SELECT uit de bron):
```bash
# 1. lege slice-db
/Applications/MAMP/Library/bin/mysql80/bin/mysql -u root -proot -P 8889 -h 127.0.0.1 \
  -e "CREATE DATABASE IF NOT EXISTS bot_signals_slice"
# 2. per tabel een SELECT … INTO OUTFILE of mysqldump met --where, dan importeren in slice
```

### 4.3 De eerste proof-of-concept (toont aan dat we slechte trades wegfilteren)
**Doel:** bewijzen dat een model de `result=3` (slechte) trades wegfiltert terwijl het de `result=1` (goede) behoudt.

1. **Export** de slice naar een Python-notebook (pandas).
2. **Feature-engineering:** per gelabelde trade in `wp_trading_simulation`, bouw features uit de indicator-waarden in het venster vóór `datetime` (laatste N ticks van vzo, obv-x-value, mfi, volumeud, phobos + afgeleiden: slope, drawdown, volume-som).
3. **Train** een LightGBM-classifier (`result=3` vs `result=1`) met walk-forward split (eerste dag train, tweede dag test).
4. **Rapporteer** de confusion matrix + **precision-recall curve**. Toon expliciet: bij recall=90% op goede trades, hoeveel % van de slechte trades filtert het model weg?
5. **Baseline-vergelijking:** draai dezelfde test met de huidige rule-engine (via de gekopieerde `wp_trading_rules`) en laat zien dat het ML-model bij gelijke recall méér slechte trades wegfiltert.

**Succescriterium PoC:** "Bij gelijke recall op goede trades (zeg ≥90%) filtert het ML-model significant meer `result=3`-trades weg dan de huidige 776 hand-getunede subrules." Dat is het directe bewijs voor Daans kerndoel.

> MEXC-aankoop blijft bewust buiten scope in deze fase. Het uitvoeringssubsysteem (`bot_*.php`, `mexc.php`) is een aparte, latere epic — daar geldt: trade-lifecycle als enum + state machine, idempotente queue-jobs, één `MexcClient` met `SSL_VERIFYPEER=true`, keys via Laravel encrypted casts.

---

## 5. Open vragen voor Daan (ontdubbeld, alleen het echt belangrijke)

1. **Labels & feedbackrichting.** De 15K `wp_trading_simulation.result`-labels zijn de hele ML-trainset. Zet jij die handmatig (mens scoort goed/middel/slecht), of genereert iets ze deels automatisch? En wil je dat de NULL-result rijen (10.356) eerst gelabeld worden via een annotatie-workflow voordat we trainen?
2. **Wat is een "goed koopmoment" precies?** De definitie "prijs zakt <1% in window, daarna >5% upside binnen x min" vind ik nergens hard in code. Is dat de gewenste **automatische label-regel** (zodat we de NULL's en toekomstige trades automatisch kunnen labelen), of bepaal je dat per geval met het oog? Wat zijn de exacte parameters (max_drop %, min_upside %, window-minuten, stabilisatie-window)?
3. **Indicator-berekening.** Worden RSI/TSI/Phobos/VZO/OBV/MFI in PHP berekend of komen ze kant-en-klaar uit Pine-scripts op TradingView? In de code zie ik alleen consumptie. Voor reproduceerbare Python-feature-engineering moeten we de exacte formules van phobos en obv-x-value kennen (staan niet in de DB).
4. **Fitness-functie.** De rijke 8-regel profit-scoring in `showEffect.php` (r577-619) staat in dood `if(1==2)`-code. Was die bewust uitgezet (te streng/traag) of work-in-progress? Dit bepaalt of we hem als basis voor de autonome fitness nemen.
5. **Scope van zelf-leren.** De 8 active strategieën (rule_number 10,11,12,18,20,21,22,23) zijn hardcoded. Moet het zelf-lerende systeem ook **nieuwe rule_numbers (nieuwe strategieën)** verzinnen, of alleen subrules binnen deze 8 tunen?
6. **Risico-acceptatie.** Wat is de gewenste balans? Liever 90% van de goede trades houden en 70% van de slechte wegfilteren, of agressiever filteren (minder slechte, maar ook wat goede missen)? Dit bepaalt de threshold-tuning concreet.
7. **`json_result['quantity']`.** Is dat veld altijd aanwezig in `wp_trading_simulation`? De Laravel volume-analyse valt terug op default `1` als het ontbreekt, wat de volume-weging betekenisloos maakt.

---

**Geschreven naar:** `/Users/daanvantongeren/Documents/Sites/bot/docs/analysis/legacy-analyse-en-rewrite-plan.md`
