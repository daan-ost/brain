# De regime-gate — wanneer een munt actief of op pauze

> Wanneer handelen we een munt, en wanneer zetten we 'm op pauze? De regime-gate beantwoordt dat
> automatisch. Dit document is de volledige uitleg; de korte kaart staat in de skill
> [[brain-regime-gate]], de plannen in [[docs/epics/epic-G-coin-regime-gate.md]] (de gate) en
> [[docs/epics/epic-H-regime-apply.md]] (overal toepassen).

## 1. Het idee

Een munt heeft een leven: opkomst (veel goede trades), afkoeling (overwegend verliezers). **Op tijd
stoppen met een aflopende munt is een van de successen van de bot** — je wilt liever roteren naar een
kansrijke munt dan doorsudderen met iets wat afloopt. De regime-gate bepaalt per munt of die **actief**
is (we traden) of **inactief** (pauze).

Belangrijk onderscheid: de gate gaat over **wanneer** we in de markt zitten, niet over **welke** trades
we maken als we in de markt zitten (dat is de buy/sell-engine). Het zijn twee aparte lagen.

## 2. Het signaal: het rollende trade-resultaat

De gate kijkt naar het **gerealiseerde** trade-resultaat (de echte `profit_loss` uit de sell-engine),
opgeteld over de **laatste 4 weken** (≈ 1 maand). Niet naar kansrijk of beweeglijkheid — die zijn
gemeten als context, maar bleken het verkeerde signaal:

- Voor FARTCOIN kantelt het resultaat netjes mee met de kansrijk-daling.
- Maar **MUMU** is de harde casus: zijn kansrijk (9–16) én beweeglijkheid (~0,3) bleven het hele jaar
  ongeveer gelijk en zagen de omslag **niet**. Alleen het gerealiseerde resultaat kantelde (naar −51%
  in september '25). Daarom is het **trade-resultaat** het signaal, niet de prijs-maten.

De lat is **20% per maand**: een maand (of rollend venster) met minder dan ~20% is na slippage geen
echte winst (je koopt iets later/hoger, verkoopt iets later/lager). Onder die lat is doorgaan zonde.

## 3. De gate-logica (v2, met demping)

```
rollend = Σ profit_loss over de laatste 4 weken
UIT  : na 2 weken aaneen met rollend < 20%      (snel stoppen — op tijd eruit)
AAN  : na 3 weken aaneen met rollend ≥ 30%      (traag + hogere lat — niet op een blip)
start: pas bij de eerste week mét trades
```

De **demping** zit in twee dingen: (1) de "X weken aaneen"-bevestiging tegen geflikker, en (2) de
**hogere herstart-lat** (30%) dan de stop-lat (20%). De band 20–30% is "plakkerig": daarbinnen blijft de
status staan. Dit is bewust **asymmetrisch — snel uit, traag aan**, want doorsudderen kost meer dan een
gemiste late opleving.

Voorbeeld van het nut van die asymmetrie: MUMU's mei-'25 was +21% (één losse maand, daarna weer
negatief). Met de herstart-lat op 30% haalt die +21% het net niet → MUMU blijft terecht uit. NOS'
augustus-'24 was +50% (een echte spike) → die haalt 30% wél → NOS gaat terecht weer aan.

Code: `www/app/Livewire/Coins/Weekly.php` (`applyGate` + de `GATE_*`-constants). Zichtbaar als de
groen/rode streep bovenaan elke munt op `/coins/weekly`, met datum-labels op de omslagweken.

## 4. De benchmark (ground truth) + cadans-keuze

`engine/data/regime_benchmark.json` legt Daans **handmatige ideale aan/uit per munt** vast (met
`hard`/`soft`-zekerheid). Dat is het **ijkpunt** waartegen we elke automatische variant scoren — niet
de motor zelf. De scoring-filosofie: **te laat stoppen telt zwaarder dan een gemiste late opleving.**

**Cadans getest** (`engine/src/regime_backtest.py`): dezelfde gate op dagelijks, 3-daags en wekelijks,
leak-vrij gescoord tegen de benchmark. **Wekelijks wint** (94,1% overeenkomst, minste doorsudderen).
Contra-intuïtief is dagelijks het slechtst: het rollende signaal schommelt dag-op-dag rond de lat,
waardoor de "X keer zwak op rij"-teller telkens reset en het stoppen juist vertraagt. Wekelijks
bemonsteren middelt die ruis weg.

## 5. Statistische validatie — de gate doorstaat de toetsen

`engine/src/regime_validate.py` toetst de gate tegen de benchmark op vier disciplines (zie
[[docs/methodology/rule-discovery.md]] §4b). Uitkomst 2026-06-25:

| toets | wat het vraagt | uitkomst |
|---|---|---|
| **Nullijn** | verslaat de gate "nooit stoppen"? | gate 94,3% vs altijd-aan 44,8% → **+39 punten** |
| **Toeval-toets** | is de match geen toeval? (3000× resultaten geschud) | alle munten **p ≤ 0,009** |
| **Apart-gehouden testperiode** | werkt het ook op de late helft? | vroeg ≈ laat → stabiel |
| **Munt-eruit-laten** | drempels van 3 munten op de 4e | 90–98% → overdraagbaar |

De gate "weet wanneer te stoppen", is significant, stabiel in de tijd en overdraagbaar — geen toeval,
geen overfit op deze vier munten. **Kanttekening:** 4 munten + een deels handmatige benchmark = sterk
bewijs, geen absolute zekerheid. Bij nieuwe munten: benchmark aanvullen en `regime_validate.py` opnieuw
draaien.

## 6. Backtest → live: het herstart-signaal

In de backtest bestaan er trades over de héle periode (de oude bot handelde door), dus de gate kan
herstart baseren op echt resultaat — óók tijdens uit-perioden. **Live geldt dat niet:** zodra een munt
op inactief staat, handel je niet → je krijgt geen nieuwe trade-resultaten → het echte resultaat kan
nooit meer herstellen. Dan zou een uitgezette munt nooit meer aangaan.

De oplossing leunt op wat de engine sowieso al doet: **de indicatoren blijven binnenkomen voor élke
munt** (ook inactieve). Op een inactieve munt laat de engine een **schaduw-trade** simuleren (de
sell-engine zonder echt geld → `coin_moment_sells`). Dat gesimuleerde resultaat voedt het
**herstart**-oordeel. Dus live wordt het asymmetrisch:

- **Stoppen** op het **echte** trade-resultaat (je handelt, je ziet de uitkomst).
- **Herstarten** op het **schaduw-/indicatorsignaal** (je handelt niet, maar simuleert).

Alles **leak-vrij**: elke beslissing op moment T gebruikt alleen data van vóór T.

## 7. Toepassen: de actieve-periode-filter (epic-H)

Zodra de gate de actieve perioden bepaalt, moet de rest van het systeem daar rekening mee houden.
Trades uit een **inactieve** periode tellen **standaard niet mee**:

- **Opslag:** de routine `coin-regime` (wekelijks) schrijft de perioden per munt naar de tabel
  `coin_regime` (+ JSON-export `engine/data/coin_regime.json`).
- **Optimalisatie:** het filter zit in `opt_lib.load_trades()` / `load_all_fires()` (+ de eigen loaders
  in `sell_tuning.py`, `subrule_power.py`, `gate_window.py`). De buy/sell-rule-routines tunen daardoor
  alleen op trades uit actieve perioden — niet op trades die we nooit gemaakt zouden hebben.
- **Schermen:** `Trades/Index.php` + `CoinExplorer.php` tonen default alleen actieve trades (toggle voor
  de rest) en zetten Σ-actief naast Σ-alles, zodat de winst van het stoppen zichtbaar is. `/coins/weekly`
  toont juist de volledige historie — dat is de kalibratie-bril.

**Feedback-lus, let op de volgorde:** het regime hangt af van trades, en de rules hangen (na filter) af
van het regime. De discipline is: trades → regime berekenen → rules tunen op actieve trades → re-fire →
regime herberekenen. Zie [[brain-routines]].

## 8. Beperkingen

- **Vier munten + deels-soft benchmark.** De drempels (20/30, 4-weeks venster) zijn afgesteld en
  gevalideerd op een klein universe. Sterk, niet absoluut — herbevestig met meer munten.
- **Het signaal loopt synchroon, niet vooruit.** De gate reageert (bewust) met enige vertraging; dat is
  prima omdat verlies-regimes hier maanden duren, maar het vangt geen plotse omslag binnen dagen.
- **Dagelijkse cadans** vraagt een ruis-bestendiger demping (bijv. "X van laatste Y dagen") vóór die
  competitief is; nu is wekelijks de keuze.
- **Gate ≠ rule-kwaliteit.** De gate zegt wanneer je in de markt zit; de rules bepalen welke trades. Het
  filter koppelt ze, maar het blijven aparte lagen.
