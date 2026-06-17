---
name: brain-sell-engine
description: De sell-engine van nobrainersbot — winst-lock, rule-101, per-tick opslag, handmatige overrides (beste/harde verkoopdatum, klasse), heranalyse-log. Wanneer je iets verandert aan hoe trades sluiten, hoe stops berekend worden, hoe de override-laag werkt, of het detailscherm van een trade.
---

De sell-engine bepaalt voor elke trade hoe en wanneer hij sluit. Hij is **getrouw geport uit
legacy** (de winst-lock staat aan), **instelbaar in data** (alle knobs in `strategies.sl_settings`),
en heeft **per-tick opslag + handmatige overrides + audit log**. Deze skill is de kaart zodat je
niet elke sessie de architectuur opnieuw hoeft te reconstrueren. Voor de full read: zie
[[docs/sell-engine.md]] in het project en de byte-voor-byte spec in
[[docs/methodology/selling-process.md]].

## Eén alinea

Per koop loopt de engine 60 min vooruit, tick-voor-tick. Per tick = stop = **max van vier
mechanismen** (absolute bodem, tijd/winst-ladder, winst-lock, rule-101). Nooit verlaagd. Zakt de
markt door de stop → verkopen op `stop × 0,9996`. Elke tick wordt weggeschreven naar
`coin_sell_ticks` (mirror van de buy-side `engine_subrule_values`). Een handmatige **harde
verkoopdatum** forceert sell op die datum (of eerder bij een trigger). Een handmatige **klasse** is
leidend bij heranalyse. Klasse-veranderingen door een rerun komen automatisch in
`coin_fires_changelog`.

## Terminologie (verplicht — zie ook [[CLAUDE.md]])

- **trades**, niet "fires"/"coin_fires" (tabel heet technisch wel `coin_fires`).
- **winst-lock**, niet "ratchet". Code-functie blijft `lock_profit`. Hele meelopende stop-gedrag:
  **meelopende stop**.

## De vier mechanismen (volgorde in `_determine_stop`)

1. **CHECK 1 — absolute bodem.** `min_sl1 × buy` (DOGEAI 0,988). Eronder = sell.
2. **CHECK 2 — tijd/winst-ladder.** `array_profit` (`[[5,-0.4],[7,-0.1],[8,0],[20,0.5]]`).
   Voldoet niet aan eis voor leeftijd → sell.
3. **CHECK 3 — trailing-breach.** Vorige stop > markt → sell op die stop.
4. **Geen sell → nieuwe stop.** `lock = lock_profit(...)` (winst-lock) + `mult = rule_engine_101(...)`.
   `new_stop = max(lock, mult × market)`. Lock wint, tenzij rule-101 `overrule` geeft (= forced
   sell). Daarna `new_stop = max(new_stop, vorige_stop)` (never lower). Boven de markt → sell.

**Winst-lock tiers** (op `highest_profit_loss = hi` in %):
- hi < 0,15 → bodem
- 0,15–0,21 / 0,21–0,30 / 0,30–0,40 / 0,40–0,50 / 0,50–0,70 → `hp1..hp5`
- 0,70–5 → `buy + (hi/hp6)/100 × buy` (hp6=4, "bewaar ~25%")
- ≥ 5 → `buy + (hi−hp7)/100 × buy` (hp7=15, "bewaar ~50%")

**Harde verkoopdatum** (handmatig) — apart van de vier: bereikt tick T ≥ `hard_sell_dt` en de
engine heeft niet al gesold → forceer sell op die tick.

## Modules (`engine/src/`)

- **`sell_lock.py`** — gedeelde `parse_sl()` + `lock_profit()`. Single source of truth voor de
  ratchet-arithmetiek. Gebruikt door validator én productie-engine.
- **`sell_rule101.py`** — `rule_engine_101(DT, PX, VV, i, buy_dt, buy, market, subrules, max_price)`
  → `(orderstatus, mult)`. Drie subrule-types: `sell_negative_volume`, `sell_x_below`,
  `previous_value` (SL).
- **`sell_engine.py`** — productie. `SellEngine(symbol)`:
  - `.sell(buy_dt, buy, rule, trace=False, hard_sell_dt=None)` → dict met sell-uitkomst,
    optioneel `ticks` (per-tick trail).
  - `.best_sell_in_window(buy_dt, buy, until_dt=None, minutes=None)` → hoogste sell voor déze
    trade, **begrensd tot de volgende koop**.
  - `._determine_stop(...)` → dict met `stop, selling_price, orderstatus, floor, lock, mult`.
- **`sell_ticks.py`** — schrijft trail → `coin_sell_ticks` voor executed trades.
- **`validate_sell.py`** — oracle-replay (bot_signals). Win/loss-richting 95%, exacte sp 463/661.
- **`sell_compare.py`** — 4 varianten (`bare`, `no_ratchet`, `full`, `smooth`) per coin/rule/totaal
  + klasse-verdeling. **Fundament voor de tuning-routine.**
- **`persist_to_brain.py`** — canonical re-fire. Snapshot vorige `profit_loss` vóór de DELETE,
  leest `hard_sell_datetime` uit `coin_moment_labels`, schrijft de changelog.

## Tabellen (brain)

| Tabel | Wat |
|---|---|
| `coin_fires` (= trades) | Eindstand per trade: `selling_price`, `best_sell_price`, `best_sell_datetime` (begrensd tot volgende koop), `selling_datetime`, `profit_loss`. `is_executed=1` = echte trade. |
| `coin_sell_ticks` | 1 rij per (trade, tick): `marketprice`, `profit`, `highest_profit`, `minimum_price`, `lock_price` (pre-clamp), `rule101_mult` (NULL = niet actief), `stoploss_price`, `selling_price`, `orderstatus`. |
| `coin_moment_labels` | Handmatige + legacy overrides. `best_sell_datetime`, `hard_sell_datetime`, `manual_klasse`, `decision`, `category`, `comment`, `source` (`manual`/`legacy`), `manual_set_at` (gezet = "handmatig leidend"). |
| `coin_fires_changelog` | Audit log van klasse-veranderingen door rerun. `field`, `old_value`, `new_value`, `reason` (bv `sell-engine-rerun`). |
| `strategies.sl_settings` | JSON per rule met alle knobs (instelbaar). |

## Instelbare knobs (`strategies.sl_settings`)

| Knop | Default | Wat |
|---|---|---|
| `min_sl1` | 0.988 | absolute bodem |
| `minutes_in_trade1` / `min_sl2` / `minutes_in_trade2` | 6 / 0.99 / 15 | jong/mid bodem |
| `minimal_profit` | 0.8 | drempel waaronder leeftijdsbodems gelden |
| `array_profit` | `[[5,-0.4],[7,-0.1],[8,0],[20,0.5]]` | CHECK-2 ladder |
| `hp_setting1..5` | -0.003 / -0.003 / -0.003 / -0.003 / 0.002 | winst-lock tiers 0,15–0,70% |
| `hp_setting6` | 4 | deler voor "bewaar ~25%" (0,70–5%) |
| `hp_setting7` | 15 | aftrek voor "bewaar ~50%" (≥5%) |

Per coin: `coins.stoploss_multiplier` (0.9996) + `coins.roundingup` (16). Géén onderdeel van
`sl_settings`.

## Frontend

- **`PromisingLabeler.php` → `detail()`** — levert `best_sell` (datum + % + bron met voorrang
  handmatig > legacy > berekend), `hard_sell`, `changes` (changelog), `manual_klasse_set` (vlag),
  markers (incl. `bestsell` en `hardsell`).
- **`saveLabel()`** — persisteert klasse + beste-sell + harde-sell + comment in
  `coin_moment_labels` met `manual_set_at=now()`.
- **Blade** — bron-pill, 2 datetime-inputs, changelog-blok, waarschuwing als handmatige klasse
  gezet is, rode "harde verkoop" lijn in de chart.
- **`CoinMomentLabel::setManual()`** — accepteert best_sell-only / hard_sell-only saves.

## Resultaten — getrouwheid + doorvoer

- **Oracle (DOGEAI 661 closed trades)**: win/loss 95%, exacte sp 463, exacte pl 465, Σ +1279%
  vs legacy +1102%.
- **Live trades (DOGEAI + NOS, doorgevoerd)**: 859→868 trades, **608→548 verlies (60 minder)**,
  Σprofit **+488%→+579%** (+91%). Winnaars → verlies: **0**.

## Vuistregels

- **Heranalyse**: `persist_to_brain.py <coin>` — herrekent álles. Snapshot van vorige
  `profit_loss` vóór DELETE; klasse-overgangen automatisch in `coin_fires_changelog`. Handmatige
  `manual_klasse` blijft leidend (UI gebruikt die over de berekende).
- **Eén bron van waarheid**: ratchet-arithmetiek alleen in `sell_lock.py`. Niet dupliceren in
  validator of engine.
- **NOS vs DOGEAI**: zelfde knobs werken niet voor beide. NOS verliest Σprofit terwijl het
  verliezers redt — per-coin tuning is de volgende stap (eigen sessie, via workflow).
- **Per-tick opslag** als diagnose: bij een gekke uitkomst → kijk eerst de trail in
  `coin_sell_ticks` (welk mechanisme zette welke stop op welke tick).
- **Begrenzing beste sell**: altijd tot de volgende koop (`until_dt`). Een rebuy-rally hoort
  niet bij de vorige trade.

## Open follow-ups

1. **Per-coin tuning-routine** (volgende sessie, via workflow). `sell_compare.py` is het
   meet-instrument: W/V-ratio + Σprofit per coin/rule/variant. Meetlat = som van winst/verlies,
   niet alleen aantal trades.
2. **Keten-analyse**: vroeg eruit + herkopen vs vasthouden (de "snel afbreken en daarna een nieuwe
   aankoop"-overweging).
3. **Harde-drop-detectie binnen het venster** — automatisch verkopen vóór een aankomende scherpe
   daling.
4. **Promising-moment trails** — `sell_ticks.py` uitbreiden naar promising momenten (nu alleen
   executed fires).

Zie ook [[brain-engine]] voor de algemene engine-kaart, [[brain-routines]] voor het routine-runner-
framework waarop de tuning-routine gaat draaien, en [[brain-promising-labeler]] voor het
detailscherm waarop de overrides ingevoerd worden.
