# Nieuwe trading-rules ontdekken — zoekstrategie

Hoe we **bottom-up** nieuwe koop-rules (rule 30, 31, …) afleiden uit de handmatig gemarkeerde goede
instap-momenten, in plaats van top-down regels te verzinnen. De rules 20-23 zijn ooit zo gegroeid:
elk geclusterd op een **regime** (bv. lage óf hoge phobos) en daarna verfijnd. Dit document beschrijft
de algemene strategie; de **multi-rule-in-een-groepje** (een parent-cascade over t0·t1·t2) is een
specifieke variant daarvan (§6).

Bijbehorende skill: [[brain-rule-discovery]]. Engine-kaart: [[brain-engine]]. Grondwaarheid en labels:
[[brain-promising-labeler]]. Verkoop-toets: [[brain-sell-engine]].

---

## 0. Waarom dit bestaat & wat "klaar" is (de strategie)

**Dit is het belangrijkste van nobrainersbot — de rules ZIJN het product.** Daarom: documenteer alles,
leg elk inzicht vast, word er steeds slimmer in.

**Oorsprong:** veel promising trades worden gemist door de huidige volume-poort (`brain_volume_found`) —
hun volume wordt niet "gevonden" met onze huidige berekening. De volume-berekening wijzigen was geen
optie → daarom ontdekken we rules **direct uit de yes-marks** (3-tick groepjes t0·t1·t2), buiten die
poort om. (Nuance: het enige tot nu toe gevalideerde signaal is `volumeud-std` = volume-MAGNITUDE — dus
volume ís relevant, alleen anders gemeten dan de poort. De promising trades hébben een volume-signatuur,
gewoon niet de ene die de poort checkt.)

**Wat nobrainersbot IS:** een set rules die samen een **deel** van de promising trades vangen. Doel:
~**30-50% van de promising trades** vangen — niet 100%, een deel is genoeg. Elke rule moet:
1. **over ALLE munten** aan de vereisten voldoen (coin-agnostisch, of expliciet per-munt afgesteld), en
2. **20-23-kwaliteit** halen (weinig slecht — zie §5).

**Architectuur rule 30 (de bouwrichting):** regime/parent (de 3-tick cascade) **+ een CHILD-rule** die —
precies zoals 20-23 — vanaf t2 **álle ~30 berekeningen × lookback 1-20** doet, de gemene deler over de
groepjes vindt, en zo de slechte trades wegfiltert (subrules op volume, prijs én vorm). Doel: van
firehose (een losse regel vuurt 1000en×) naar **20-23-schaal** (honderden), landend op de echte
stijgingen (promising groepjes = mediaan **+12 à +16%** max-up, mediaan 0% dip — geverifieerd; de labels
zijn zuiver, de losheid komt van de rule). Discipline: elke subregel-keuze **pre-registered / holdout /
permutatie-getoetst** — de scan verbergt anders echte signalen onder ruis (zie §9).

## 1. Filosofie

*Faithful first → measurably better → gated apply.* Een nieuwe rule is pas iets waard als hij over de
**complete muntperiode** en op **beide munten** standhoudt, en — de eindtoets — **echt geld** oplevert
na de verkoopmotor. Buy-kwaliteit (mooie stijging na de instap) is niet hetzelfde als winst.

## 2. Grondwaarheid (hard)

- **Alleen `decision='yes'` ("ok")-marks zijn waarheid.** Dit zijn de momenten die Daan zelf als goede
  instap heeft afgevinkt (hij weet de afloop al — daarom vinkt hij ze aan).
- **Ongelabeld is NIET slecht.** Een survivor die niet gelabeld is, krijgt nooit het stempel "slechte
  trade". We rapporteren zijn forward-afloop / sell-resultaat, geen oordeel.
- Slechte trades in de rapportage = **gerealiseerde verliezers** (sell-engine `profit_loss < 0`), niet
  "gelabeld slecht".

## 3. De methode, stap voor stap

1. **Promising groepjes verzamelen.** Groepeer de yes-marks in *rises* (nieuwe groep bij gap > 5 min).
   Eén rise = één periode met meerdere goede instap-ticks. Neem de **eerste 3 opvolgende ticks** als de
   triple (schuifbaar binnen de groep: 1-3 / 2-4 / 3-5).
2. **Gemene deler per groepje aflezen.** Bereken voor elke triple-tick de volledige metric-set: ~30
   `window_metrics` × meerdere lookbacks × 5 indicatoren (obv-x-value, vzo, mfi, phobos, volumeud +
   prijs). Lees per feature de band af waarin de 3 ticks vallen.
3. **Clusteren in regimes (= MEERDERE rules).** De groepjes delen meestal géén enkele band — hun centers
   beslaan het hele oscillator-bereik (obv 28 én 69). Dat is geen ruis maar **verschillende regimes**.
   Splits de groepjes in 2-3 clusters (KMeans op de oscillator-vector; phobos is vaak een goede primaire
   as). **Elk regime wordt een eigen rule.** De winnende regimes kunnen per munt tegengesteld zijn
   (DOGEAI = oversold, NOS = momentum).
4. **Projecteren over de hele periode.** Neem de regime-band (de AND-hull van de cluster-leden) en tel
   hoeveel ticks hem halen met de **parent-cascade**: k=3 opvolgende ticks moeten allemaal in de band
   zitten. Telling = selectiviteit (1 / 10 / 1000 survivors?).
5. **Verfijnen met vorm-subregels.** Voeg condities toe om de zwakke survivors eruit te halen:
   skewness / range_percentage / volatility met een lookback. **Leun naar vorm/relatieve metrics, niet
   absoluut niveau** — absolute niveaus (current_value) driften per dag en overfitten; vorm-metrics
   (skewness van het volume!) generaliseren beter over dagen heen.

## 4. Scheidsrechters (de overfit-remmen)

Met ~1000 kandidaat-condities en een handvol goede voorbeelden vind je *altijd* iets dat de train-set
schoonmaakt. Daarom telt een bevinding pas als hij ál deze poorten haalt:

1. **Cross-groep-herhaling** — dezelfde band/feature komt terug in ≥2-3 ónafhankelijke groepjes, en
   bij voorkeur op **beide munten**. Eén feature dat op beide munten onafhankelijk wint is het sterkste
   bewijs tegen toeval.
2. **Tijd-holdout** — band afleiden op de vroege helft van de rises, bevestigen op de late helft.
3. **Zelfde-dag baseline** — vergelijk de survivors met willekeurige ticks op **dezelfde dagen**. Anders
   meet je alleen "de survivors vuurden op een stijgende dag", niet een echt timing-signaal.
4. **Gerealiseerde winst (de eindtoets)** — haal de survivors door de sell-engine (trouwe dedup op
   `selling_date`) en vergelijk met een **schone random sell-baseline** (NIET
   `coin_moment_sells.profit_loss` als gemiddelde — outlier-rijen maken dat onbruikbaar). Guard
   `|pl|>200%` (kapotte prijs-ticks). Alleen `profit_loss`, geen best_upside ([[feedback-hard-numbers-only]]).
5. **Permutatie-test (beslissend tegen multiple-testing)** — een greedy-scan over honderden kandidaten
   tovert uit **pure ruis** al een holdout-edge (gemeten: null-p95 ≈ +0,28%/trade). Shuffle de winst,
   draai dezelfde scan N×, en eis dat de echte edge in de staart valt (p<0,05). **Les: méér scannen
   verbergt echte signalen onder ruis.** De zuivere weg = een **pre-registered hypothese** (uit
   herhaling-over-methodes, drempel uit de labels) permutatie-toetsen — zónder scan. Zo bleek NOS
   `oversold + volumeud-std` p=0,001 (echt), terwijl de scan-versie p=0,07 (grensgeval) gaf.
   Harness: `parent_perm.py` (scan) / `parent_perm_fixed.py` (pre-registered).

## 4b. Verplicht protocol (volgorde + statistische discipline)

De volgorde waarin je dit doet bepaalt of je jezelf voor de gek houdt. Geleerd, in deze volgorde:

1. **Labels eerst.** < ~50 groepjes/munt = niet betrouwbaar (geen echte holdout mogelijk). Label divers
   (veel dagen, beide munten) vóór je zoekt. **Verifieer** dat de groepjes echte stijgingen zijn
   (mediaan +12-16% max-up gemeten) — anders zoek je naar ruis.
2. **Meet hard.** Alleen `profit_loss` via trouwe sell-engine-dedup (`selling_date`); schone random
   baseline (NIET `AVG(coin_moment_sells)`); artefact-guard `|pl|>200%`. Lat = **20-23, niet random**.
3. **DE valkuil — multiple testing.** Een greedy-scan over honderden kandidaten **tovert uit pure ruis
   al een holdout-edge** (gemeten: null-p95 ≈ +0,28%/trade). **Méér scannen = hogere ruis-vloer → het
   verslaat zichzelf.** Daarom:
   - **Permutatie-test ALTIJD** (shuffle winst, draai dezelfde scan N×, eis echte edge p<0,05).
   - **Beste praktijk: pre-register.** Neem een feature die **over meerdere methodes terugkeert**, leg
     hem vast met een drempel **uit de labels** (niet uit het optimaliseren van de winst), en
     permutatie-test díé ene hypothese — **zonder scan**. (Zo: scan-versie p=0,07 → pre-registered p=0,001.)
4. **Holdout = echte tijd-split** (vroege → late dagen, ≥30 dagen gap), niet binnen dezelfde markt-episode.
5. **Cross-coin.** Test op een 2e munt; coin-specifiek mag, maar **markeer het** (geen coin-agnostische
   claim zonder bewijs op een munt waar je 'm niet vond).
6. **Realiteit erkennen.** **Meer features/berekeningen ≠ meer precisie** op weinig data — het geeft
   overfit, geen schonere scheiding (rule-30 child: train-recall 77% → holdout 4/59, verlies). De
   bindende beperking is de hoeveelheid **diverse data (munten)**, niet het aantal features.
7. **Echte deploybaarheid = incrementele refire** bovenop 20-23 (één-positie-schaduw), niet standalone.

## 5. Rapportagevorm + succescriterium

Rapporteer een gevonden rule **altijd KORT, per munt** ([[feedback-compact-result-format]]):

> **{munt}: {N}/{M} promising groepen | goed {g} / middel {md} / slecht {b} | Σprofit {±x}%**

(Optioneel klein erbij: gem %/trade, p-waarde.) goed/middel/slecht = gerealiseerde `profit_loss`
(goed ≥3% / middel 0–3% / slecht <0%; geen best_upside). Géén lange tabellen of proza eerst.

### Succescriterium — de lat is 20-23, NIET random

Een random sell-baseline is een te lage ondergrens (sanity only). De **bestaande rules 20-23 zijn de
richtlijn**. Gemeten (gerealiseerd, executed trades):

| | selectiviteit (%ticks) | gem/trade | slecht% | goed% | Σprofit |
|---|---|---|---|---|---|
| DOGEAI 20-23 per regel | 0,01–0,08% | +0,68 … +12,5% | 24–61% | 12–37% | +80 … +187% |
| NOS 20-23 per regel | 0,02–0,06% | +1,48 … +2,22% | 9–40% | 21–26% | +66 … +177% |
| typisch (alle 4 samen) | ~0,15% | ~+1,9 à +2,3% | ~28–45% | ~19–23% | +404 … +562% |

Een nieuwe regel is **succesvol** als hij per munt: (1) selectiviteit ≤ ~0,1% van de ticks — vuurt op
de **schaal van de promising groepjes**, niet als firehose; (2) gem winst/trade ≥ +0,7% (zwakste
bestaande), streef ~+2%; (3) slecht ≤ ~45% én goed ≥ ~19%; (4) Σprofijt positief; (5) standhoudt op
tijd-holdout, pre-registered permutatie-significant (p<0,05), én positief op een 2e munt (of expliciet
*coin-specifiek*). De random-baseline blijft als sanity-vloer, maar 20-23 is de lat die telt.

**Doel van nobrainersbot als geheel (recall):** de rules samen hoeven niet ALLE promising trades te
vangen — **~30-50% is genoeg**. Per rule = een smalle, precieze plak; samen dekken ze een deel. Een
losse regel die 1000en× vuurt om recall te halen is FOUT (firehose → veel slecht); precisie eerst,
recall via méér rules. Geverifieerd: de promising groepjes zijn echte stijgingen (mediaan +12 à +16%
max-up, mediaan 0% dip) — de labels zijn zuiver, dus slecht% komt van een te losse rule, niet van slechte labels.

## 6. Variant: multi-rule-in-een-groepje (parent-cascade)

De specifieke variant die dit onderzoek startte: koop niet op de **eerste** groene tick, maar pas als
een groepje van **k=3 opvolgende ticks** (t0·t1·t2) allemaal aan de rule voldoen — een *parent-poort*.
Twee vormen:
- **Rule 30 (cascade):** dezelfde band moet 3 ticks achter elkaar gelden → koop op t2.
- **Rule 30a/30b/30c (positioneel):** t0 voldoet aan 30a, t1 aan 30b, t2 aan 30c (elk verfijnder).

De cascade verhoogt de precisie (filtert losse vonken) maar verlaagt de recall. Optioneel per rule
aan/uit, datamodel-patroon zoals de `coin_strategies`-override.

## 7. Harness (READ-ONLY, `engine/src/`)

| Script | Doet |
|---|---|
| `parent_discover.py` | `Features`-class (volledige metric-vector per tick) + yes-groepen vormen |
| `parent_fullperiod.py` | enkel-feature holdout-screen + `rises()` (groepjes per munt) |
| `parent_regimes.py` | KMeans-regimes + AND-band projectie |
| `parent_eval.py` | **eindtoets**: trouwe sell-engine-dedup (`selling_date`) → rapportagevorm §5. Helpers `faithful_trades` (artefact-guard `\|pl\|>200%`), `trade_stats`. **Geen best_upside** |
| `parent_crosscoin.py` | vaste rule op een andere munt projecteren (cross-coin dry-run) |
| `parent_discovery.py` | regime-clustering + **echte tijd-holdout** (train-dagen → holdout-dagen), per regime |
| `parent_refine_holdout.py` | beste vorm-subregel: selecteer op TRAIN, rapporteer op HOLDOUT |
| `parent_stack_holdout.py` | greedy 2-3 vorm-subregels stapelen (platte AND), holdout-bevestigd |
| `parent_spoor1.py` | greedy EENZIJDIGE drempels + fijner rooster (lean metrics, snel) |
| `parent_perm.py` / `parent_perm_fixed.py` | permutatie-test (scan-versie / pre-registered, §4.5) |
| `parent_child.py` | rule-30 CHILD: volle ~30 calcs × lookback 1-20 vanaf t2, greedy AND |

(Verouderd / vervangen door de holdout-varianten: `parent_refine.py`, `parent_periodbase.py` — die
gebruikten nog de forward-up%/dip% (`fwd`), wat per de hard-cijfer-regel niet meer mag, zie
[[feedback-hard-numbers-only]].)

## 8. Een rule = een smalle plak (cruciaal mentaal model)

De bestaande rules zijn **platte ANDs van 39-81 subregels** (rule 20: 43, 21: 39, 22: 81, 23: 39) en
vuren elk op **0,01-0,08% van de ticks** — elke rule pakt dus maar een **klein stukje** van alle
promising trades. Zo dekken 20-23 samen het geheel: veel smalle, strak-getunede rules naast elkaar.
Een discovery-rule met 5-6 subregels is daarom per definitie nog te los; de weg naar de lat is **veel
meer subregels per rule** (indikken) ÉN **meer rules** (dekking) — elk afzonderlijk voldoend aan §5.

## 9. Bevindingen (juni 2026, DOGEAI + NOS)

- **Absolute niveaus falen.** obv 41-46 leek perfect op 1 dag, maar was een dag-artefact (over alle
  labels: goed≈slecht). Bevestigt de 2-munten-muur en seed-and-tighten overfit.
- **Meer labels = de doorbraak in betrouwbaarheid.** Van 14/16 groepjes naar **DOGEAI 159 / NOS 143**
  (76 / 85 dagen) → een echte tijd-holdout kan eindelijk, en de recall is hoog (regimes dekken 10/11,
  25/28, 26/28 holdout-groepjes).
- **Regime + gestapelde vorm-subregels: echte holdout-edge, maar nog ONDER de 20-23-lat.** Robuustste
  vondst (2 onafhankelijke runs): **NOS oversold-regime + `volumeud` standaarddeviatie** → ~0,33% van de
  ticks, **+0,27%/trade** vs baseline +0,07% (holdout, hard), 58% slecht. DOGEAI oversold + 2 vorm-
  subregels: +0,13%/trade, 0,35%, zwakker. De greedy **plateaut rond 0,33%** (geen 3e subregel die
  indikt zonder de groepjes/edge te verliezen).
- **Het terugkerende kenmerk-type = volume-distributie-vorm** (skewness/std), maar per munt afgesteld;
  géén coin-agnostische regel — welk regime wint verschilt per munt (DOGEAI oversold, NOS oversold/momentum).

- **Permutatie-test ontmaskert de scan (§4.5).** Spoor 1 (eenzijdige drempels + fijn rooster) bracht NOS
  naar 0,080% selectiviteit, maar de permutatie-test: de greedy-scan tovert uit ruis al +0,28%/trade →
  spoor-1-edge grotendeels scan-artefact (NOS p=0,07, DOGEAI p=0,32). **Pre-registered** `NOS oversold +
  volumeud-std` (geen scan) IS echt: **p=0,001**, +0,27%/trade; DOGEAI p=0,40 (coin-specifiek).
- **Rule-30 child (volle 30 calcs × lookback 1-20 vanaf t2) MISLUKT.** DOGEAI overfit (train-recall 77% →
  holdout 4/59, Σ −32%, ónder random); NOS firehose (1946 trades, 1147 slecht, +454% maar diluut). De
  30 berekeningen scheiden de promising-t2-ticks **niet schoon van de achtergrond** op 2 munten.

**EINDCONCLUSIE:** op **2 munten** is geen 20-23-grade rule te vinden. Het enige reële signaal = **NOS
oversold + volumeud-std** (volume-magnitude, p=0,001), maar bescheiden (+0,27%/trade vs +1,9%) en
coin-specifiek. Labels zijn zuiver (groepjes = +12-16% stijging). **Méér features/berekeningen = overfit,
geen precisie.** De bindende beperking is het **2-munten-plafond**; de **enige echte hefboom = MEER
MUNTEN** (meer diverse data) — zie roadmap E05/E07 + memory's `coin-volatility-stoplicht` /
`mexc-volatile-coins-discovery`. De methode + harness + 20-23-lat staan klaar voor zodra er meer munten
zijn. GEEN rule toegevoegd.

## 10. Onverkende insteken + gekozen volgende stap (beslissing juni 2026)

De groepjes zijn véle promising trades met hoge winst (+12-16%) — er **moet** uiteindelijk iets te
vinden zijn. De reframe: niet "vang álle groepen" (loos, want heterogeen), maar **vind eerst de
schoonste ~25%-segment** van de groepen; een gemene deler over die dichte 25% is smal → automatisch
selectief → precies. Onverkende insteken, op volgorde van belofte:

1. **Precisie-pocket-zoektocht** *(GEKOZEN volgende stap)* — niet "welke feature scheidt alle groepen",
   maar schuif over de waarde-as van elke (indicator × lookback 1-20 × ~30 calcs) een **smal venster** en
   zoek het venster dat **veel groepen vangt ÉN zeldzaam is in de achtergrond** (~25% groepen bij <0,1%
   achtergrond = een dichte, zeldzame pocket). Ander zoekdoel dan de greedy child (die alle groepen wilde
   houden → loos). **Meteen cross-coin gepoold** (DOGEAI+NOS) zodat de pocket coin-agnostisch is.
2. **Density-clustering** (DBSCAN/nearest-neighbor) i.p.v. KMeans — dichte kern houden, uitbijters laten
   vallen ("hou 25% over"). KMeans dwingt elke groep in een cluster.
3. **Cross-coin segment vanaf het begin** — pocket die op BEIDE munten zit = de enige weg naar coin-agnostisch.
4. **Vorm van de t0→t1→t2-beweging zelf** — dynamiek over de 3 ticks (versnelling, volume-profiel van de
   mini-beweging); alleen statische t2-features zijn tot nu gebruikt.
5. **Segmenteren op stijging-grootte** — de grootste/duidelijkste pumps delen mogelijk een schonere signatuur.

Discipline blijft §4b (holdout + permutatie).

**LES (vastgelegd juni 2026):** een gemene deler zoeken over **ÁLLE** promising groepen is **per
definitie kansloos** — ze zijn heterogeen. Bewezen: de precisie-pocket-search (`parent_pocket.py`) vond
**0 features** waar de groepen zich van de achtergrond onderscheiden (zelfs een venster dat 87% van de
groepen vangt pakt 81% van de achtergrond). **DAAROM de juiste insteek:** vergelijk NIET met de
achtergrond, maar **segmenteer de groepen zelf** — vind segmentaties die elk **10-25% van de groepen
binden** (zoals 20-23 ontstonden: lage phobos, range, etc.; de eerste segmentatie was volume, maar hier
anders en niet als één segmentatie). Catalogus eerst (hoeveel segmenten, op welke berekening: current_value,
prijsstijging% over lookback, "geen daling", combi's), **dán per segment verfijnen** (achtergrond/precisie
+ t0→t1→t2-dynamiek). **Doel: een rule die de voorwaarden haalt ÉN 10-25% van de promising trades pakt =
perfect.** Harness: `parent_segment.py` (segmenteer groepen, géén achtergrond) → `parent_pocket.py` is de
verouderde achtergrond-variant.

**Segmentatie-resultaat (juni 2026):** ~54/57 natuurlijke splits per munt, **19 cross-coin**; kop-axis =
**phobos-regime** (count pos/neg phobos over lb 12-20, bindt 33-38%) — exact de "lage/hoge phobos"-indeling
van 20-23. **Gekozen verfijn-richting:** (1) scherp het phobos-segment op **current_value** (80-20: de band
die ~80% van de groepen bindt); (2) zoek dáárbinnen extra binders, te beginnen met een **volume-regel**
(aantal negatief volume, % prijsstijging t2 t.o.v. t0) + continue stijging × lookback. Harness:
`parent_phobos_refine.py`. "Bekijk goed wat het groepje bindt" — descriptief, dán pas precisie/achtergrond.

**FUNNEL-METHODIEK (`parent_funnel.py`) — de kern-methodiek.** De insteken zijn bijna oneindig → de
methodiek zélf = goede segmentaties stapelen en per subregel de **TRECHTER** volgen: hoeveel promising
groepen hou je (recall, train+holdout) en op hoeveel ticks vuur je nog (selectiviteit). Regels:
- **Tijdens het indikken NIET op goed/slecht kijken** — gewoon narrowen tot klein genoeg (1000en fires =
  te veel, verder terug); **pas op het EIND** goed/middel/slecht beoordelen. Je houdt altijd een deel
  van de promising trades (dat is het uitgangspunt: 10-25% vangen = perfect).
- Greedy: kies de subregel die de tick-fire het meest indikt terwijl ≥X% van de groepen blijft. Volgorde
  zoals 20-23: volume → indicator-value-ranges → prijs.
- **Funnel-resultaat (juni 2026):** elke subregel dikt maar ~30% in (niet 50%); bg-fire blijft op ~12-14%
  na 8 subregels (20-23 = 0,01-0,08%, dáárom hebben die 39-81 subregels); en **holdout-recall stort in
  (6-7%) terwijl train blijft (30%) → de subregels OVERFITTEN op de train-groepen**. Conclusie: op 2
  munten niet naar 20-23-schaal in te dikken zonder de holdout-groepen weg te overfitten. De funnel is de
  juiste tool en wijst een echte rule aan zodra er meer munten zijn.

## 11. Prior art — dit is een bestaand vakgebied (juni 2026 internet-onderzoek)

Wij zijn niet de enigen; onze 3 bouwstenen hebben academische namen + kant-en-klare tools:

1. **"Segmenteer de promising trades"** = **Subgroup Discovery** — vind beschrijfbare subsets waarvan de
   target-verdeling afwijkt van het geheel. Lib: **pysubgroup** (github.com/flemmerich/pysubgroup, op
   pandas). Algoritmes: APRIORI-SD, SD-Map, BSD; ingebouwde interestingness-maten + non-redundantie.
2. **De funnel / subregels stapelen** = **Rule Induction / Sequential Covering** — leer-één-regel,
   verwijder-gedekte, herhaal; conditie-voor-conditie. Algoritmes: **RIPPER, CN2, AQ**. Python: **wittgenstein**
   (RIPPER). APRIORI-SD geeft kleinere rule-sets met hogere coverage/significantie.
3. **Onze permutatie/pre-register-discipline** = **backtest-overfitting-statistiek (Marcos López de Prado)**:
   **PBO** (Probability of Backtest Overfitting) + **Deflated Sharpe Ratio** (Bailey & LdP) corrigeren voor
   selection-bias onder multiple testing ("3 trials = al schijn-significantie"; "100 varianten, ruwe Sharpe
   2,0 → deflated 0,5"). **CPCV** (Combinatorial Purged Cross-Validation) + purging/embargo = strengere
   tijd-holdout dan walk-forward, geeft een VERDELING van out-of-sample-performance.

**Mee te nemen (concrete upgrades):**
- Gebruik **pysubgroup** + **wittgenstein** i.p.v. zelf bouwen (kwaliteits-maten + zoek-algoritmes klaar).
- Upgrade de holdout naar **CPCV + purging/embargo** (verdeling van OOS i.p.v. één split).
- Kwantificeer overfit met **PBO + Deflated Sharpe** (formaliseert onze permutatie-test).
- **López de Prado's #1 aanbeveling: modelleer de HELE universe, niet losse effecten** → exact onze
  conclusie "meer munten". De literatuur bevestigt: per-coin op 2 munten = recept voor false discoveries.

## 12. De discovery-engine GEBOUWD (Epic RD) + uitkomst (juni 2026)

De methodiek staat nu als herbruikbare engine in **`engine/src/discovery/`** (de losse `parent_*.py`-proef
is geconsolideerd), met de twee onderdelen die de proef miste:
- **pysubgroup** (`segment.py`) — Subgroup Discovery: conjuncties van feature-banden met kwaliteits-maten
  + non-redundantie, doel = `is_promising`. Vindt de promising-dichte ~10-25%-plakken die de losse-feature
  pocket-search niet kon vinden.
- **CPCV-gestuurde subregel-keuze** (`funnel.py`) — elke subregel wordt gekozen op de **trefkans buiten de
  trainingsdata** (drempel afgesteld op train-blokken, gemeten op het apart-gehouden blok); stopt zodra die
  zou instorten. Drempels overal via een reproduceerbare percentiel-regel (pysubgroup kiest alleen de
  STRUCTUUR: welke features + richting), zodat CPCV consistent is.
- **Validatie** (`validate.py`) — CPCV met herfit + embargo, toeval-toets (pre-registered) + Šidák-correctie
  voor het aantal pogingen, schone willekeurige nullijn, en de **incrementele bijdrage** bovenop 20-23
  (in-memory, uit `coin_fires` — NIET via RuleEngine want `brain_volume_found` staat in de huidige DB op 0).

Draaien (vanuit `engine/src`): `python -m discovery.run --coin both`. Modules: `data.py` (feature-tabel +
CPCV-blokken), `segment.py`, `funnel.py`, `validate.py`, `report.py`, `run.py`. Dev-cache in `.cache/`
(geen Parquet-store — Feature 1 is bewust uitgesteld tot "meer munten").

**Uitkomst op DOGEAI + NOS (de eerlijke beste kans op 2 munten):**

| | selectiviteit | gem/trade | CPCV buiten-data | slecht / goed | toeval-toets (Šidák) | 2e munt |
|---|---|---|---|---|---|---|
| DOGEAI | 0,199% | +0,94% | +1,59%/trade, 100% blokken+ | 63% / 9% | p=0,000 | NOS p=0,19 (zwak) |
| NOS | 0,364% | +0,45% | +1,18%/trade, 100% blokken+ | 57% / 8% | p=0,037 | DOGEAI p=0,002 ✓ |

**Wat nu WEL lukt (en bij de ruwe proef niet):** (1) de funnel dikt door tot bijna 20-23-selectiviteit en
de **OOS-trefkans stort niet meer in** (loopt mee met de train-trefkans) — het vastpinnen op toeval is
opgelost door de CPCV-poort; (2) beide rules zijn **out-of-sample winstgevend** (élk tijdblok positief) en
**significant ná multiple-testing-correctie**; (3) één feature is coin-agnostisch: **`price|L5|mindip`**
(*een kleine dip kopen*) zit in de top-segmenten van beide munten.

**Waarom toch GEEN KEEPER:** te veel **goedkope verliezers** (56-63%) en te weinig grote winners (7-9%) →
de strikte 20-23-lat (slecht ≤45%, goed ≥19%, ≤~0,1% ticks) wordt net niet gehaald, ook al is de netto-winst
positief (winst-lock maakt verliezers goedkoop). **De bindende beperking is nu de trade-kwaliteitsmix, niet
overfit of generalisatie** — een scherpere diagnose dan §9. Het 2-munten-plafond is daarmee **verdiend met
het juiste gereedschap**; de hefboom blijft **meer munten** (Epic 07). `apply.py` (KEEPER → DB) is conform
plan NIET gebouwd: er is geen KEEPER. Engine staat klaar voor zodra er meer munten zijn.

**Gepoolde both-coin ontdekking (`discovery/pooled.py`).** De per-munt-rules bleken munt-specifiek (rule 30
zakt op NOS naar p=0,19). Daarom een GEPOOLDE variant: zoek één gedeelde **structuur** (zelfde features +
richting) over beide munten samen, met **per-munt drempels** (percentielen uit elke munt z'n eigen promising
→ coin-aangepast, ook voor schaal-afhankelijke features). De funnel neemt een (feature, side) alleen op als
hij op **ÁLLE** munten tegelijk indikt én de OOS-trefkans overeind houdt; stopt zodra één munt zou instorten.
Resultaat: een **45-subregel gedeelde structuur** die op beide munten tot ~0,4% selectiviteit komt, met
OOS-trefkans die op beide meeloopt (geen instorting), out-of-sample winstgevend op **beide** (CPCV +1,22 …
+1,37%/trade), Σ positief (+218% / +275%), p=0,000 (Šidák). **Maar nog steeds GEEN KEEPER:** op beide munten
~57-60% slecht / 9% goed / ~0,4% ticks. **Bevestiging:** de bindende grens is de **trade-kwaliteitsmix**,
identiek op beide munten — niet coin-specificiteit en niet overfit. Een echte both-coin rule-structuur bestáát
dus (en is OOS-significant), maar haalt de strikte 20-23-kwaliteitslat niet op 2 munten. Open vraag voor
vastleggen: de gedeelde structuur voegt netto winst toe bovenop 20-23 (ΔΣ +167 … +199%) maar óók veel
verliezers (+209 … +226) → de standaard apply-poort ("verliezers niet omhoog") zou 'm weigeren; persisteren
vereist een net-winst/winst-lock-rationale of eerst de slecht%-tuning via de routines.

**Coin-agnostische rule (de juiste vorm — `pooled.py`, gedeelde banden).** De gepoolde variant gebruikte
nog **per-munt drempels** → dat is feitelijk een rule-per-munt (rule 30 DOGEAI + 31 NOS), wat het
uitgangspunt schendt. De juiste vorm = **één rule_number met GEDEELDE banden voor alle munten** (zoals
20-23). Dat kan alleen met (1) **schaal-invariante features** — relatief/% en vorm (skewness,
range_percentage, volatility, sum_average_positive_percentage, diff_previous_value, consecutive,
reversal_count, sideways); de ruwe-magnitude-metrics (`standard_deviation`, `diff_lowest_value_period`,
absolute levels) vallen eruit want hun schaal verschilt per munt — en (2) **gedeelde drempels** (percentiel
over de gepoolde promising-ticks van alle munten). Eén rule, één set banden, geldig op DOGEAI, NOS én elke
toekomstige munt. Resultaat (55 gedeelde subregels): **NOS bijna KEEPER** (0,13% ticks, +1,14%/trade, 47%
slecht, CPCV +2,9%, p=0,000), **DOGEAI zwakker** (0,24%, +0,64%/trade, 65% slecht, CPCV +1,36%, p=0,000) —
netto winstgevend + significant op beide, OOS-trefkans loopt op beide mee, maar de strikte slecht%-lat
(≤45%) wordt nog niet gehaald (zelfde structurele grens). `apply.py` legt dit als ÉÉN rule_number (30) met
gedeelde banden vast (active=0, getrouwheid lean-vs-live 100%, rules_history-audit). **Modus is nu
coin-agnostisch by design** (`pooled.py`: `scale_invariant_cols` + gedeelde percentiel-drempels) — geen
per-munt-rules meer.

## 13. Vaste werkwijze: zoek de volgende rule op de WITTE PROMISING-RUIMTE (juni 2026, Daans regel)

**De vaste eerste stap voor elke nieuwe rule (31, 32, …):** prioriteer de **grootste witte vlek** — de
promising-groepen (handmatig gemarkeerde goede instap-momenten) waar **nog geen enkele live trade op zit**.
"Bedekt" = er valt al minstens één live executed trade (rule 20-30) in het groep-venster; "wit" = nog door
geen enkele rule gepakt. Zo richten we de zoektocht op échte recall-winst (gemiste goede momenten), niet op
momenten die de bot al pakt. **Dit gaat over GEREALISEERDE dekking, nooit over best_upside/potentieel** —
zie de afspraak "alleen harde sell-cijfers": een hoge potentiële stijging die niet gerealiseerd werd is een
sell-engine-kwestie (wanneer verkoop je), géén reden voor een nieuwe koop-rule.

**Gereedschap:**
- `python -m discovery.whitespace` — rangschikt de ~30 pysubgroup-segmenten per munt op **witte dekking**
  (#promising-groepen zonder live 20-30-trade), met precisie, selectiviteit en de Σ gerealiseerde pl van de
  beste tick per witte groep. Read-only; toont waar de grootste winst-kans zit.
- `python -m discovery.pooled --whitespace --rule 31` — zoekt de coin-agnostische rule (gedeelde banden)
  op de witte ruimte: `whitespace_rules=(20,21,22,23,30)` beperkt de funnel tot de nog-witte groepen per munt.
- `python -m discovery.apply --rule 31 [--write]` — legt de gevonden rule **inactief** (active=0) vast in
  `brain.rules` met getrouwheids- en refire-cijfers + rules_history-audit. `--rule N` parametriseert het
  rule-nummer (bron-json `.cache/pooled_rule_N.json`).

**Portfolio-filosofie (Daan).** We vinden zoveel mogelijk rules en leggen ze **inactief** vast met hun
volledige toets-cijfers (toeval-toets p, CPCV-OOS, slecht%, goed%, ΔΣ bovenop 20-23). Straks bij live traden
besluiten we per munt welke aan/uit gaan (`coin_strategies`-override bestaat). **De rem:** een rule telt pas
mee als hij door de **toeval-toets (p<0,05, Šidák-gecorrigeerd) én de apart-gehouden testperiode** komt —
anders vul je het portfolio met ruis. Op 2 munten lijken alle gevonden rules op elkaar (netto positief,
loser-zwaar) → de echte hefboom blijft **meer munten**, niet meer rules op dezelfde twee.

**Stand (juni 2026):**
- **Rule 30** — LIVE (active=1), vult de idle-gaten van 20-23. Beide munten samen: 386 trades, Σ +267%.
- **Rule 31** — vastgelegd INACTIEF (active=0), gevonden op de witte ruimte (141/159 DOGEAI, 133/143 NOS
  nog wit). DOGEAI 245 trades +0,79%/trade Σ+192% (p=0,000, CPCV +1,59%); NOS 229 trades +1,07%/trade
  Σ+244% (p=0,000, CPCV +2,33%). Geen keeper (slecht 48-54%, goed 9-15%) maar netto winstgevend + hard
  significant → portfolio-rule, klaar om aan te zetten bij live traden. Eerste gedeelde subregel =
  `relvol|L10|standard_deviation` (de coin-agnostisch gemaakte volume-grootte).
