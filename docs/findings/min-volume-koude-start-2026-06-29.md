# min_volume — koude-start-onderzoek (Epic K, Feature 2: O1 + O2)

> **Datum:** 2026-06-29 · **Status:** O1 (convergentie) + O2 (listing-piek) **af** (read-only).
> O3 (gevoeligheid, zware refire) staat nog open — zie onderaan. Script: `engine/src/min_volume_study.py`
> (muteert niets). Data-dump: `engine/src/out/min_volume_study.json`. Substraat: de **11** munten met
> buy-rules (niet 12 — POPCAT is verwijderd).

## Wat we meten en waarom

`min_volume` is de volume-schaal van een munt (`relvol = volumeud / min_volume`, `volume.py:72`). In de
praktijk ≈ **p90 van de volumeud-reeks**. De vraag van Epic K: kun je die schaal voor een **verse live
munt** al op dag 1 betrouwbaar schatten, of vertekent de listing-piek dat? We simuleren "alleen de eerste
X uur bekend" op de 11 munten (waarvan we de volledige historie kennen) en vergelijken met de eind-waarde.

## De meettabel

```
munt          n_tot span_d  %neg      eind_p90 rank  dev12u   dev1d   dev3d   dev1w piek_d0 piekmax3
TURBO        308367    767    51     3,951,541   90    -51%    -51%    +37%    +83%    0.49     2.06
PEPE2        139907    409    16 19,352,639,898  94   +486%   +398%   +264%   +185%    4.98     4.98
NOS          144589    414    42        533.10   90   +997%   +841%   +957%   +620%    9.41    10.95
PONKE        328055    340    47         8,705   90   +561%   +456%   +352%   +283%    5.56     5.81
DOGEAI       143207    150    44        11,314   92   +467%   +391%   +277%   +214%    4.91     4.91
ATR          107213    188    40        27,088   90    -17%    -20%    +31%    +39%    0.79     1.58
MUMU         338736    490    47    43,243,356   87    -84%    -81%    -79%    -79%    0.19     0.22
CATDOG       119724    106    50    39,336,353   90    -59%    -64%    -62%    -48%    0.36     0.40
FARTCOIN     409959    379    50        13,951   93  +1217%  +1080%   +987%   +763%   11.80    11.80
1DOLLAR      130735    295     8        37,846   97  +1280%   +951%   +836%   +591%   10.51    10.51
JELLYJELLY   129956    172    20        41,227   90  +1882%  +2574%  +1813%  +1130%   26.74    26.74
```

- **dev12u…dev1w** = afwijking van de p90-schatting in dat venster t.o.v. de eind-p90 (volledige historie).
- **rank** = op welk percentiel de huidige live `min_volume` valt.
- **piek_d0 / piekmax3** = p90 van dag 0 / van de eerste 3 dagen, gedeeld door de eind-p90.

## Bevindingen

**0. `volumeud` is een net-signaal (up minus down), niet een hoeveelheid.** 16–51% van de waarden is
**negatief**, de mediaan ligt rond 0. De schaal `min_volume` is dus de **bovenstaart** (p90), niet een
gemiddelde. (Hierdoor was een eerste O2-opzet op de mediaan-ratio zinloos — vervangen door p90-per-dag.)

**1. De p90-proxy reproduceert de huidige praktijk exact** — dit valideert zowel de meting als Beslissing 1
van het epic. `eind_p90 ≈ huidig min_volume` voor de p90-munten (TURBO 3.951.541 vs 3.951.626; PONKE/ATR/
JELLYJELLY/NOS gelijk), en de **rank** geeft precies de hand-getunede percentielen terug: PEPE2 **94**,
1DOLLAR **97**, FARTCOIN **93**, DOGEAI **92**, MUMU **87**. De seeder kan dus betrouwbaar op een percentiel
mikken — mits er genoeg historie is.

**2. Een vroege schatting (≤ 1 week) is wild onbetrouwbaar.** Géén enkele munt komt binnen 10% van de
eind-waarde binnen 1 week (`conv90 = ">1w"` voor alle 11). Zelfs na een week: NOS **+620%**, JELLYJELLY
**+1130%**, FARTCOIN **+763%**, MUMU **−79%**. De p90 is niet stationair over de 100–760 dagen historie, en
de eerste week wordt gedomineerd door de listing-piek.

**3. De listing-piek splitst de munten in twee scherp gescheiden types** (kolom `piek_d0`):
- **Launch-piek (`piek_d0 ≫ 1`):** JELLYJELLY 26,7× · FARTCOIN 11,8× · 1DOLLAR 10,5× · NOS 9,4× · PONKE
  5,6× · PEPE2 5,0× · DOGEAI 4,9×. Hun data begint **bij/rond de listing** → het volume van de eerste dag
  is enorm → een vroege p90 **overschat de schaal met +400 tot +1900%**. Een verse munt op dag-1-schatting
  zou `min_volume` 5–27× te hoog zetten → bijna geen kandidaat-ticks → traadt (vrijwel) niet.
- **Post-launch (`piek_d0 < 1`):** MUMU 0,19 · CATDOG 0,36 · TURBO 0,49 · ATR 0,79. Hun import begint **ná**
  de hype → vroege p90 onderschat → schaal te laag.

**4. Voor een échte verse live munt is alleen het launch-piek-type relevant** — en dat is goed nieuws. Een
verse munt uit epic-10 (mexc) start per definitie **bij de listing**, dus valt in het launch-piek-type. De
post-launch munten in onze dataset zijn een **import-artefact** (we laadden hun historie laat in), geen
model voor de koude start. Het launch-piek-gedrag werkt bovendien **vanzelf de veilige kant op**: een
vroege schatting is te **hoog** → `min_volume` te hoog → weinig kandidaten → de munt traadt voorzichtig.
Naarmate de piek wegsijpelt zakt de p90 naar de echte waarde → de schaal moet **omlaag** herijkt worden,
niet omhoog. "Te weinig traden" is de gewenste foutrichting voor een onbekende verse munt.

## Recept-concept (voorlopig — de kwantitatieve drempels hangen aan O3)

1. **Geen vaste correctiefactor.** De piek-grootte varieert 5–27× en is vooraf onbekend; je kunt 'm niet
   wegdelen met één constante.
2. **Start conservatief-hoog, herijk naar beneden.** Seed de eerste `min_volume` op de p90 van de eerste
   beschikbare dag (bewust hoog door de piek) → de munt traadt voorzichtig. Herijk dagelijks op alle data
   tot nu toe; de schaal zakt mee terwijl de piek uit het venster loopt.
3. **IJk waarschijnlijk op een recent voortschrijdend venster, niet op all-history.** De p90 verschuift
   sterk over de historie (MUMU blijft −79%). Een venster van bv. de laatste 30 dagen volgt het echte regime
   beter dan een all-history-p90 die de oude listing-piek voor altijd meesleept. → **Te valideren voor de
   seeder (Feature 3).**
4. **Bevriezen zodra stabiel** (Feature 4) — criterium uit O1 over te leiden, bv. "N dagen dat de
   herschatting < ±10% beweegt". Op basis van deze data is dat **niet binnen 1 week**; reken op meerdere
   weken voordat een launch-piek-munt stabiliseert.

## O3 — gevoeligheid (laag 1: kandidaat-ratio, read-only — GEMETEN)

Kernvraag: **hoeveel maakt een verkeerde `min_volume` uit?** Laag 1 meet de zuivere gevoeligheid van de
volume-poort (`brain_volume_found`) voor de schaal — per factor het % ticks dat minstens één buy-rule z'n
volume_check haalt met `min_volume × factor`. Volledig in-memory, geen mutatie. Script:
`engine/src/min_volume_sensitivity.py`. Steekproef (sample 1/8):

```
munt         n_meet  x0.5   x0.7   x0.8   x1.0   x1.2   x1.3   x1.5
TURBO         38546   12.5   11.0   10.3    9.2*   8.1    7.7    7.0
DOGEAI        17901   12.5   10.3    9.6    8.2*   7.1    6.7    6.0
1DOLLAR       16342   16.7   12.1   10.5    7.9*   6.3    5.6    4.5
```

**Conclusie laag 1: binnen ±50% is de schaal ruw-ongevoelig — een zachte glijschaal, geen klif.** Een
±20%-fout verschuift de kandidaat-ratio met grofweg ±10–30% (1DOLLAR, de gevoeligste/hoogst-geijkte, het
steilst). Er is geen drempel-effect waar de kandidaat-set plots instort. Dat bevestigt de veilige
foutrichting: te hoog → geleidelijk minder kandidaten, niet plots nul.

**Maar het echte koude-start-risico ligt buiten deze band.** De vroege fouten uit O1 zijn geen ±50% maar
**+400 tot +1900%** (factor 5–27×). Bij `min_volume` 10× te hoog is de kandidaat-ratio vrijwel 0 → de munt
traadt niet. Dus: **zodra de schatting binnen ~±50% van de echte waarde zit, mag een verse munt traden**;
de uitdaging is van +500% naar binnen-±50% komen — en dat duurt (O1: niet binnen 1 week). Dit maakt punt 4
van het recept concreet: herijk agressief omlaag, en geef pas "stabiel" als de schatting binnen ±50%
(ruim) — strenger ±10% (bevries-criterium) — beweegt.

### Nog open — O3 laag 2 (Σprofit / #trades, zware refire)

Laag 1 meet de kandidaat-*set*; de trade-*uitkomst* (welke kandidaten winst/verlies geven) kan scherper
reageren. Dat vereist een **volledige refire per factor** (de schaal zit in de prefix-checksum → Epic
I-incrementeel helpt niet) en **muteert `coin_fires`** → een losse-terminal-taak met herstel, geen
read-only sweep. Nog niet gebouwd. **Commando's:**

```bash
# O3 laag 1 — volledig (alle 11 munten, alle ticks). Read-only, ~5-7 min.
cd engine/src && ../.venv/bin/python min_volume_sensitivity.py

# O3 laag 2 — Σprofit/#trades per factor. Muteert coin_fires (refire + herstel) → op verzoek te bouwen
# als min_volume_sensitivity_l2.py; ~17 min refire × 7 factoren × ≥3 munten ⇒ losse terminal (nohup/screen).
```
