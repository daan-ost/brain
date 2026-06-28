# EPIC I: Incrementele refire (alleen het nieuwe staartje herberekenen)

**Status:** GEBOUWD (Feature 2+3) — 2026-06-27 · **Datum opgesteld:** 2026-06-26 · Refines: schaalplan
([[../findings/optimize-scaling-plan-2026-06-25.md]]) + refire-speedup ([[../findings/refire-speedup-plan-2026-06-26.md]])

## Gebouwd (2026-06-27)

**Fase 0-meting wijzigde de aanpak.** Een refire is voor **99,8%** discovery-fires (gemeten, NOS 30d);
de promising-scan (0,2%) + verkoop/dedup (0,0%) zijn verwaarloosbaar. Daarom is **alleen de fires-
berekening incrementeel gemaakt**; `persist_to_brain` herbouwt de rest (sell, dedup, periods,
coin_fires/coin_periods) gewoon VOLLEDIG — bit-identiek by construction. **Feature 2c (incrementele sell +
behoud coin_fires<T_safe) is daarmee vervallen** (geen meetbare winst), en de twee grens-aanscherpingen
(open-positie-init, gedeelde periode-grens) zijn niet meer nodig — er wordt niets behouden, alles wordt
herbouwd bovenop een gecachete fires-prefix.

| Onderdeel | Bestand |
|---|---|
| Feature 2a — kolommen `last_max_datetime` + `prefix_checksum` | `www/database/migrations/2026_06_27_010000_add_incremental_refire_state.php` |
| Feature 2b — prefix-bewuste fires-cache (`prefix_indicators_checksum`, `series_max_datetime`, `cached_fires_incremental`) | `engine/src/fires_cache.py` |
| Feature 2a/2c — keuze incrementeel↔volledig op prefix-checksum, `--full` flag, modus-log, state-write | `engine/src/persist_to_brain.py` |
| Feature 3 — orakel-vangnet (split bit-identiek + B-pad incrementeel + prefix-mismatch-fallback) | `engine/src/test_incremental_refire.py` |

`brain_volume_found` (in de prefix-checksum) is look-back-stabiel → daily-append gaat in productie écht
incrementeel. De daily routine roept `persist_to_brain.py <coin>` zonder venster aan (consistente
cache-lineage). Feature 1 (incrementele ingest) NIET gebouwd — optioneel, afhankelijk van de live-databron.

---


## Why this exists

Wanneer een munt **dagelijks nieuwe data** krijgt, refiret `persist_to_brain` nu de **hele historie**
opnieuw — ~12-15 min per munt (de discovery-rules 30-34 zijn ongepoort → draaien op élke tick, ~90% van
de refire-tijd). Bij N munten × elke dag wordt dat onhoudbaar. Maar dagelijkse data is **aangroei**: de
oude ticks veranderen niet, er komen alleen nieuwe bij. Dan hoef je alleen het **nieuwe staartje** te
verwerken — een dag aan ticks is een fractie van de reeks, dus de per-tick-kost wordt irrelevant. Doel:
~12 min → **seconden** voor een dagelijkse data-update, **bit-identiek** aan een volledige refire.

## The known gap (huidige staat — gemeten)

1. **Ingest is volledig herladen.** `import_indicators.py:28` doet `DELETE FROM indicators WHERE
   trading_symbol_id=%s` + een volledige `INSERT..SELECT`. Elke import vervangt de hele reeks.
2. **Refire is volledig.** `persist_to_brain.py:101-102` doet `DELETE FROM coin_fires` + `DELETE FROM
   coin_periods` voor de munt, en herberekent dan ALLE fires + ALLE sell-P&L over de volle reeks.
3. **De fires-cache is venster-gebonden, niet aangroei-bewust.** `fires_cache.cached_fires_per_rule`
   (Plan B) sleutelt op een checksum van de **hele** indicators-reeks; nieuwe data → checksum verschuift
   → alle rules van die munt koud. (Plan B helpt alleen als ALLEEN een rule wijzigt, niet bij nieuwe data.)

Gevolg: elke data-update = volledige koude refire. Gemeten NOS (143k ticks, de lichtste munt): ~12 min.

## Key insight

`fires(rule, frm, to)` (`rule_engine.py:124`) **accepteert al een [frm,to]-venster**. De bouwstenen voor
incrementeel zijn er dus deels al. Drie eigenschappen maken aangroei correct:
- **Oude fires zijn stabiel.** Een tick op moment `T` kijkt alléén terug (look-back n ticks / `_vol_rows`
  60-300 min). Nieuwe ticks ná `T` veranderen die look-back niet → de fire-uitkomst van oude ticks blijft
  gelijk. Alleen ticks > `old_max` zijn nieuw.
- **Alleen de grens-trades moeten opnieuw verkocht worden.** Een trade verkoopt binnen `FORWARD_MINUTES`
  (= 60 min, `config.FORWARD_MINUTES`). Een trade die < 60 min vóór `old_max` instapte had een
  verkoop-venster dat tóen nog niet vol was; nieuwe data vult dat venster → die verkoop kan veranderen.
  Trades die > 60 min vóór `old_max` instapten zijn volledig afgehandeld → hun `coin_fires`-rij is finaal.
- **De greedy dedup/shadow-lus moet herstarten vanaf een veilige grens.** Een open positie bij `old_max`
  kan een nieuwe tick tot shadow maken. Geen positie leeft > `FORWARD_MINUTES`, dus herstarten vanaf
  `T_safe = old_max − FORWARD_MINUTES` vangt elke nog-open positie. Alles vóór `T_safe` blijft staan.

## Existing code (reference — waar de builder moet zijn)

| Bestand:regel | Wat |
|---|---|
| `engine/src/import_indicators.py:28` | `DELETE FROM indicators` + INSERT..SELECT (volledig herladen) |
| `engine/src/persist_to_brain.py:101-102` | `DELETE FROM coin_fires/coin_periods` per munt (begin van de refire) |
| `engine/src/persist_to_brain.py:172-186` | `_compute_rule_fires` + `fires_cache.cached_fires_per_rule` (de fires) |
| `engine/src/persist_to_brain.py:214-282` | de greedy dedup + koop-bevestiging + sell-P&L-lus die `coin_fires` schrijft |
| `engine/src/persist_to_brain.py:~54` + `coin_refire_state` | A1 per-coin fingerprint (skip-on-unchanged) — uitbreiden met de aangroei-grens |
| `engine/src/fires_cache.py` | `cached_fires_per_rule`, `rule_fires_fingerprint`, de indicators-checksum (regel 45-48) |
| `engine/src/rule_engine.py:124` | `fires(rule, frm, to)` — neemt al een venster; hergebruik voor de tail |
| `engine/src/sell_engine.py:140` | `sell(buy_dt, buy, rule, ...)` — per trade, scant `FORWARD_MINUTES` vooruit |
| `engine/src/config.py` | `FORWARD_MINUTES` (= 60, de 1-uurs hold) |
| `engine/src/test_fires_cache.py` | bestaand orakel-vangnet-patroon om uit te breiden |

## Decided (vooraf — voorkomt heen-en-weer tijdens bouwen)

| # | Vraag | Beslissing |
|---|---|---|
| 1 | Incrementele ingest verplicht? | **Nee.** De refire-incrementaliteit werkt zolang de (datetime,value,price,gate)-**prefix** stabiel is — óók na een volledig herladen met identieke oude rijen. Incrementele ingest (Feature 1) is een aparte, optionele snelheidswinst die afhangt van de toekomstige live-databron. Bouw eerst de incrementele refire (Feature 2). |
| 2 | Hoe detecteren of het écht aangroei is? | Checksum van de reeks-**prefix** t/m `old_max` (zelfde CRC32-bouwstenen als `fires_fingerprint`). Matcht de prefix-checksum → aangroei → incrementeel. Mismatch (oude data gewijzigd) → **volledige refire** (huidige pad). Nooit raden. |
| 3 | Veilige herstart-grens | `T_safe = old_max − FORWARD_MINUTES`. `coin_fires`/`coin_periods` met datetime ≥ `T_safe` worden weggegooid + herbouwd; de rest blijft staan. Conservatief (FORWARD_MINUTES dekt elke open positie + verkoop-venster). |
| 4 | Bit-identiek is de harde eis | De incrementele refire MOET exact dezelfde `coin_fires` opleveren als een volledige refire. Een orakel-test (Feature 3) is verplicht en bewijst dit op een gesplitste reeks. Geen merge zonder groene test. |
| 5 | Fallback altijd beschikbaar | `persist_to_brain.py --full` (of: prefix-mismatch) forceert de bestaande volledige refire. Incrementeel is een optimalisatie bovenop, nooit de enige weg. |
| 6 | Vectorisatie (Plan B+) | **Niet in deze epic.** Incrementeel maakt de per-tick-kost irrelevant voor de dagelijkse update; vectorisatie blijft geparkeerd voor de zeldzame volledige herbouw (nieuwe munt / revisie). |

## Features (3)

### 1. Incrementele ingest (optioneel, na Feature 2)
**Status:** Approved (lage prioriteit)
Pas `import_indicators.py` aan zodat het bij een bestaande munt alleen rijen met `datetime > MAX(datetime)`
appendt i.p.v. `DELETE` + volledig herladen. Behoud de outlier-null-stap (`null_price_outliers`) op de
nieuwe rijen. Behoud een `--full` pad (delete+reload) voor een nieuwe munt of een geforceerde herbouw.
**Acceptance criteria**
- [ ] Append-pad voegt alleen nieuwe datetimes toe; bestaande rijen ongemoeid (zelfde id's/values).
- [ ] `--full` doet de huidige delete+reload.
- [ ] `null_price_outliers` draait op de nieuwe rijen (de buur-mediaan mag oude rijen als context gebruiken).
- [ ] Een append gevolgd door een `--full` levert byte-identieke `indicators` op (idempotent).

### 2. Incrementele refire (de kern)
**Status:** Approved
Breid `persist_to_brain` (en `fires_cache`) uit met een incrementeel pad, gekozen op de prefix-checksum.

**2a. Aangroei-detectie + grens.** Sla per munt op (uitbreiding van `coin_refire_state`): `last_max_datetime`
+ `prefix_checksum` (CRC32-som van indicator|datetime|value|price|gate t/m `last_max_datetime`). Bij een
refire: bereken de huidige prefix-checksum t/m `last_max_datetime`. Match → incrementeel met
`T_safe = last_max_datetime − FORWARD_MINUTES`. Mismatch of geen state → volledige refire.

**2b. Incrementele fires.** `fires_cache`: cache de per-rule fires van de **stabiele prefix** (t/m
`last_max_datetime`), gesleuteld op de prefix-checksum + rule-def. Bij aangroei: laad de prefix-fires uit
cache, bereken `fires(rule, frm=last_max_datetime, to=new_max)` voor ALLEEN de tail, concat. (De prefix-
fires veranderen niet — bewezen door de look-back-eigenschap.) Schrijf de nieuwe gecombineerde cache weg.

**2c. Incrementele sell + dedup.** Behoud `coin_fires`/`coin_periods` met datetime < `T_safe`. Draai de
greedy dedup + koop-bevestiging + sell-P&L-lus (`persist_to_brain.py:214-282`) alléén over fires met
dt ≥ `T_safe`, met de open-positie-staat correct geïnitialiseerd (een positie geopend < FORWARD_MINUTES
vóór `T_safe` bestaat per definitie niet, dus `open_until=None` bij de start van `T_safe` is veilig —
verifiëren in de orakel-test). Verwijder + herinsert alleen de rijen ≥ `T_safe`. `coin_periods` idem
(herbereken alleen de periodes die ≥ `T_safe` raken).

**Acceptance criteria**
- [ ] Prefix-checksum match → incrementeel pad; mismatch → volledige refire (beide getest).
- [ ] Incrementele fires = prefix-cache + tail; bit-identiek aan `fires(rule, volledige reeks)`.
- [ ] `coin_fires` < `T_safe` ongemoeid; ≥ `T_safe` herbouwd.
- [ ] Een incrementele refire op een munt is **seconden**, niet minuten (meet + log de tail-grootte).
- [ ] Live-log toont "incrementeel: N nieuwe ticks vanaf T_safe" of "volledig (prefix gewijzigd)".

### 3. Orakel-test (verplicht vangnet)
**Status:** Approved
`test_incremental_refire.py`: neem een munt, knip de reeks op een willekeurig moment `M` (bv. 80%).
(a) Volledige refire op de eerste 80% → snapshot `coin_fires`. (b) "Nieuwe data": de resterende 20%
beschikbaar maken → incrementele refire. (c) Volledige refire op 100% → de referentie. Eis: incrementeel
(a+b) == volledig (c), **bit-identiek** op alle `coin_fires`-velden (datetime, rule, is_executed,
shadow_parent, buy_price, selling_price, selling_datetime, profit_loss, klasse). Plus: prefix-mismatch
(wijzig één oude tick) → val terug op volledig + nog steeds correct.
**Acceptance criteria**
- [ ] Incrementeel == volledig bit-identiek op ≥1 munt (NOS), midden-split.
- [ ] Grens-test: een trade die exact op `T_safe` instapt + een open positie over de grens → correct.
- [ ] Prefix-revisie (één oude value gewijzigd) → automatische fallback naar volledig, resultaat klopt.
- [ ] Draait read-only-veilig (snapshot→vergelijk→restore, geen live `coin_fires`-rommel).

## Aanbevolen implementatie-volgorde

1. **Feature 3 eerst (de test als vangnet)** — bouw `test_incremental_refire.py` met de split-methode
   tegen de HUIDIGE volledige refire (de incrementele tak faket eerst = volledig). Zo staat het orakel
   klaar vóór je de incrementele logica schrijft.
2. **Feature 2a** — prefix-checksum + `T_safe` + de incrementeel-vs-volledig keuze (nog met een volledige
   refire als body). Test: keuze klopt (match/mismatch).
3. **Feature 2b** — incrementele fires (prefix-cache + tail) in `fires_cache`. Test bit-identiek tegen
   `fires()` op de volle reeks.
4. **Feature 2c** — incrementele sell+dedup vanaf `T_safe`. Draai Feature 3 → moet bit-identiek zijn.
5. **Meet** op NOS: incrementele update (1 dag tail) moet seconden duren. Log de tail-grootte.
6. **Feature 1 (optioneel)** — incrementele ingest, als de tijdwinst op de INSERT het waard is.

## Nieuwe bestanden aan te maken

| Bestand | Type | Feature |
|---|---|---|
| `engine/src/test_incremental_refire.py` | orakel-vangnet (plain assert) | 3 |
| (uitbreiding) `engine/src/fires_cache.py` | prefix-cache + tail-append | 2b |
| (uitbreiding) `engine/src/persist_to_brain.py` | incrementeel pad + `--full` flag + `T_safe`-lus | 2a/2c |
| (uitbreiding) `coin_refire_state` tabel | `last_max_datetime` + `prefix_checksum` kolommen | 2a |
| (uitbreiding) `engine/src/import_indicators.py` | append-pad + `--full` | 1 |

## Out of scope (bewust niet)

- **Vectorisatie van `fires()` (Plan B+)** — incrementeel maakt 'm overbodig voor de dagelijkse update;
  geparkeerd voor de zeldzame volledige herbouw.
- **Compute-parallel over munten** — aparte optimalisatie (serieel-schrijven tegen de InnoDB gap-lock,
  zie [[../../engine/src/daily_optimization.py]] commit `0ad1b3f`). Kan later los.
- **De live-databron zelf** (streaming ingest / MEXC-pijplijn) — dat is Epic 07/08-terrein; deze epic
  maakt de refire kláár voor dagelijkse aangroei, ongeacht waar de data vandaan komt.
- **Auto-loosen (rq2) caching** — los traagheidspunt, aparte epic.

## Open questions (for Daan)

1. Komt de dagelijkse data via een **append** binnen (nieuwe ticks toegevoegd) of via een **volledig
   herladen** uit een bron? (Bepaalt of Feature 1 nuttig is; Feature 2 werkt in beide gevallen zolang de
   oude (datetime,value,price)-prefix identiek blijft.)
2. Kan oude data ooit **met terugwerkende kracht wijzigen** (correctie/backfill)? Zo ja, hoe vaak? (De
   prefix-mismatch-fallback vangt het sowieso, maar als het vaak gebeurt verdampt de winst.)
3. Is `FORWARD_MINUTES = 60` de enige look-forward? (De `T_safe`-grens leunt erop; een langere hold of
   een hard-sell-override verder weg zou de grens moeten oprekken.)
