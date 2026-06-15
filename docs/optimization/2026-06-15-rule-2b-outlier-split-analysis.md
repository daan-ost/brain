# 2b — Outlier-good split-analyse (read-only, 15 jun 2026)

> **Read-only analyse. Er is NIETS aangepast** — geen rule, database of engine. Dit rapport
> rangschikt *split-kandidaten* en stelt getallen voor. Elke headline is **onafhankelijk via raw
> SQL gereproduceerd** (4 adversariële verify-agents, één per rule + een methode-criticus) tegen de
> `brain`-tabel `indicator_metrics` — een ander datapad dan de Parquet-cache die het script gebruikt.
> Alle vier headlines: **CONFIRMED** (exacte match). De caveats van de criticus staan in §Beperkingen.

Artefact: `engine/src/split_2b.py` → `engine/out/opt/split_2b.json` (33 648 kandidaten; 2 421
genuine-gap scale-safe). Draaien: `engine/.venv/bin/python split_2b.py`.

---

## Wat is 2b? (en waarom het géén RQ1 is)

RQ1 (het 14-jun-rapport) plaatst een drempel op de **slechte rand** en behoudt **alle** goede trades.
Daardoor kan RQ1 per definitie **nooit** een slechte trade verwijderen die *binnen* de goede band
ligt — tussen de goede-cluster en een uitschietende ("outlier") goede trade.

**2b doet precies dat.** Eén goede trade met een extreme indicator-waarde rekt de effectieve band op;
in de opgerekte zone zitten slechte trades die meeliften. 2b **offert die outlier-goede op** (hij
krijgt een **eigen rule** — de uitgestelde 2b-vervolgstap) en verstrakt de band naar de goede-cluster,
waardoor exact die in-band-slecht wegvalt.

**Definities** (identiek aan het RQ1-rapport): GOED = executed `best_upside ≥ 3%`; SLECHT = executed
`best_upside < 0.5%`; MIDDEL (0.5–3%) telt niet mee. Coins: **DOGEAI = 2525**, **NOS = 244**, gepoold.

**Meetgrootheid.** Per rule × metric (indicator, lookback, calc), gepoold over beide coins:
- `cluster_edge` = de op-één-na-extreme goede waarde (de clusterrand ná het opofferen van 1 outlier).
- **`slecht_in_gap`** = aantal SLECHTE trades tussen `cluster_edge` en de outlier-goede. **Dit is de
  headline** — precies de slecht die alléén een split kan verwijderen.
- `baseline_rq1_drop` = slecht voorbij de outlier (wat RQ1 al wegneemt, 0 goed verloren). Voor alle
  vier headlines is dit **0**: RQ1 raakt deze slecht niet.

**Genuine-gate** (om continue-staart-trims en degeneratie te weren): isolatie `gap / cluster_IQR` in
**[2, 30]** (een echte gap, geen bijna-constante cluster), cluster heeft ≥ 5 distincte waarden,
**scale-safe** (geen volumeud-level-metric — die is inert in de engine, zie RQ1-rapport), en de
gedropte slecht vallen op **beide coins** (`min_coin_drop ≥ 1`). Beslissende robuustheidstoets:
**out-of-sample `good_keep`** — leid de clusterrand af op train, kijk of de held-out goede trades in
de cluster blijven (een echte zeldzame outlier ⇒ ~1.0).

---

## Samenvatting — de split-kandidaten, gerangschikt

| Rule | Beste split (ADD-subrule) | slecht weg (engine-onbevestigd) | per coin | OOS min good_keep | Outlier-goede → eigen rule | Oordeel |
|------|---------------------------|--------------------------------|----------|-------------------|----------------------------|---------|
| **21** | `phobos / sideways_lower / lb3 / lower ≥ −3.353` | **10** | DOGEAI 8 · NOS 2 | **0.918** | DOGEAI 2025-03-19 19:02:56 (bu 7.1) | **Sterkste** — grootste drop, OOS-robuust |
| **22** | `phobos / standard_deviation / lb10 / upper ≤ 18.057` | **8** | DOGEAI 4 · NOS 4 | **0.929** | DOGEAI 2025-03-19 13:16:28 (bu **15.4**) | **Schoonste** — perfect cross-coin, high-value outlier |
| **23** | `mfi / diff_percentage_prev_min / lb10 / lower ≥ −6.187` | **6** | NOS 4 · DOGEAI 2 | 0.857 | NOS 2024-01-01 15:26:00 (bu 4.8) | Max-drop, maar **dun** (kleine sample, OOS < 0.90) |
| 23 *(alt)* | `vzo / volatility / lb20 / upper ≤ 0.792` | 3 | NOS 2 · DOGEAI 1 | 0.900 | NOS 2024-03-06 19:05:18 (bu **15.7**) | OOS-veiligste; high-value outlier |
| **20** | `phobos / range_percentage / lb9 / upper ≤ 31.61` | **4** | DOGEAI 3 · NOS 1 | 0.929 | NOS 2024-11-11 16:01:01 (bu 4.1) | **Marginaal** — kleinste winst, nauwelijks-goede outlier |

**Kern.** De rules 21 en 22 hebben een **echt, schoon outlier-split-signaal**; rule 23 is reëel maar
statistisch dun; rule 20 is marginaal (overweeg overslaan). Geen van de vier verstrakt een
*bestaande* subrule-band — alle vier **voegen een nieuwe subrule toe** (`is_existing_subrule_calc =
False`, geverifieerd). Belangrijk: 2b is **inherent risicovoller dan RQ1** — de drempel snijdt dicht
bij de goede-staart, dus OOS `good_keep` is 0.86–0.93 (niet 1.0 zoals RQ1). En de opgeofferde goede
trade is **niet** door een andere rule gedekt → 2b is een **tweedelige** wijziging: de aanscherping
**plus** de companion-rule, anders is het netto "drop N slecht, verlies 1 echte goede".

> ### ⚠️ Geen aanbeveling om te shippen
> Dit is in-sample + OOS bewijs op de huidige executed-trades. Het is **niet** engine-bevestigd
> (geen multi-rule re-fire met dedup). De `slecht_in_gap`-cijfers zijn een **bovengrens** op de
> netto portfolio-reductie (§Beperkingen). Volgende stap vóór enige pilot: companion-rule definiëren
> + volledige re-fire over beide coins.

---

## Per rule — de gap, zichtbaar gemaakt

Elke regel hieronder is onafhankelijk via raw SQL gereproduceerd (`coin_fires` `is_executed=1` JOIN
`indicator_metrics` op `(trading_symbol_id, datetime)`).

### Rule 21 — `phobos / sideways_lower / lb3` (lower) — **STERKSTE**
```
cluster_edge = -3.3531   (outlier-goede: -109.434 @ DOGEAI, bu 7.1, 2025-03-19 19:02:56)
dichtstbij cluster:  [-3.353, -1.792, -1.613, -1.232, -1.124, -1.073]
SLECHT in het gat (10):  -33.85@NOS  -24.19@DOGE  -17.30@DOGE  -17.26@DOGE  -13.86@DOGE
                         -7.18@DOGE  -4.21@DOGE  -4.19@DOGE  -3.53@DOGE  -3.43@NOS
OOS:  time gk=1.0 (39b, bad_drop .10) | 2525→244 gk=1.0 | 244→2525 gk=0.918 (5 goed geofferd)
```
Eén DOGEAI-goede zit op `sideways_lower` = −109 terwijl de hele cluster bij ~−3 ligt — een
zonneklare gap (isolatie 8.8). 10 slecht liggen ertussen. Held-out goede trades blijven in de cluster
(gk ≥ 0.918) → de outlier is echt zeldzaam. De winst is DOGEAI-zwaar (8/2), maar cross-coin robuust.
Dezelfde DOGEAI-goede is een outlier op ~56 genuine metrics → één structurele outlier.

### Rule 22 — `phobos / standard_deviation / lb10` (upper) — **SCHOONSTE**
```
cluster_edge = 18.057   (outlier-goede: 27.107 @ DOGEAI, bu 15.4, 2025-03-19 13:16:28)
dichtstbij cluster:  [15.003, 15.621, 16.484, 16.949, 17.377, 18.057]
SLECHT in het gat (8):  19.25@NOS  19.76@DOGE  20.01@DOGE  20.91@NOS  22.05@DOGE  22.68@DOGE  23.37@NOS  23.37@NOS
OOS:  time gk=1.0 | 2525→244 gk=1.0 | 244→2525 gk=0.929 (4 goed geofferd)
```
**Perfect cross-coin gebalanceerd** (4 DOGEAI + 4 NOS slecht weg) en de op te offeren goede is een
**high-value bu 15.4-trade** — precies het soort distinctieve moment dat een eigen rule verdient. De
gap is bescheidener (isolatie 2.0; de slecht vormen een dichte band 19–23 net boven de clusterrand),
dus dit leunt richting "RQ1-randtrim die RQ1 niet kan maken omdat de outlier de bovenrand blokkeert".
Toch reëel: 8 slecht, 0 via RQ1 bereikbaar, OOS gk ≥ 0.93.

### Rule 23 — `mfi / diff_percentage_prev_min / lb10` (lower) — max-drop, **DUN**
```
cluster_edge = -6.1867   (outlier-goede: -20.07 @ NOS, bu 4.8, 2024-01-01 15:26:00)
SLECHT in het gat (6):  -17.73@NOS  -10.08@NOS  -8.31@DOGE  -7.43@NOS  -6.44@NOS  -6.37@DOGE
OOS:  time gk=1.0 (maar 7g/6b) | 2525→244 gk=0.857 (3 goed geofferd) | 244→2525 gk=1.0
```
6 slecht weg, maar rule 23 heeft de **kleinste sample** (31 goed / 21 slecht gepoold) en de
clusterrand (−6.19) ligt vlak boven een dicht groepje slecht (−6.37, −6.44, −7.43) → OOS `good_keep`
zakt naar 0.857. Behandel de magnitude als indicatief, niet precies.
**Alternatief, OOS-veiliger:** `vzo / volatility / lb20 / upper ≤ 0.792` — slechts 3 slecht, maar
gk ≥ 0.90, en de outlier is een **bu 15.7**-trade (2024-03-06 19:05:18 @ NOS) die een eigen rule
dubbel verdient.

### Rule 20 — `phobos / range_percentage / lb9` (upper) — **MARGINAAL**
```
cluster_edge = 31.61   (outlier-goede: 964.0 @ NOS, bu 4.08, 2024-11-11 16:01:01)
SLECHT in het gat (4):  33.55@DOGE  275.0@NOS  290.91@DOGE  309.32@DOGE
OOS:  time gk=0.929 | 2525→244 gk=0.931 | 244→2525 gk=1.0
```
Slechts 4 slecht, in een dunne/verspreide regio, en de op te offeren goede is **nauwelijks goed**
(bu 4.08, net boven de 3%-grens). De ruwe top-kandidaat had `range_percentage = 964` (een freak-waarde
→ isolatie 78, door de genuine-cap als degeneratie uitgesloten). **Rule 20 heeft geen schoon
single-outlier 2b-signaal** — de sterkere rule-20 splits in de JSON vergen `k ≥ 2` (méér goede trades
opofferen). Aanbeveling: rule 20 niet via 2b aanpakken.

---

## Welke goede trades hebben een eigen rule nodig?

De op te offeren outlier-goede trades (genuine-gap, **niet** door een andere rule gedekt → echte kost).
Een trade die op véél onafhankelijke metrics tegelijk outlier is, is een structureel ander soort
goed-moment en de sterkste kandidaat voor een eigen rule:

| Coin | Datetime | best_upside | #genuine metrics | Hoort bij | Opmerking |
|------|----------|-------------|------------------|-----------|-----------|
| DOGEAI | **2025-03-19 19:02:56** | 7.10 | 56 (2 ind.) | rule 21 | grootste split-payoff (10 slecht) |
| DOGEAI | **2025-03-19 13:16:28** | **15.43** | 7 (2 ind.) | rule 22 | high-value; zelfde **dag** als de rule-21 outlier |
| NOS | 2024-01-01 15:26:00 | 4.79 | 30 (3 ind.) | rule 23 | breedste outlier (3 indicatoren) |
| NOS | 2024-03-06 19:05:18 | **15.67** | — | rule 23 alt | high-value; OOS-veilige variant |
| DOGEAI | 2025-03-02 17:20:07 | **12.78** | 8 (3 ind.) | rule 23 | high-value, ongedekt |
| NOS | 2023-12-04 04:25:21 | 9.79 | 9 (2 ind.) | rule 20 | high-value, ongedekt |

**Opvallend:** de outliers van rule 21 én rule 22 vallen beide op **2025-03-19** (DOGEAI) — dezelfde
handelsdag produceerde distinctieve outlier-goede momenten over meerdere rules heen. Dat suggereert
dat een eventuele companion-rule eerder een **regime/dag-kenmerk** vangt dan rule-specifieke ruis.

---

## Beperkingen (door de methode-criticus bevestigd — vóór shippen lezen)

1. **Het zijn NIEUWE subrules, geen band-aanscherpingen.** Alle vier winnende metrics zijn geen
   bestaande subrule van hun rule (`is_existing_subrule_calc = False`, per SQL geverifieerd). De
   slecht-drop is geldig op de executed-set (executed trades passeerden álle bestaande subrules; een
   AND-subrule is monotoon — verwijdert alleen). Maar een **toegevoegde** subrule filtert de héle
   historie op een dimensie die nog nooit een rule-constraint was → de verplichte full-period re-fire
   is hier nóg belangrijker.

2. **Cache-drop = bovengrens op netto portfolio-reductie.** Twee mechanismen:
   (a) *Same-timestamp shadow-promotie* — door single-position dedup vuurt per (coin, datetime) maar
   één rule; valt een slecht weg, dan kan een co-located shadow promoveren. **Empirisch gecheckt: 0**
   van de in-gap-slecht heeft een andere rule binnen ±3 min, voor alle vier headlines → cache-drop =
   netto-drop *voor deze kandidaten*.
   (b) *Slot-freeing-promotie* — een weggevallen slecht gaf een positie-slot vrij; een later
   geblokkeerde fire kan nu alsnog executen (mogelijk zelf slecht). Dit ziet **noch** de cache-analyse
   **noch** `rq2_refire_check.py` (die re-fired één rule, niet de dedup-portfolio). Alleen een echte
   multi-rule re-fire met dedup bevestigt dit. **Niet gedaan.**

3. **In-sample + OOS, niet engine-bevestigd.** Anders dan RQ1 (dat `full_validation` draait) toont 2b
   nu OOS `good_keep`/`bad_drop` (time + beide cross-coin-richtingen), maar geen engine-re-fire. De
   OOS `good_keep` is 0.86–0.93 (niet 1.0): held-out goede trades vallen soms in de offer-zone — 2b is
   **structureel zwakker bewijs dan RQ1**.

4. **De opgeofferde goede is een echte, ongedekte kost.** `n_sacrificed_covered_elsewhere = 0` voor
   alle vier. Zonder de companion "eigen rule" is het netto-effect "drop N slecht, verlies 1 echte
   goede". 2b is dus **per definitie tweedelig**: aanscherping + companion-rule samen, of niets.

5. **De genuine-gate is een filter, geen certificaat.** Hoge isolatie kan uit een bijna-constante
   cluster komen (rule 20's 964-freak, daarom de cap op 30); lage isolatie (rule 22 @ 2.0) kan een
   continue-staart-trim zijn. De OOS `good_keep` is de beslissende toets, niet de isolatie alleen.

---

## Vervolgstappen (als 2b doorgezet wordt)

1. **Begin bij rule 22** (schoonste: cross-coin gebalanceerd, OOS gk 0.93, high-value outlier) en
   **rule 21** (grootste drop, OOS-robuust). Rule 23 alleen met de small-sample-caveat; rule 20 overslaan.
2. **Definieer de companion-rule** voor de outlier-goede (de uitgestelde 2b-stap: nieuw `rule_number`,
   eigen subrules rond het outlier-profiel). Zie `brain-engine` §"Adding a NEW rule_number".
3. **Multi-rule full-period re-fire met dedup** (aanscherping + companion samen) over beide coins;
   houd alleen aan als totaal-slecht strikt daalt én 0 executed-goed netto verloren — de
   `auto_apply`-gate uit `brain-routines`.

*Reproduceerbaar uit `engine/out/opt/split_2b.json`. Dit rapport wijzigt niets.*
