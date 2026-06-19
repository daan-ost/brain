# Wanneer een coin op pauze? Volatiliteit, een aan/uit-gate, en het stoplicht/rotatie-idee

**Datum:** 2026-06-17
**Scope:** de twee brain-coins DOGEAI (2525, feb–jul 2025) + NOS (244, nov 2023–jan 2025). READ-ONLY —
er is **niets toegepast** aan rules/engine. Drie samenhangende onderzoeken + een strategische denkrichting.
**Artefacten:**
- `engine/src/coin_activity.py`, `coin_activity_daily.py` — ruwe-data signalen per maand/dag.
- `engine/src/coin_stop_backtest.py` — maand-pauze backtest.
- `engine/src/gate_window.py`, `gate_eval.py`, `gate_pnl.py`, `gate_pnl_label.py` — korte-termijn aan/uit-gate.
- `engine/src/coin_stoplicht.py` — het groen/oranje/rood-stoplicht op dagbasis.

---

## High-level vraag

We zoeken **volatile trades**. Een coin heeft een leven: opkomst (DOGEAI feb/mrt = top), afkoeling
(apr/mei = dood). Kunnen we op de RUWE coin-data (volume + prijs + alle indicatoren) een **alarmbel /
stoplicht** bouwen dat zegt "deze coin is afgekoeld, zet op pauze" — zodat je kunt **roteren** naar een
coin die nu in zijn opkomst zit, in plaats van te blijven hangen (maart +45%, april +0%)?

## Antwoord in één alinea

De **volatiliteit van een coin is goed en verklaarbaar meetbaar** op ruwe data, en is de juiste motor voor
zo'n stoplicht. Maar: (1) het signaal loopt **gelijk op** met de afkoeling, het voorspelt niet weken
vooruit; (2) een **per-minuut aan/uit-gate loont niet in echte winst** — de winst-lock maakt verliezers al
goedkoop; (3) het stoplicht is berekenbaar en springt netjes om rond de DOGEAI-afkoeling, maar **flikkert**
en wordt te vroeg oranje als je het absoluut/zelf-relatief meet. De grootste conclusie is strategisch:
**Daan's rotatie-frame is de juiste,** want het maakt een vals alarm goedkoop — maar het is met de huidige
data **niet te bewijzen**, omdat DOGEAI en NOS niet gelijktijdig leven. De echte volgende stap is daarom
**meer coins, gelijktijdig**, niet een slimmer signaal op deze twee.

---

## Onderzoek 1 — volatiliteit per coin (de "thermometer")

Per maand uit de volumeud-ticks: **beweeglijkheid** = % van de momenten waarop de prijs binnen het komende
uur nog ≥3% stijgt (leak-vrij t.o.v. de trade-selectie; meet de coin zelf, niet welke trades de rules kozen).

- **DOGEAI:** beweeglijkheid volgt de winst-per-trade sterk (Spearman **0,83**). Dieptepunt in mei (de dode
  maand, +0,6%), opleving in juni (+22,6%). De beweeglijkheid dáált al vanaf februari, net als de winst.
- **Twee soorten signaal:** beweeglijkheid → volgt de **winst per trade** (kwaliteit); aantal
  kandidaat-ticks/dag → volgt het **aantal trades** (kwantiteit), maar zegt niets over kwaliteit (bij NOS
  zelfs −0,04).
- **"Eerder zien?" Nee.** De vorige maand voorspelt de volgende niet; de beweeglijkheid loopt **synchroon**
  met de winst-omslag. Waarde zit in stabiliteit (28k ticks i.p.v. tientallen trades) en directheid, niet
  in vooruitzien.
- **NOS spreekt deels tegen.** juni 2024 (beweeglijkheid 5,5 → dood, −1,6%) vs juli 2024 (5,3 → goed,
  +11,2%): vrijwel gelijk signaal, tegengestelde uitkomst. Augustus 2024 maakte +40,5%, maar dat kwam
  vrijwel volledig van **één dag** (5 aug, +31,6%) in een verder rustige maand. Een absolute
  beweeglijkheidsdrempel die op DOGEAI werkt, snijdt op NOS ~80% van de winst weg.

**Les:** de drempel is niet overdraagbaar tussen coins (NOS speelt op een lager volatiliteitsniveau), en
een rustige coin kan via een enkele spike tóch winst geven.

## Onderzoek 2 — per-minuut aan/uit-gate (de "instap-filter")

Per instapmoment naar de laatste 15/30/45/60 min van **alle** indicatoren (incl. prijs) kijken, met
schaal-vrije vorm-features (helling, positie-in-range, choppiness, prijs-vs-volume). Doel: goede trades
behouden, deel van de slechte voorkomen.

- **Er is een echte, cross-coin gemene deler:** goede instap = de prijs beweegt al omhoog (stijgende
  helling, hoog in de range), niet chaotisch, en trekt mee met het volume (geen volume-oploop zonder
  prijsreactie). De sterkste, `div_pricevol_W45`, behoudt op **beide** coins >90% van de goede trades en
  voorkomt 17-29% van de slechte. Dit **overleeft de lek-check** (binnen-maand behoudt ~75% van het
  scheidend vermogen → geen vermomd maand-signaal) én een tijds-holdout.
- **Maar het loont niet in winst.** Tegen het échte doel (winnaars pl>0 vs verliezers pl<0) is de scheiding
  zwak (Cohen's d 0,3–0,45), en **élke** gate-instelling levert netto Σwinst in op beide coins. Oorzaak: de
  winst-lock maakt verliezers goedkoop (kleine min) en winnaars duur (grote plus), dus een onvolmaakt
  filter kost altijd meer aan gemiste winnaars dan het bespaart aan vermeden verlies. De minst slechte
  ("vermijd chaotische prijs in de laatste 30 min") was op NOS bijna gratis (−0,2) maar nog steeds negatief
  op DOGEAI (−22,8 voor 20 verliezers minder).

**Les:** de prijs-momentum-vorm is waardevol als **zachte score** (rangschikken / koop-bevestiging), niet
als harde blokkerende gate. Dit bevestigt langs een nieuwe weg (tijds-vensters i.p.v. rij-lookbacks)
dezelfde muur als de geparkeerde feature-discovery — met als nieuw inzicht dat de prijs-vorm wél cross-coin
overdraagt.

## Het stoplicht/rotatie-idee — gedachten + doorrekening

Het stoplicht (`coin_stoplicht.py`): 7-daags beweeglijkheid gedeeld door de eigen 30-daags piek. Groen
≥60%, oranje 40-60%, rood <40%.

**Wat blijkt op DOGEAI:** het stoplicht wordt **22 feb groen**, **13 maart oranje**, eind maart kort rood,
en flikkert daarna in april/mei tussen oranje en groen. Het springt dus netjes om rond de afkoeling. Twee
problemen:
1. **Het flikkert** (oranje→groen→oranje binnen dagen). Daan's "na 7 dagen" is precies de oplossing:
   demping/hysterese — pas oranje na X dagen onder de drempel, pas weer groen na X dagen erboven.
2. **Het wordt te vroeg oranje** (13 maart, terwijl maart +85% en april +45% maakten). Omdat de feb-piek
   extreem was, lijkt alles erna "laag". Een zelf-relatief stoplicht is dus goed voor "**niet meer top**",
   niet voor "stop helemaal".

**De kern (waarom rotatie het idee redt):** mijn bezwaar tegen "stoppen" in onderzoek 1 was de
opportunity-kost (je mist juni's opleving, NOS' augustus). In een **rotatie**-frame vervalt dat bezwaar: je
pauzeert niet om cash aan te houden, maar om naar een beweeglijker coin te gaan. Een vals alarm is dan
goedkoop — zolang er een beter alternatief is. "DOGEAI oranje vanaf maart" is in een rotatie-context geen
fout: het zegt "DOGEAI is niet meer top, kijk rond".

**Twee maten voor twee doelen:**
- **Zelf-relatief** (t.o.v. eigen piek): "koelt deze coin af?" → goed voor het *pauze*-signaal. Nadeel: een
  dode coin wordt weer "groen" t.o.v. zijn eigen lage piek (NOS, 18 mei 2024: groen op 100% van een piek
  van slechts 16).
- **Absoluut / tussen coins** (welke coin beweegt nu het meest): "waar moet ik heen?" → de juiste maat voor
  *rotatie*. Rangschik de levende coins op hun 7-daags beweeglijkheid, handel de top-N, pauzeer de rest,
  herzie wekelijks. Geen absolute per-coin drempel meer nodig — de ranking lost dat op.

---

## Conclusie & aanbevolen volgende stap

1. **Het stoplicht is bouwbaar en zinvol** als zacht, wekelijks signaal — mits met demping (Daan's 7 dagen)
   en bij voorkeur als **ranking tussen coins** (rotatie), niet als absolute drempel per coin.
2. **De motor is de beweeglijkheidsmaat** (% momenten met ≥X% stijging binnen 1u), die op pure ruwe data
   werkt en dus ook op een **verse coin vanaf dag 1** te berekenen is — dat is precies wat rotatie nodig
   heeft (een opkomende coin herkennen zonder trade-historie). Sluit aan op het `brain_volume_found` dag-1
   spoor.
3. **Niet doen: een per-minuut aan/uit winst-gate.** Bewezen niet lonend bij de huidige sell-engine.
4. **De #1 blocker is data, niet het signaal.** Rotatie is met DOGEAI + NOS niet te bewijzen — ze leven niet
   gelijktijdig. De duidelijke volgende stap is **meer coins, gelijktijdig in de tijd**, dan het
   rotatie-stoplicht (ranking op absolute beweeglijkheid + 7-daagse demping) backtesten over een periode
   waarin meerdere coins tegelijk te kiezen waren.

## Bekende beperkingen

- **Twee coins, niet-overlappend in tijd** — geen out-of-coin/gelijktijdige test mogelijk; alle conclusies
  zijn met die slag om de arm. Het rotatie-idee is nu een *concept met onderbouwde motor*, geen bewezen
  strategie.
- De beweeglijkheidsmaat kijkt 60 min vooruit binnen de periode; voor een pauze-beslissing aan het begin
  van periode X gebruik je data t/m X−1, dus geen lek naar de beslissing.
- Prijs-glitches (bv. DOGEAI-tick van 23044 bij een coin van ~0,02) zijn met een lokaal rolling-mediaan
  filter verwijderd; de beweeglijkheidsmaat is daar sowieso ongevoelig voor, de dag-range niet.

Verwant: `sell-engine.md` (waarom verliezers goedkoop zijn), de recall-docs (dezelfde 2-coin-muur),
roadmap-stap "meer coins".
