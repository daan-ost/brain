# ONDERZOEK: relatieve volume-maat als precisie-lever voor rule 30

## Opdracht (één alinea)

Voeg aan de rule-discovery engine (`engine/src/discovery/`) een **relatieve, per-munt-genormaliseerde
volume-feature** toe, en toets of die de coin-agnostische discovery-rule (nu "rule 30") **selectiever
maakt** — minder verliezers — zónder de generalisatie te verliezen. Draai de gepoolde ontdekking
(`discovery/pooled.py`) opnieuw mét deze feature en rapporteer, met dezelfde statistische discipline
(CPCV buiten-data + toeval-toets), of een relatieve-volume-subregel het verschil maakt op **beide munten
(DOGEAI 2525 + NOS 244)**.

## Waarom (rationale)

- Rule 30 vuurt **buiten de bestaande volume-poort** (`brain_volume_found` / `check_volumeud_3`) om — dat
  was de premisse van Epic RD: de promising trades worden juist door die poort gemist. Gevolg: rule 30 mist
  de precisie-lever die 20-23 sterk maakt en heeft daardoor **veel goedkope verliezers** (DOGEAI 65% slecht,
  NOS 47% slecht).
- Het **enige statistisch bevestigde signaal** uit al het eerdere onderzoek was **volume-GROOTTE**
  (`volumeud|standard_deviation`, p=0,001 pre-registered). Maar dat is **schaal-afhankelijk** (DOGEAI-volume ≠
  NOS-volume), dus het is uit de **coin-agnostische** rule gevallen (zie `pooled.py` → `SCALE_INVARIANT`
  sluit `standard_deviation` uit).
- Een **relatieve** volume-maat (volume ÷ per-munt-basislijn) maakt die volume-grootte schaal-vrij → bruikbaar
  als **gedeelde band** voor alle munten, en brengt precies dat bewezen signaal terug. Dit is exact hoe 20-23
  hun volume-poort coin-agnostisch maken: per munt een eigen `min_volume` (uit `coin_rule_settings`).
  (De uitgestelde Parquet-feature-store rekent volumeud al relatief als `value / min_volume` — zie
  `engine/src/feature_store.py` — dus het mechanisme is bekend, alleen nog niet in de live discovery-engine.)

## Hoe de engine nu werkt (referentie — lees dit eerst)

- Draaien (vanuit `engine/src`): `../.venv/bin/python -u -m discovery.pooled`. LET OP: zet
  `NUMBA_DISABLE_JIT=1` vóór `import pysubgroup` (gebeurt al in `segment.py`).
- **Featureset** staat in `engine/src/discovery/data.py`:
  - `INDS1` = (volumeud, phobos, obv-x-value, vzo, mfi) — `volumeud` is hier **RUW** (uit `parent_crossgroup.AsOf`).
  - `LB1` = (5, 10, 20); `METRICS1` = 12 vorm/relatieve metrics (uit `parent_spoor1.lean_metrics`).
  - `feature_cols()` = INDS1 × LB1 × METRICS1 + prijs-features. `_all_tick_arrays()` cachet ze vectorized
    over alle ticks (dev-cache in `.cache/`, signatuur-gebaseerd).
- **Coin-agnostische ontdekking** staat in `engine/src/discovery/pooled.py`:
  - `SCALE_INVARIANT` = de set metrics die als **gedeelde band** mogen (relatief/%/vorm). `standard_deviation`
    en `diff_lowest_value_period` staan er NIET in (ruwe schaal).
  - De funnel kiest een (feature, side) met een **gedeelde drempel** (percentiel over de gepoolde
    promising-ticks van beide munten) en neemt 'm alleen op als hij op ÁLLE munten indikt + de OOS-trefkans
    overeind houdt. `refine_quality()` = verlies-reductie (gedeelde drempels uit gepoolde winnende trades).
- **Validatie**: `engine/src/discovery/validate.py` — CPCV (`refit=False` voor de gedeelde rule),
  toeval-toets + Šidák-correctie, schone willekeurige nullijn, incrementeel op 20-23 (uit `coin_fires`).
- **Lat (oordeel)**: `engine/src/discovery/report.py` — 20-23-niveau: selectiviteit ≤ ~0,1% ticks,
  gem ≥ +0,7%/trade, slecht ≤ 45%, goed ≥ 19%, Σ>0, CPCV>0, p<0,05. Huidige stand van de rule:
  DOGEAI 0,24% / +0,64%/trade / 65% slecht / CPCV +1,36% ; NOS 0,13% / +1,14%/trade / 47% slecht / CPCV +2,90%.

## Beslissingen (vooraf vastgelegd — niet opnieuw vragen)

| # | Vraag | Beslissing |
|---|---|---|
| 1 | Welke volume-maat? | **Relatief**, per munt genormaliseerd — NIET de bestaande `check_volumeud_3`-poort (die mist de trades per premisse) en NIET ruw volume (schaal-afhankelijk). |
| 2 | Welke basislijn? | Primair `min_volume` uit `coin_rule_settings` (zoals `feature_store.py`: `value / min_volume`). Vergelijk eventueel met een voortschrijdende mediaan van volumeud over een venster. |
| 3 | Hoe meenemen? | Als nieuwe **schaal-invariante** feature(s) → toevoegen aan `SCALE_INVARIANT` zodat de funnel ze als gedeelde band mag gebruiken. |
| 4 | Discipline | Zelfde als de engine: CPCV buiten-data + **toeval-toets ALTIJD** + Šidák. Een vondst telt pas als ze op **beide munten** standhoudt (of expliciet coin-specifiek gemarkeerd). |
| 5 | Getrouwheid | De nieuwe feature moet reproduceerbaar zijn via het live-pad zodat `apply.py` 'm kan vastleggen (de relatieve-volume-maat = `min_volume`-genormaliseerd, sluit aan op `coin_rule_settings` — net als 20-23). |

## Stappen

1. **Feature toevoegen** in `data.py`: voeg een relatieve-volume-indicator toe, bv. `relvol` =
   `volumeud_value / min_volume(munt)` (haal `min_volume` per munt op uit `coin_rule_settings`; meerdere rules
   hebben licht verschillende waarden — kies één, bv. rule 21, of het gemiddelde, en leg de keuze vast). Bereken
   daarover dezelfde lean-metrics (current_value/std/skewness/range_percentage/…) over LB1. Voeg de kolommen
   toe aan `feature_cols()` + `_all_tick_arrays()` (en invalideer de dev-cache: signatuur verandert vanzelf,
   anders `.cache/` legen).
2. **Schaal-invariant markeren**: zet de relevante relvol-metrics in `pooled.py` → `SCALE_INVARIANT`
   (inclusief de magnitude-variant — relvol is per definitie schaal-vrij, dus `relvol|std` MAG nu wél mee).
3. **Opnieuw ontdekken**: draai `python -m discovery.pooled`. Kijk of de funnel een relvol-(sub)regel opneemt,
   hoe ver hij indikt, en of de OOS-trefkans op beide munten blijft staan.
4. **Vergelijk** met de huidige rule (cijfers hierboven): zakt **slecht%** richting ≤45%? Stijgt gem/trade?
   Komt de selectiviteit dichter bij ~0,1%? Blijft CPCV positief + toeval-toets significant op beide munten?
5. **Pre-registered toeval-toets** op de relvol-hypothese specifiek (drempel uit de labels, niet uit het
   optimaliseren van de winst) — zoals `parent_perm_fixed.py` deed voor `volumeud-std`. Reproduceert de
   relatieve variant de p=0,001-vondst, nu coin-agnostisch?

## Succescriterium

De relatieve-volume-feature is een **keeper-lever** als, op **beide munten**: (a) een relvol-subregel door de
CPCV-gestuurde funnel + verlies-reductie wordt opgenomen (overleeft buiten de trainingsdata), én (b) de
gedeelde rule daardoor **minder verliezers** krijgt (slecht% omlaag, richting ≤45%) zonder dat de trefkans
instort, én (c) de toeval-toets significant blijft ná Šidák. Bonus: de bevestigde `volumeud-std`-vondst keert
terug als coin-agnostische `relvol`-variant.

Rapporteer **compact per munt** (vast format): `{munt}: N/M promising groepen | goed/middel/slecht | Σprofit%`
+ selectiviteit, gem/trade, CPCV-OOS, p-waarde — naast de huidige stand, zodat de winst van de relvol-feature
direct zichtbaar is.

## Eerlijke verwachting / valkuil

Het **2-munten-plafond** blijft de bovengrens: "meer features ≠ meer precisie op weinig data" (eerder bewezen).
Verwacht een verbetering van de precisie (de relvol-feature re-activeert het enige bewezen signaal), maar toets
hard — een schijn-verbetering die alleen in-sample bestaat is de standaard valkuil. Als de relvol-feature de
slecht% niet structureel (CPCV-bevestigd op beide munten) verlaagt, is dat zelf een waardevolle uitkomst:
bevestiging dat de bindende grens écht de hoeveelheid munten is, niet de volume-meting.

## Niet in scope

- **Activeren/deployen** van een verbeterde rule → aparte stap (`apply.py`, gap-fill vs. globale-dedup).
- **Meer munten** onboarden → Epic 07 (de uiteindelijke hefboom).
- De volledige Parquet feature-store → uitgesteld (de in-memory featureset volstaat voor 2 munten).
- De bestaande `check_volumeud_3`-poort wijzigen → bewust niet; dit gaat om een **nieuwe, andere** volume-maat.
