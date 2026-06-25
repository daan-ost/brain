# EPIC G: Munt aan/uit — de regime-gate (stoplicht)

> **Status:** Plan (nog niets gebouwd). Vervolg op [epic-05](epic-05-coin-volatility-gating.md) (oorspronkelijke
> gating-spec) en [epic-V](epic-V-coin-volatility-flag.md) (gebouwd: kansrijkheid-ranking + `coin_daily_metrics`).
> Bouwt voort op de findings [coin-volatiliteit-stoplicht-2026-06-17](../findings/coin-volatiliteit-stoplicht-2026-06-17.md).

## Epic Specification

Bouw een mechanisme dat **per munt automatisch bepaalt of we hem nú moeten traden of op pauze zetten**, op basis
van de cijfers die we al berekenen: de kansrijkheid (% momenten met ≥3% stijging binnen 1u), de beweeglijkheid
(spreiding van de 1-min koersbewegingen) en — nieuw als eerste-klas signaal — het **gerealiseerde trade-resultaat**
over een meelopend venster. Met **regime** bedoelen we een langere fase waarin een munt zich anders gedraagt:
opkomst (veel goede trades) versus afkoeling (overwegend verliezers). De gate zet een munt op **uit** wanneer hij
in een verlies-regime zit en weer op **aan** wanneer hij herleeft, met **demping** zodat de status niet dag-op-dag
heen-en-weer flikkert. We maken het eerst alleen **zichtbaar** (rode/groene streep in `/coins/weekly`), tunen dan
de regel-mix met statistische discipline, en laten het pas dáárna echt meebeslissen in de (nog te bouwen) live-laag.

## Rationale

63% van álle herbouwde trades is een verliezer en bijna een derde van alle trade-activiteit zit in regimes die
**netto geld kosten**. Twee duidelijk-dode staarten alleen al — MUMU vanaf juni 2025 en FARTCOIN vanaf april 2025 —
zijn samen **1.769 trades (28% van alles), 1.226 verliezers, en −212% aan Σwinst**. Die trades hadden we niet
hoeven maken. We missen daarmee een paar goede trades, maar belangrijker: we vermijden een enorme berg slechte.
In een model met beperkte gelijktijdige posities is het nóg sterker — een dode munt die de positie-plek bezet houdt,
blokkeert een goede trade op een levende munt. Dit is precies Daans rotatie-idee: een vals alarm is goedkoop zolang
er een beter alternatief is.

## Dependencies

- `coin_daily_metrics` (gevuld door routine `coin-metrics`) — levert kansrijk (`up_pct`/`up_7d`) + beweeglijkheid (`vol_pct`/`vol_7d`) per dag/munt. **Aanwezig.**
- `coin_fires` (executed trades, `profit_loss`) — het evaluatie-doel + de trade-resultaat-input. **Aanwezig.**
- Het routine-raamwerk (`engine/src/routines.py`, ongegate set zoals `coin-metrics`). **Aanwezig.**
- De methodologie-discipline uit [docs/methodology/rule-discovery.md](../methodology/rule-discovery.md) §4b (toeval-toets, apart-gehouden testperiode). **Aanwezig.**
- De live-laag (welke munten/regels actief zijn per moment) — **nog niet gebouwd**; Fase 4 sluit daarop aan.

## Wat we al weten (en wat nu anders is)

Uit de findings van 17-06-2026 (READ-ONLY, niets toegepast):

1. **De kansrijkheid-/beweeglijkheidsmaat is goed meetbaar op ruwe data en leak-vrij**, en correleert met
   winst-per-trade (DOGEAI 0,83 / 0,94; NOS 0,50).
2. **Het signaal loopt synchroon met de afkoeling — het voorspelt niet weken vooruit.**
3. **Een per-minuut aan/uit-gate per trade loont NIET**: de winst-lock maakt verliezers al goedkoop en winnaars
   duur, dus een onvolmaakt instap-filter kost altijd meer aan gemiste winnaars dan het bespaart. → **Dat doen we
   hier expliciet niet.**
4. **Het stoplicht flikkert** als je het zelf-relatief meet → demping/hysterese nodig (Daans "na X dagen").
5. **De #1 blocker was data**: DOGEAI en NOS leefden niet gelijktijdig, dus rotatie was niet te bewijzen.

**Wat nú anders is — de blocker is opgeheven.** We hebben 4 munten, waarvan er meerdere **gelijktijdig leven**:
MUMU + FARTCOIN overlappen okt 2024 → nov 2025 (13 maanden), met DOGEAI erbij feb–jul 2025. Voor het eerst is er een
periode waarin er écht iets te kiezen viel → rotatie/gating is nu toetsbaar.

**Waarom regime-gating kán werken waar de per-trade-gate faalde:**

- **Grovere korrel.** We beslissen per week/regime, niet per trade. Een verlies-regime duurt hier **maanden**
  (MUMU bloedt 6 maanden aaneen). Een synchroon/achterlopend signaal is dan goed genoeg: zelfs 2–4 weken te laat
  reageren bespaart het overgrote deel van de schade, omdat de fase zo lang aanhoudt.
- **Ander doel.** Niet "win-kans per trade verbeteren" (waar de winst-lock je tegenwerkt), maar "een aanhoudend
  verlies-regime verlaten". In zo'n regime zijn er tóch weinig winnaars → de gemiste-winst-kost is laag.
- **Nieuw signaal: het trade-resultaat zelf.** Zie hieronder — voor MUMU is dit het enige signaal dat werkt.

## Het bewijs in de data

Per maand per munt, de trade-uitkomst náást de prijs-signalen (executed trades, `profit_loss`):

| munt | maand-reeks | kansrijk | beweeg | Σwinst-patroon |
|---|---|---|---|---|
| **FARTCOIN** | nov'24→mrt'25 | 47→22 | 0,71→0,36 | +109…+27 (positief, dalend) |
| | **apr'25→sep'25** | **10→2** | **0,26→0,15** | **−2,+1,+2,−8,−20,−14 (dood)** |
| **MUMU** | jul'24→jan'25 | 32→16 | 0,53→0,34 | +80…+1 (positief, dalend) |
| | **jun'25→nov'25** | **12→9** | **0,37→0,35** | **−18,−17,−29,−51,−28,−27 (bloedt)** |
| **DOGEAI** | feb'25→jul'25 | 47→15 | 1,07→0,56 | +464…+5 (altijd positief, afzwakkend) |
| **NOS** | nov'23→jan'25 | 32→… wisselt | 0,55→0,17 | gemengd; dode maanden bij kansrijk <5 |

**De sleutelbevinding — geen enkel signaal volstaat alléén:**

- **FARTCOIN** kantelt precies wanneer **kansrijk onder ~10** zakt (apr'25). Prijs-signaal werkt hier.
- **MUMU is de harde casus:** zijn kansrijk (9–16) én beweeglijkheid (~0,3) blijven het hele jaar **ongeveer
  gelijk** — ze zien de omslag níét. Alleen het **gerealiseerde resultaat** kantelt (positief → −51 in sep'25).
  MUMU's kansrijk loopt zelfs ~2 maanden áchter op de resultaat-omslag.
- **DOGEAI** blijft altijd boven kansrijk 12 en altijd (krap) positief → een te strenge gate zou hem onterecht
  uitzetten. Dit is de "mixed"-casus: hooguit vanaf juli afbouwen.

**Conclusie:** de gate moet een **mix** zijn van (a) prijs-signaal (kansrijk/beweeglijkheid — vangt FARTCOIN/DOGEAI/NOS)
en (b) trade-resultaat (vangt MUMU). Precies Daans intuïtie. En de drempels zijn **niet één-op-één overdraagbaar
tussen munten** (NOS speelt op een lager niveau) → de gate werkt het robuust als hij deels **zelf-relatief** of
**rang-gebaseerd** is, niet met één absolute drempel voor alles.

## Benchmark — de ideale aan/uit-perioden (ground truth)

Vastgelegd 2026-06-25 in [`engine/data/regime_benchmark.json`](../../engine/data/regime_benchmark.json). Dit is
Daans handmatige oordeel over wanneer elke munt idealiter aan/uit had moeten staan. **Elke automatische methode
scoren we hiertegen** — de getallen bepalen de beste methode, niet onderbuik.

| munt | ideaal AAN | ideaal UIT |
|---|---|---|
| **MUMU** | jul 2024 → half dec 2024 | vanaf ~15 dec 2024, **niet meer starten** (mei '25 +21 = losse maand) |
| **DOGEAI** | feb → jun 2025 | vanaf jul 2025 |
| **FARTCOIN** | nov 2024 → mrt 2025 | vanaf apr 2025, uit blijven |
| **NOS** (lastig) | **nov 2023 – feb 2024 (belangrijkst)**, mrt–apr (overgang), evt aug '24, dec '24 | mei–jul '24, sep–okt '24, (nov '24), jan '25 |

`confidence: hard` = zeker; `soft` = Daans nuance/twijfel (telt lichter mee bij scoren). NOS heeft losse winst-spikes
(aug +50, dec +36) midden in dode periodes — dat maakt 'm het lastigst en het scherpst om een methode op te testen.

**Scoring-filosofie (uit Daans woorden):** *op tijd stoppen weegt zwaarder dan een late goede week meepakken* —
liever roteren naar een kansrijke munt dan doorsudderen met iets wat afloopt. De score straft dus **te laat
stoppen** (doorsudderen in de afloop) zwaarder dan een **gemiste late spike**.

**Cadans-keuze — GETEST (2026-06-25), wekelijks wint.** `engine/src/regime_backtest.py` draait dezelfde gate
leak-vrij op 3 cadansen en scoort tegen de benchmark (late-stop telt 2×, gemist 1×, flikker-straf):

| cadans | overeenkomst | te-laat-door | gemist | schakels | strafscore |
|---|---|---|---|---|---|
| dagelijks | 93,2% | 62 | 28 | 5 | 154,5 |
| 3-daags | 93,6% | 41 | 44 | 5 | 128,7 |
| **wekelijks** | **94,1%** | **34** | 44 | 6 | **115,8** ← beste |

Wekelijks wint op rauwe overeenkomst én op doorsudderen. Contra-intuïtief is **dagelijks het slechtst**: het
rollende resultaat schommelt dag-op-dag rond de lat, waardoor de "X keer zwak op rij"-teller telkens reset en het
stoppen juist vertraagt (meer doorsudderen). Wekelijks bemonsteren middelt die ruis weg. Kanttekening: 4 munten +
deels-soft benchmark = indicatief. "Dagelijks slecht" geldt voor déze demping-regel; een ruis-bestendiger variant
("X van laatste Y dagen" / gladgestreken signaal) kan dagelijks later alsnog competitief maken.

→ **We bouwen de live-routine op wekelijkse cadans** (sluit aan op `/coins/weekly`), dagelijks-met-smoothing is een
latere optie wanneer er meer munten zijn.

## Beslissingen

| # | Vraag | Beslissing |
|---|---|---|
| 1 | Per-trade gate of regime-gate? | **Regime-gate** (week-korrel). Per-trade is bewezen niet-lonend. |
| 2 | Welke inputs? | Kansrijk (rollend 24u + 7d), beweeglijkheid (7d), **gerealiseerd trade-resultaat** (rollend venster). |
| 3 | Hoe flikker voorkomen? | **Demping/hysterese**: aparte aan- en uit-drempels + minimaal X dagen in een richting vóór de status omklapt. |
| 4 | Eerst tonen of meteen toepassen? | **Eerst tonen** (rode/groene streep in `/coins/weekly`), niets live. |
| 5 | Statistische discipline? | Verplicht: vóór-vastgelegde hypothese, apart-gehouden testperiode (tijd), munt-eruit-laten-toets, **toeval-toets** (p<0,05). |
| 6 | Waar de status opslaan? | **Nieuwe tabel `coin_regime`** (per munt per dag: status + de signaalwaarden + reden). Niet in `coin_strategies` (dat is sell-override per regel). |
| 7 | Doel-maat? | Primair: **minder verliezers** met behoud van Σwinst. De gemiste goede trades expliciet rapporteren als kost. |
| 8 | Posities-model | **Eén globale positie** (Daan, 2026-06-25): één trade tegelijk over álle munten. Een dode munt die de plek bezet houdt blokkeert dus een goede trade elders → de gate-/rotatie-winst is groot, rotatie is cruciaal. |

## Aanpak in fasen

### Fase 1 — Zichtbaar maken (de rode/groene streep)
Read-only. Bereken voor elke munt per week een **kandidaat-aan/uit-status** uit een eerste, met-de-hand-gekozen
regel (v0, zie hieronder) en toon die als een derde strook onder de twee heatmap-rijen in `/coins/weekly`:
groen = aan, rood = uit. Doel: Daan kalibreert visueel tegen zijn eigen oordeel (MUMU uit vanaf ~jan'25,
FARTCOIN vanaf ~apr'25, NOS gemengd, DOGEAI evt. vanaf jul'25). Dit is een **terugtoets (backtest) overlay**,
nog geen beslissing. Goedkoopste stap met de meeste leerwinst.

### Fase 2 — Statistische toetsing — UITGEVOERD (2026-06-25): gate doorstaat alles
`engine/src/regime_validate.py` toetst de gate tegen de benchmark op vier disciplines. Uitkomst:

| toets | resultaat |
|---|---|
| **Nullijn** (verslaat "nooit stoppen"?) | gate 94,3% vs altijd-aan 44,8% / altijd-uit 55,2% → **+39 punten** |
| **Toeval-toets** (3000× resultaten geschud) | alle munten **p ≤ 0,009** → géén toeval |
| **Apart-gehouden testperiode** (vroeg 70% vs laat 30%) | stabiel (vroeg ≈ laat) op alle munten |
| **Munt-eruit-laten** (drempels op 3, toets op 4e) | 90–98% op de ongeziene munt → overdraagbaar, niet munt-specifiek |

De gate "weet wanneer te stoppen", is significant, stabiel in de tijd en overdraagbaar. Kanttekening: 4 munten +
deels-soft benchmark = sterk bewijs, geen absolute zekerheid → herbevestigen met meer munten. Bijvangst: herstart-lat
mag iets lager (25 i.p.v. 30, binnen de ruis — 20/30 blijft staan). **Cadans-test** (`regime_backtest.py`):
wekelijks > 3-daags > dagelijks (zie boven).

**Critical-eye-follow-up (2026-06-25) — twee niet-circulaire bevestigingen bovenop de benchmark:**
- **P1 — economische waarde mét slippage** (`regime_economics.py`): bij 0,4%/trade is álles verhandelen een
  **verliesstrategie** (Σ −378% onbeperkt / −337% één-positie); de actieve-periode-filter maakt er **+618% / +577%**
  van. De gate is dus het verschil tussen verlies en winst — de vlakke ruwe Σ verborg dat (slippage op duizenden
  marginale trades is de killer). KANTTEKENING: actieve-set komt van de volledige-historie-gate → herstart
  backtest-optimistisch (P3 open).
- **P2 — vooruit-voorspellend, niet-circulair** (`regime_forward.py`): gate-stand op T (alleen verleden) voorspelt
  het resultaat ná T. AAN → volgende week +22,7% / 4 wkn +67,4%; UIT → +0,3% / +1,0% (na slippage netto verlies).
  p=0,000, alle 4 munten dezelfde richting. Demping voegt +5 toe t.o.v. een naïeve drempel.

**Volgende: operationaliseren + overal toepassen → [epic-H](epic-H-regime-apply.md).** Open critical-eye: P3
(schaduw-herstart in backtest), P5/P6 (knoppen bevriezen, meer munten, schone holdout), P7 (gevoeligheid gewichten).

### Fase 3 — Routine (schaduw, nog niet traden)
Nieuwe ongegate routine (zoals `coin-metrics`) die per munt de status berekent en in `coin_regime` zet, mét demping.
Eerst puur **registreren + tonen** (de streep wordt "echt"), zonder iets te blokkeren, om vertrouwen op te bouwen.
Cadans = **wekelijks** (getest als beste, zie Fase 2).

**Leak-vrij + het herstart-signaal (door Daan bevestigd 2026-06-25) — het wezenlijke backtest→live-verschil:**
- Op elk beslismoment alleen **verleden-data** (rollend venster t/m nu); nooit vooruitkijken.
- In de backtest bestaan er trades over de héle periode (de oude bot handelde door), dus de gate kan herstart
  baseren op echte resultaten — óók tijdens "uit"-perioden. **Live niet:** zodra een munt op INACTIEF staat,
  handel je niet → geen nieuwe trade-resultaten → het echte resultaat kan nooit herstellen.
- Oplossing: de indicatoren blijven binnenkomen voor élke munt (ook inactieve). Op een inactieve munt laat de
  engine een **schaduw-trade simuleren** (sell-engine, geen echt geld → `coin_moment_sells`). Dát gesimuleerde
  resultaat voedt het **herstart**-oordeel. Dus asymmetrisch: **stoppen op echt resultaat, herstarten op het
  schaduw-/indicator-signaal.** De motor levert beide al dagelijks (`coin_daily_metrics` + de sell-simulatie).

### Fase 4 — Echt laten beslissen (sluit aan op de live-laag)
Pas wanneer de live-laag bestaat: de `coin_regime`-status bepaalt mee welke munten actief traden. In rotatie-vorm:
rangschik de levende munten op absolute beweeglijkheid/kansrijkheid, handel de top-N, pauzeer de rest, herzie
wekelijks. Dit is het keuzemenu-idee uit de rule-discovery-filosofie, maar dan voor munten i.p.v. regels.

## Kandidaat-regel v0 (startpunt voor Fase 1 — expliciet nog niet "de" regel)

```
UIT  als   (kansrijk_7d < 8)
      OF   (rollend 30-daags Σprofit < 0  gedurende ≥ 10 dagen)
AAN  als   (kansrijk_7d > 12  gedurende ≥ 7 dagen)
      EN   (rollend 30-daags Σprofit ≥ 0)
demping:   status klapt pas om na X dagen aaneen in de nieuwe richting (X≈7).
```

Dit is bewust simpel en met de hand gekozen op de tabel hierboven (kansrijk ~8–10 als breuk; resultaat-venster
voor de MUMU-casus die kansrijk mist). Fase 2 vervangt de getallen door getoetste waarden.

## Fase 1 — uitgevoerd (2026-06-25): bevindingen

Gebouwd: status-streep (groen/rood) bovenaan elke munt in `/coins/weekly`, met v0-gate als constants in
`Weekly.php`. Read-only, blokkeert niets.

- **Bug gevonden + gefixt:** de wekelijkse trade-som groepeerde op alias `id`, wat botste met de kolom
  `coin_fires.id` → MySQL groepeerde op de primaire sleutel → één trade per week i.p.v. de hele week. De gate
  liep dus eerst op ruis. Na de fix kloppen de weekresultaten met de DB.
- **Gecorrigeerde uitkomst v0** (kansrijk-off 8 / on 12, resultaat-venster 4w, demping 2w):
  - **NOS:** aan t/m maart 2024, uit vanaf 25-03 (klopt met Daans oordeel; de kansrijk-dip in jan wordt
    terecht genegeerd door de demping + sterk positief weekresultaat).
  - **FARTCOIN:** uit vanaf 28-04-2025 (−24% vermeden, 470 verliezers eruit). Klopt met "vanaf april".
  - **MUMU:** uit vanaf 02-06-2025 (−170% vermeden, 815 verliezers eruit). **Later dan Daans "januari"** —
    want jan–mei was nog net positief (mei +21); juni is de echte omslag. Gate volgt het geld, niet het oog.
  - **DOGEAI:** nooit uit (was altijd winstgevend; kansrijk >12, resultaat >0). Daans "evt. vanaf juli" niet
    nodig — juli was nog +5.
- **Zichtbaar gemaakte spanning (= Fase 2-werk):** NOS' uit-periode bevat +139% "gemiste winst" (losse spikes
  aug/dec 2024 in een verder dode munt). Per-munt lijkt dat te streng; bij één globale positie is het oké
  (sta dan op een levendere munt). Dit is precies waarom de aan/uit-beslissing rotatie-bewust moet (Fase 4) en
  de drempels deels zelf-relatief (Fase 2).

## Nieuwe bestanden aan te maken

| Bestand | Type | Fase |
|---|---|---|
| `engine/src/coin_regime.py` | regime-status berekenen (signalen + demping) | 1–3 |
| `engine/src/regime_backtest.py` | terugtoets + apart-gehouden testperiode + toeval-toets | 2 |
| migratie `coin_regime` tabel | per munt per dag: status, kansrijk_7d, beweeg_7d, result_30d, reden | 3 |
| routine `coin-regime` in `routines.py` | dagelijkse ongegate set | 3 |
| `www/.../coins/weekly.blade.php` (wijziging) | derde strook: groen/rood per week | 1 |
| `engine/tests/test_coin_regime.py` | demping, leak-vrij, drempel-logica | 1–3 |

## Niet in scope

- Een **per-minuut/per-trade instap-gate** (bewezen niet-lonend bij de huidige sell-engine).
- Een **vooruitkijkend** signaal forceren (het signaal is synchroon; we omarmen de korte vertraging i.p.v. te doen
  alsof we de toekomst zien).
- De **live-uitvoering** zelf (Fase 4 wacht op de live-laag).
- Nieuwe koop-/verkoop-regels — dit gaat puur over munt aan/uit, niet over welke regels binnen een munt vuren.

## Open vragen voor Daan

1. ~~Posities-model~~ → beslist: **één globale positie** (zie beslissing #8).
2. **Stoppen = cash of roteren?** Pure pauze (geld eruit) of altijd doorrollen naar de beste levende munt?
   (Bij één globale positie ligt doorrollen voor de hand.)
3. **Week- of dag-korrel** voor de status (de streep)? Week sluit aan op `/coins/weekly`; dag is fijnmaziger maar
   flikkert eerder. Fase 1 bouwt week-korrel.
