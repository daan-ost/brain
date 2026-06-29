# NoBrainersBot — systeembeschrijving + de uitdaging van rule 30/31

> **Doel van dit document.** Dit is een zelfstandig leesbare beschrijving van een crypto-trading-systeem,
> bedoeld als input voor een **tweede LLM die een onafhankelijke review doet**. We zoeken specifiek: wélke
> insteken om twee koop-rules (30 en 31) te verbeteren hebben we over het hoofd gezien? De concrete ambitie:
> **~50% minder slechte trades bij behoud van de upside** (de winnende trades zijn goed genoeg; het probleem
> is het aandeel verliezers). Aan het eind staan de reviewvragen. Lees eerst het systeem, dan de uitdaging.
>
> De lezer kent het project niet — alles wat nodig is om mee te denken staat hieronder. Technische namen
> (tabellen, functies, indicatoren) staan in `code font`.

---

## 1. Wat het systeem doet (in één alinea)

We handelen geautomatiseerd in volatiele low-cap crypto-munten (memecoins) op 5-minuten candles. Per munt
hebben we een tijdreeks van indicatoren. Een set **koop-rules** bepaalt op welke momenten we instappen; een
**verkoop-engine** (een meelopende stop / "winst-lock") bepaalt wanneer we eruit gaan. Het systeem is een
herbouw + validatie van een oudere "legacy bot", uitgebreid met zelf-ontdekte rules, een verkoop-engine met
afstelbare knoppen, een **regime-gate** (wanneer een munt aan/uit staat), en een optimalisatie-keten met
strenge statistische toetsing. Alles draait offline op historische data; live traden is de volgende stap.

## 2. De data

- **11 munten** (DOGEAI, NOS, FARTCOIN, MUMU, CATDOG, TURBO, PONKE, PEPE2, ATR, 1DOLLAR, JELLYJELLY),
  elk een tijdreeks van **5-minuten candles** over maanden tot ~2 jaar.
- **5 indicatoren** per tick (uit de legacy bron), elk een eigen tijdreeks:
  | Indicator | Wat het ruwweg meet |
  |---|---|
  | `vzo` | Volume Zone Oscillator — koop- vs verkoopvolume-balans |
  | `phobos` | momentum/trend-oscillator (legacy-eigen) |
  | `obv-x-value` | On-Balance-Volume-afgeleide — cumulatieve volumedruk |
  | `mfi` | Money Flow Index — volume-gewogen RSI (overbought/oversold) |
  | `volumeud` | volume up/down — het ruwe handelsvolume per tick |
- **Eén afgeleide:** `relvol = volumeud / min_volume`. `min_volume` is een **per-munt ijk-constante** (de
  volume-schaal van die munt; ~90e percentiel van de volumeud-verdeling). Alleen de discovery-rules (30-34)
  gebruiken `relvol`; de legacy-rules (20-23) gebruiken `volumeud` direct.

## 3. De rules

Een rule is een **AND van subregels**: een tick is een koop-kandidaat als élke subregel "waar" is. Subregels
draaien op één indicator en hebben een type (de "operator") + een venster (`def1_value` = lookback in ticks) +
een toegestane band (`b_min`–`b_max`).

**De subregel-types (operators):**
| Type | Betekenis |
|---|---|
| `currentvalue` | huidige indicatorwaarde moet in [b_min, b_max] liggen (= een NIVEAU-filter) |
| `previous_value` | de waarde N ticks terug moet in de band liggen |
| `skewness` / `standard_deviation` / `volatility` / `range_percentage` | VORM-metrics over een lookback-venster (scheefheid, spreiding, beweeglijkheid, bandbreedte) |
| `consecutive_increases` / `consecutive_decreases` / `reversal_count` | PATROON-metrics (hoeveel ticks op rij omhoog/omlaag, aantal omkeringen) |
| `sideways_upper` / `sideways_lower` | of de reeks zijwaarts binnen een band beweegt |
| `sum_average_positive_percentage` / `diff_*` | diverse afgeleiden (sommen, verschillen t.o.v. min/max/vorige) |
| `volume_check` (`check_volumeud_3`) | de **volume-poort**: `relvol` moet boven de drempel — de eerste zeef |
| `futureprice` / `futureprice_x_rows` | **koop-bevestiging**: na het signaal wacht de bot tot de prijs binnen een venster weer bóven de signaalprijs "kruist"; pas dan is de koop bevestigd. De kruising is enkel de trigger; de instap is de signaalprijs/-tijd. |
| `missingdata` | data-gat-check (te veel ontbrekende ticks → skip) |

**De twee families rules:**

- **20, 21, 22, 23 — de bewezen legacy-rules** (39–82 subregels elk). Gebruiken vooral `currentvalue`
  (niveaus), `skewness`, `previous_value`, en `futureprice` (koop-bevestiging) op de 5 indicatoren.
  Gebruiken **geen** `relvol`. Dit zijn de rules die we vertrouwen.
- **30, 31, 32, 34 — zelf-ontdekte "discovery"-rules** (54–56 subregels; 33 is inactief). Gevonden door onze
  eigen discovery-engine. Gebruiken **vorm- en patroon-metrics** (`consecutive_*`, `reversal_count`,
  `sideways_*`, `range_percentage`, `skewness`, `standard_deviation`, `volatility`) + `relvol`. Ze gebruiken
  bewust **géén** `currentvalue` (niveau bleek geen edge te geven). Ze zijn gevonden in de **"witte ruimte"** —
  koop-momenten waar de legacy-rules 20-23 niet vuren.
- **101 — een verkoop-signaal-rule** (`sell_negative_volume`, `sell_x_below`), geen koop-rule.

## 4. De engine-pipeline (van tick tot trade)

1. **Ingest** — de 5 indicator-reeksen per munt in `brain.indicators`.
2. **min_volume** per munt vastgesteld → `relvol` berekenbaar → de volume-poort werkt.
3. **Fires** — per rule, per tick, alle subregels evalueren (AND). Een tick die slaagt = koop-kandidaat.
4. **Dedup (één positie tegelijk)** — alle fires van álle rules samen, chronologisch. Een greedy loop houdt
   één globale "positie open tot"-grens bij: vuurt een signaal — van wélke rule dan ook — terwijl er een
   positie open is, dan wordt het een **shadow** (telt niet als trade). Zo handelen we nooit twee posities
   tegelijk. (Dit spiegelt de legacy bot exact.)
5. **Koop-bevestiging** (`futureprice`) — zie boven; rules zonder futureprice kopen direct op het signaal.
6. **Verkoop-engine** — per echte trade bepaalt de winst-lock de verkoopprijs/-tijd → `profit_loss`.
7. **Promising-label** — een onafhankelijk oordeel "was dit objectief een goed koop-moment?" (de prijs stijgt
   binnen 60 min ≥ 3% zonder eerst diep te dippen). Dit is de maat van "potentie", los van welke rule vuurde.
8. **Regime-gate** — per munt per week aan/uit (zie §6). Trades uit een inactieve periode tellen niet mee.

De uitkomst staat in `coin_fires` (per trade: rule, instap, verkoop, `profit_loss`, `is_executed` 1/0).

## 5. De verkoop-engine (winst-lock / meelopende stop)

Zodra een trade open is, beweegt een **stop-prijs** mee omhoog naarmate de winst stijgt, en zakt nooit terug —
zo zetten we winst vast en kappen we verliezers af. Instelbare knoppen (in een gedeelde default-tabel
`strategies` + per-munt overrides in `coin_strategies`):
- `min_sl1` / `min_sl2` — de **harde stop-bodem** (multiplier op de instapprijs), per leeftijdsfase.
- `minutes_in_trade1/2`, `minimal_profit` — wanneer welke bodem geldt.
- `hp_setting1..7` — de winst-lock-trappen: hoeveel van de piekwinst we vasthouden per winst-niveau.
- `array_profit` — een tijd/winst-ladder (na X min moet de winst ≥ Y%, anders eruit).

Deze knoppen zijn afgesteld met een aparte testperiode (holdout) + toeval-toets. Recent is de stop-bodem voor
30/31 al iets opgehoogd (`min_sl1` 0,988 → 0,992) — de enige bewezen kleine winst (zie §9/§10).

## 6. De regime-gate (wanneer staat een munt aan?)

Een munt kan "uitgewerkt" raken (een dode staart van alleen verliezers). De regime-gate zet een munt **uit**
als het 4-weeks rollende handelsresultaat te lang onder een drempel zakt, en weer **aan** als het herstelt
(leak-vrij: de beslissing op week T gebruikt alleen data t/m T). Trades uit een inactieve periode tellen
**standaard niet** mee in de cijfers of de rule-optimalisatie — we meten op de trades die we écht zouden
hebben gemaakt. Dit verwijderde ~28% dode-periode-trades uit de beoordeling.

## 7. De optimalisatie-keten + statistische discipline

Routines die rules en knoppen bijstellen, allemaal met een **vaste statistische lat**:
- **rq1 (aanscherpen)** — subregel-banden strakker maken om verliezers te weren.
- **rq2 (versoepelen)** — banden ruimer om goede gemiste momenten alsnog te pakken.
- **sell-tuning** — de verkoop-knoppen per munt + de gedeelde default afstellen.
- **discovery** — nieuwe koop-rules vinden (zo zijn 30-34 ontstaan).

**De lat (verplicht, niet onderhandelbaar):**
- **Toeval-toets** (permutatie/sign-flip): schud de uitkomsten, herhaal de zoektocht; de echte voorsprong moet
  in de staart vallen (p < 0,05). Met **Šidák-correctie** bij meerdere kandidaten (anders tovert een scan
  edges uit ruis).
- **Apart-gehouden testperiode** (echte tijd-holdout): kiezen op de oude helft, bevestigen op de nieuwe.
- **Alleen gerealiseerde `profit_loss`** als succes-maat — nooit "potentiële stijging".
- Voor zwaardere zoektochten ook **CPCV / PBO / Deflated Sharpe** (López de Prado) om te corrigeren voor het
  aantal geprobeerde kandidaten.

## 8. Wat we tot nu toe bereikt hebben

- Legacy bot herbouwd en gevalideerd tegen de oude database ("oracle").
- Universe uitgebreid 2 → 4 → **11 munten**.
- Verkoop-engine (winst-lock) gebouwd + getuned (verliezers goedkoper, winst vastgezet).
- Regime-gate gebouwd (dode periodes eruit).
- Discovery-engine gebouwd → rules 30-34 gevonden.
- Caching + incrementele herberekening (de hele keten draait nu in minuten i.p.v. uren).
- Gepoolde verkoop-default over alle munten geoptimaliseerd (stop-bodem 20-23 +0,2 procentpunt = −163 verliezers).

**Prestatie per koop-rule** (binnen regime, momentopname eind juni 2026; RUW, zonder transactiekosten):

| Rule | trades | winst/trade | middel heft slecht op? | munten + / − | oordeel |
|---|---|---|---|---|---|
| 23 | 227 | **+1,57%** | ✅ | 10 / 1 | **live-waardig** |
| 20 | 393 | **+1,39%** | ✅ | 11 / 0 | **live-waardig** |
| 22 | 1075 | **+0,91%** | ✅ | 11 / 0 | **live-waardig** |
| 21 | 570 | +0,36% | ❌ | 8 / 4 | te dun |
| **31** | **~1250** | **+0,26%** | ❌ | 9 / 3 | **kanshebber, te dun** |
| **30** | **~1340** | **+0,25%** | ❌ | 7 / 5 | **kanshebber, te dun** |
| 32 | 1583 | +0,20% | ❌ | 9 / 3 | te dun |
| 34 | 727 | +0,18% | ❌ | 7 / 4 | te dun |

"Middel heft slecht op" = of de licht-winstgevende trades (0–3%) samen de verliezers compenseren; bij 20/22/23
wel, bij de rest niet. **De slippage-lat:** een trade moet > ~0,30%/trade opleveren (fee ~0,10% + slippage
~0,20% op illiquide memecoins) om netto winst te maken. 20/22/23 halen dat ruim; 30/31 niet.

---

## 9. DE UITDAGING — rule 30 en 31

**Wat is goed aan 30/31:** ze vinden koop-momenten in de "witte ruimte" die 20-23 missen, en de **upside is
er**: de winnende trades zijn echt goed (de overlap-trades doen ~+3%/trade — zie hieronder). Ze zijn netto
positief (Σ +364% / +331% ruw). Met meer munten erbij willen we ze in het portfolio.

**Wat is het probleem:** te veel verliezers. ~65–70% van de 30/31-trades is "slecht" (`profit_loss < 0`).
Per trade leveren ze maar +0,25–0,26% op (ruw) — ónder de slippage-lat. De licht-winstgevende trades
compenseren de verliezers niet.

**Het concrete doel:** **~50% minder slechte trades, met behoud van de upside.** Als we de verliezers zouden
kunnen halveren zonder de winnaars te verliezen, springt de marge per trade ruim boven de slippage-lat en
worden 30/31 volwaardig live-waardig.

**De kern van de moeilijkheid (5× bevestigd in eigen onderzoek):** of een 30/31-trade wint of verliest wordt
bepaald door de **prijsbeweging ná instap** — en niets wat we vóór instap weten kon dat voorspellen. Elke
instap-feature die we probeerden scheidt de latere winnaars niet van de verliezers.

**De marginale-waarde-analyse** (waarom het extra lastig is):
- **92–93% van de 30/31-trades is "puur"** (geen 20-23-signaal in hetzelfde tijdvenster → echte witte ruimte).
- Maar precies die pure trades zijn de **dunne, verliesgevende massa**: +0,06–0,09%/trade, ~70% slecht.
- De winst zit in de **~8% "overlap"-trades** (waar kort na de 30/31-instap óók een 20-23-signaal komt):
  ~+3%/trade. Dat is reële "vroeger instappen op een sterke beweging"-waarde — **maar niet vooraf te
  isoleren**, want of er een 20-23-bevestiging volgt weet je pas ná instap.

## 10. Wat we al geprobeerd hebben (en waarom het niet werkte)

Een uitgebreid multi-agent-onderzoek (6 invalshoeken, elk idee getoetst met holdout + toeval-toets) leverde
**één** kleine bewezen winst en een lange lijst doodlopers op.

**De enige werkende verbetering:** de harde stop-bodem (`min_sl1`) voor 30/31 van 0,988 → 0,992–0,994 (optimum
loopt door tot ~0,996). Effect: ondiepere verliezers, +0,03–0,05%/trade. **Maar dit breekt de loser-kern
NIET** — het aandeel slechte trades blijft ~65%; het maakt ze alleen goedkoper. (Inmiddels toegepast:
rule 30 staat nu op `min_sl1 = 0,992`.)

**Doodgelopen insteken (allemaal getoetst, allemaal gefaald op holdout of toeval-toets):**
1. **Instap-filter op vorm/niveau** (skewness, std, volatility, oscillator-niveau, verkoopdruk, window-metrics):
   scheidt winst/verlies binnen de 30/31-trades niet. 4× bevestigd op 2-4 munten, opnieuw op 11.
2. **Consensus** — koop alleen waar 30 én 31 samen vuren (p=0,45), of 30/31 + een 20-23-rule (stort in op
   holdout), of een bevestigende vervolg-fire binnen 5 min (p=0,19). 30/31 delen het grootste deel van hun
   subregels en zitten juist in de witte ruimte → te weinig echte samenval.
3. **Meta-labeling** (López de Prado: 30/31 als primair signaal, een secundair model voorspelt of het een
   winnaar wordt) met exogene forward-features (fire-dichtheid, tijd-sinds-regimewissel, uur/dag, mede-vurende
   rules): beste variant na Šidák p=0,077 — niet significant. (5e bevestiging van de muur.)
4. **Volatiliteits-regimepoort via Hidden Markov Model**: scheidt prachtig in-sample (0,56% vs −0,15%), **nul
   out-of-sample** — klassiek vastpinnen op toeval.
5. **Leeftijds/winst-ladder strakker** (verliezers eerder afkappen): flipt méér winnaars dan het verliezers
   redt (de goede trades dippen óók eerst). p ≥ 0,17.
6. **Winst-lock-trappen (hp6/hp7) aanpassen**: kleine, niet-significante effecten (p ≥ 0,11).
7. **Loser-munten wegsnijden**: de winst-lock maakt verliezers al goedkoop → concentreren op winnaars werkt,
   wegsnijden niet.
8. **`min_volume` of een ander muntkenmerk als voorspeller** voor welke munten 30/31 werken: geen significante
   samenhang (n=11 te klein; p_perm = 0,17).
9. **Positie-sizing (Kelly) / conforme voorspelling**: hangen allemaal op een werkend voorspel-model dat (zie
   3 en 4) niet bestaat.

**Selectieve inzet** (geen verbetering van de rule zelf, wel een gebruiks-strategie): 30/31 alléén aanzetten op
de munten waar ze bewezen werken (NOS, DOGEAI) haalt +0,86%/trade — maar dat zijn precies de munten waaróp ze
ontdekt zijn (een confound), dus het is geen overdraagbaar kenmerk voor nieuwe munten.

## 11. De hoeken die we (nog) NIET volledig hebben onderzocht

1. **Een NÁ-instap uitkomst-as.** Alle afgeschoten filters keken naar informatie vóór instap, terwijl de
   uitkomst ná instap wordt bepaald. Een signaal dat zich in de **eerste X minuten ná instap** ontvouwt (bv.
   de vroege prijs-richting) als **vroege-exit-conditie**, los van de vaste verkoop-ladder — conceptueel het
   enige echt onverkende gat. Nog niet getoetst.
2. **Confound-breker.** 30/31 opnieuw ontdekken op de 9 munten waarop ze NIET ontdekt zijn, om te zien of de
   "werkt op NOS/DOGEAI"-bevinding een echt muntkenmerk is of puur de ontdekmunten. (Vergt de discovery-engine,
   die de database muteert.)
3. **Bodem 0,993–0,996 fijnmazig uittesten** — het stop-bodem-effect loopt monotoon door voorbij de geteste
   band; daar begint de flip-kost pas serieus op te lopen. Niet fijnmazig gemeten.

---

## 12. Vragen aan de reviewer (tweede LLM)

We zoeken nieuwe, concrete, **toetsbare** insteken om ~50% minder slechte 30/31-trades te krijgen met behoud
van de upside. Graag per suggestie: de hypothese, hoe je het read-only zou toetsen (met holdout + toeval-toets),
en het verwachte effect.

1. **Wat is de grootste blinde vlek in onze aanpak?** Gegeven dat instap-features de uitkomst niet voorspellen
   (5× bevestigd) — is onze conclusie "30/31 zijn structureel begrensd" terecht, of zit er een denkfout /
   ongeprobeerde categorie in?
2. **De NÁ-instap-richting (hoek 1).** Is een dynamische vroege-exit op basis van de eerste minuten prijs-/
   volume-gedrag ná instap een kansrijke route om verliezers te halveren zonder winnaars te raken? Hoe zou je
   die conditie definiëren en valideren zonder vooruit te kijken (geen look-ahead)? Welke valkuilen?
3. **Trade-management i.p.v. instap-selectie.** We hebben de winst-lock en de stop-bodem afgesteld. Zijn er
   exit-/risk-management-technieken die we missen (bv. tijd-gebaseerde scratch-exit, break-even-stop na X%,
   gedeeltelijk afbouwen, asymmetrische stops) die specifiek loser-zware rules redden?
4. **Meta-labeling — deden we het goed?** We probeerden meta-labeling met exogene features (faalde na Šidák).
   Is er een betere feature-set, label-definitie (triple-barrier?), of model-opzet die we hadden moeten
   gebruiken? Of bevestigt onze uitkomst dat meta-labeling hier niet kan werken (omdat de informatie simpelweg
   niet vóór/op instap bestaat)?
5. **Is "50% minder slechte trades" überhaupt het juiste doel?** Gegeven dat de winst geconcentreerd zit in
   ~8% overlap-trades en de pure massa dun is — is er een herformulering van het doel (bv. positie-sizing op
   signaal-kwaliteit, of 30/31 puur als "vroege instap"-laag bovenop 20-23) die economisch zinvoller is dan
   verliezers wegfilteren?
6. **Statistische opzet.** Is onze lat (toeval-toets + Šidák + tijd-holdout + CPCV/PBO) streng genoeg, of juist
   zó streng dat we echte zwakke-maar-reële edges wegmoffelen op een universe van maar 11 munten? Hoe zou jij
   power vs. vals-positieven afwegen bij dit aantal munten?
7. **Wat zou je als éérste doen** als je deze twee rules naar live-waardig moest tillen, en waarom?

> Context die de reviewer mag aannemen: alle metingen zijn read-only mogelijk op een MySQL-database met
> `coin_fires` (trades), `indicators` (de 5 reeksen), `rules` (subregel-definities), `coin_regime`
> (actieve periodes). De engine is Python; de sell-uitkomst is in-memory herberekenbaar zonder de hele
> historie te refiren. We kunnen elk concreet voorstel toetsen — geef vooral de hypothese + de toets.
