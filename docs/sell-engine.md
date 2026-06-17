# Sell-engine — functioneel + technisch

Eén document voor wat de sell-engine doet, hoe de logica werkt, waar het in de
code zit, hoe je het op het scherm bedient, en wat handmatig instelbaar is.
De **getrouwe rebuild** van legacy is af; de winst-lock staat aan; per-tick
opslag draait; handmatige overrides zijn ingebouwd.

Voor de byte-voor-byte legacy-specificatie: zie
[methodology/selling-process.md](methodology/selling-process.md).

## In één alinea

Voor elke koop loopt de engine de prijs tick-voor-tick vooruit (max 60 min).
Per tick berekent hij de stop-loss als **de hoogste** van een aantal mechanismen
(de absolute bodem, de tijd/winst-ladder, de winst-lock, en rule-101 sell-signalen).
De stop wordt **nooit verlaagd**. Zakt de prijs door de stop, dan verkopen.
De verkoopprijs = `stop × 0,9996` (net onder de stop, zodat de exchange snel hapt).
Per minuut wordt elke tussenwaarde opgeslagen in `coin_sell_ticks`, zodat de
hele verkoop-trail per trade terug te kijken is.

## Functioneel — wat de gebruiker ziet en bedient

### Het detailscherm van een trade

Per trade staat in de detail-modal:

- **Beste sell-datum + %** met een bron-pill: **handmatig** (oranje), **legacy**
  (blauw), of **berekend** (grijs). De voorrang is: jouw handmatige aanpassing
  wint; daarna legacy; daarna de berekende.
- **Onze sell-resultaten** (verkoopdatum, profit %, hoogste/laagste % in de hold).
- **Heranalyse-log**: per regel "klasse: oud → nieuw (reason)". Wordt automatisch
  bijgewerkt zodra de sell-engine een trade anders waardeert na een re-run.
- **Twee invoervelden** onderaan het label-blok:
  - **Beste sell-datum overschrijven** — leeg laten = berekende waarde wordt
    gebruikt.
  - **Harde verkoopdatum** — de sell-engine moet uiterlijk op deze datum
    verkopen. Een normale trigger (bodem, ladder, winst-lock, rule-101) eerder
    blijft mogelijk; laten lópen ná deze datum kan niet.
- **Kwaliteit-override (klasse)** — handmatig **leidend**. Een +1%-trade mag jij
  "slecht" noemen; de automatische heranalyse overschrijft dat niet en toont
  de waarschuwing "handmatige kwaliteit is leidend".

### De grafiek

- Blauwe lijn = koop, rode lijn = onze sell, paarse lijn = beste sell-datum
  (uit de bron met voorrang), rode "harde verkoop"-lijn als je die hebt gezet.

### Wat er gebeurt bij een heranalyse

`persist_to_brain.py` herrekent álle trades met de huidige sell-engine.
Tijdens die rerun:

- de **vorige** profit_loss per trade wordt onthouden vóór de tabel wordt
  geleegd;
- na de herberekening wordt elke klasse-overgang (`slecht → middel`, etc.)
  geschreven naar **`coin_fires_changelog`** met `reason='sell-engine-rerun'`;
- handmatige overrides blijven leidend — een handmatig op "slecht" gezette
  trade verandert de UI niet, ook al berekent de engine "goed".

## De vier mechanismen — uitleg en wanneer ze pakken

Volgorde van evaluatie in `determine_stop()`. Eerste sell-trigger sluit
de trade; pakt geen enkele, dan wordt de nieuwe stop berekend.

### 1. Absolute bodem (CHECK 1)

`minimum_price = min_sl1 × buy` (DOGEAI: 0,988 → max 1,2% verlies). Zakt
de marktprijs eronder, dan **verkopen**, prijs = marktprijs × 0,9996.
Bedoeld als hard plafond op je verlies.

### 2. Tijd/winst-ladder (CHECK 2)

`array_profit = [[5,-0.4],[7,-0.1],[8,0],[20,0.5]]` — "na 5 min minstens
−0,4%, na 7 min minstens −0,1%, na 8 min minstens 0%, na 20 min minstens
+0,5%". Voldoet de trade niet aan de eis voor zijn leeftijd, dan **verkopen**.
Stopt trades die te traag op gang komen.

### 3. Trailing-breach (CHECK 3)

De stop uit de vorige tick werd niet gehaald → de prijs is door de
meelopende stop gezakt → **verkopen** op die stop.

### 4. Nieuwe stop berekenen — winst-lock vs rule-101

Pakte geen van de drie hierboven, dan bouwen we de nieuwe stop:

- **Winst-lock (`lock_profit`)** — keys op de piek-winst sinds koop (`hi`):
  - hi < 0,15%: terug naar de bodem;
  - tiers `hp1..hp5` voor pieken 0,15–0,70%;
  - `0,70 ≤ hi < 5%`: `buy + (hi/hp6)/100 × buy` met `hp6=4` ⇒ "bewaar ~25%
    van de piek";
  - `hi ≥ 5%`: `buy + (hi−hp7)/100 × buy` met `hp7=15` ⇒ "bewaar ~50% van de
    piek". (Voorbeeld: piek +20% → stop op +5% boven koop.)
- **Rule-101** — geeft een **stop-multiplier** terug:
  - "volume slaat negatief om" → stop = `0,98 × hoogste prijs sinds koop`;
  - "prijs te hard gezakt over laatste X metingen" → forced sell (`overrule`);
  - "≥4 van laatste 5 ticks negatief + dalende prijs" → forced sell.
- **Combineer**: `new_stop = max(winst-lock, rule101_mult × markt)`. De
  winst-lock wint, tenzij rule-101 een `overrule` (forced sell) geeft.
- **Never lower**: `new_stop = max(new_stop, vorige_stop)`.
- Komt de stop boven de markt te liggen → **verkopen**.

### Harde verkoopdatum (handmatig)

Apart van de vier: als jij een **harde verkoopdatum** zet, dan verkoopt de
engine **uiterlijk** op die datum (op de eerste tick op of na). Een
normale trigger eerder blijft pakken.

## Wat is instelbaar

Alle knobs leven in **`strategies.sl_settings`** (JSON, één rij per rule).

| Knop | Default (legacy-getrouw) | Wat doet het |
|---|---|---|
| `min_sl1` | 0.988 | absolute bodem-multiplier |
| `minutes_in_trade1` / `min_sl2` / `minutes_in_trade2` | 6 / 0.99 / 15 | jong/mid bodem |
| `minimal_profit` | 0.8 | onder dit profit-% gelden de leeftijdsbodems |
| `array_profit` | `[[5,-0.4],[7,-0.1],[8,0],[20,0.5]]` | tijd/winst-ladder (CHECK 2) |
| `hp_setting1..5` | -0.003 / -0.003 / -0.003 / -0.003 / 0.002 | winst-lock tiers voor pieken 0,15–0,70% |
| `hp_setting6` | 4 | deler voor de "bewaar ~25%"-tier |
| `hp_setting7` | 15 | aftrek voor de "bewaar ~50%"-tier |

Per coin: `coins.stoploss_multiplier` (verkoop-multiplier, 0.9996) en
`coins.roundingup` (decimalen). Géén onderdeel van `sl_settings`.

Handmatige overrides per trade in `coin_moment_labels`:
- `best_sell_datetime` — overschrijft de berekende beste-sell-datum
- `hard_sell_datetime` — forceert sell uiterlijk op die datum
- `manual_klasse` — overschrijft de berekende klasse (goed/middel/slecht)
- `manual_set_at` — timestamp; aanwezig ⇒ "handmatig is leidend"

## Technisch — waar wat zit

### Engine-code (`engine/src/`)

- **`sell_lock.py`** — `parse_sl(raw)` (lees de knobs uit de JSON) en
  `lock_profit(profit, minutes, hi, buy, market, sl)` (de winst-lock).
  Pure functies, gedeeld door **validator én productie-engine** — één
  source of truth voor de ratchet-arithmetiek.
- **`sell_rule101.py`** — `rule_engine_101(...)` met de drie subrule-types
  (`sell_negative_volume`, `sell_x_below`, `previous_value`/SL).
- **`sell_engine.py`** — `SellEngine(symbol)`:
  - `.sell(buy_dt, buy, rule, trace=False, hard_sell_dt=None)` → dict
    met `selling_price`, `selling_date`, `profit_loss`, `hi`, `lo`,
    `hi_price`, `hi_dt`, optioneel `ticks`.
  - `.best_sell_in_window(buy_dt, buy, until_dt=None, minutes=None)` →
    de hoogste sell voor déze trade, **begrensd tot de volgende koop**
    (een rebuy-rally hoort niet bij ons).
  - `._determine_stop(...)` → `dict(stop, selling_price, orderstatus,
    floor, lock, mult)` zodat de aanroeper de tussenwaarden kan zien.
- **`sell_ticks.py`** — schrijft de volle trail naar `coin_sell_ticks`
  voor alle executed fires (1 rij per tick).
- **`validate_sell.py`** — oracle-replay over `bot_signals` om de fidelity
  te meten.
- **`sell_compare.py`** — draait de engine in 4 varianten (`bare`,
  `no_ratchet`, `full`, `smooth`) over alle trades en toont per coin,
  per rule en totaal de winst/verlies-ratio + Σprofit + klasse-verdeling.
  Fundament voor de tuning-routine.
- **`persist_to_brain.py`** — de canonical re-fire/write-path. Snapshot
  vorige `profit_loss` voor de DELETE, leest `hard_sell_datetime` uit
  `coin_moment_labels` en geeft het mee aan de engine, schrijft de trades
  + de `coin_fires_changelog`-rijen voor klasse-veranderingen.

### Tabellen (`brain`)

- **`coin_fires`** — de trades. Nieuwe kolommen: `selling_price`,
  `best_sell_price`, `best_sell_datetime` (begrensd tot volgende koop),
  `selling_datetime`, `profit_loss`. `is_executed=1` = echte trade;
  `=0` = schaduw (binnen een open positie).
- **`coin_sell_ticks`** — 1 rij per (trade, tick) met `marketprice`,
  `profit`, `highest_profit`, `minimum_price`, `lock_price` (winst-lock
  pre-clamp), `rule101_mult` (null = niet actief), `stoploss_price`,
  `selling_price`, `orderstatus`. Geverifieerd byte-voor-byte gelijk
  aan de legacy sell-log (sim 15212).
- **`coin_moment_labels`** — handmatige + legacy-overrides. Kolommen
  `best_sell_datetime`, `hard_sell_datetime`, `manual_klasse`, `decision`,
  `category`, `comment`, `source` (`manual` / `legacy`), `manual_set_at`.
- **`coin_fires_changelog`** — audit log van klasse-veranderingen door
  de heranalyse. Eén rij per overgang met `field`, `old_value`,
  `new_value`, `reason`.
- **`strategies`** — per rule de `sl_settings` JSON met álle knobs.

### Frontend (`www/`)

- **`app/Livewire/Trades/PromisingLabeler.php`** — de detail-modal.
  `detail()` levert: stats (zonder "beste upside %"), `best_sell`
  (datum + % + bron met voorrang), `hard_sell`, `changes` (changelog),
  `manual_klasse_set` (vlag), markers voor de chart (incl. `bestsell` en
  `hardsell`-lijn). `saveLabel()` persisteert alle vier velden (klasse,
  beste-sell, harde-sell, comment) in `coin_moment_labels` met
  `manual_set_at = now()`.
- **`resources/views/livewire/trades/promising-labeler.blade.php`** —
  bron-pill, twee datetime-inputs, changelog-blok, handmatig-leidend
  waarschuwing, rode "harde verkoop"-lijn in de chart.
- **`app/Models/CoinMomentLabel.php`** — `setManual()` accepteert nu ook
  best_sell-only / hard_sell-only saves. Casts voor de drie datetime-velden.

### Resultaten — getrouwheid + doorvoer

- **Oracle-fidelity** (DOGEAI 661 closed trades): win/loss-richting 95%
  (530→630), exacte selling_price 333→463, exacte profit_loss 334→465,
  totale P&L +1279% vs legacy +1102%.
- **Doorgevoerd in de live trades** (DOGEAI + NOS): 859→868 trades,
  608→548 verlies (**60 minder**), Σprofit +488,3% → **+579,2%** (+91%).
- Klasse-verschuiving: slecht 608→548, middel 165→241 (+76), goed 86→79.
  De winst-lock kan een stop alleen omhoog zetten — dus 0 trades zakken
  van winst naar verlies; 60 zakken van verlies naar winst.

## Roadmap (na deze fase)

1. **Per-coin tuning-routine** (eigen sessie, via workflow): de knobs
   per coin/rule bijstellen op basis van nieuwe trades, met als
   meetlat de som van winst/verlies (NOS verliest nu Σprofit terwijl
   het verliezers redt — de routine moet die ruil expliciet maken).
2. **Keten-analyse**: vroeg eruit + herkopen vs vasthouden (de "snel
   afbreken en daarna een nieuwe aankoop"-overweging).
3. **Harde-drop-detectie binnen het venster** — automatisch verkopen
   vóór een aankomende scherpe daling (los van de winst-lock-respons).
4. **Promising-moment trails** — sell_ticks.py uitbreiden naar de
   promising momenten (niet alleen executed fires).
