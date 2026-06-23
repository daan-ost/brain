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

## Outlier-guard op prijs-ticks (datakwaliteit — verplicht weten)

Eén corrupte feed-tick (decimaal-/schaal-glitch) in `brain.indicators` veroorzaakt downstream
**absurde winst**: de sell-engine scant ~60 min vooruit naar de hoogste verkoopprijs en "verkoopt"
elk koop-moment in dat venster tegen die ene rotte tick. Bekend geval: DOGEAI (2525), `volumeud`,
**2025-06-10 13:06:37**, `price=23044` (echte prijs ~0,02304) → 15 rijen in `coin_moment_sells` met
`profit_loss` tot ~100.000.000%. De engine doet niets fout (garbage in, garbage out) — dit is een
**datakwaliteit**-probleem.

- **`outlier_guard.py`** — gedeelde guard. `OUTLIER_FACTOR = 10.0`, `OUTLIER_WINDOW = 5`. Een tick
  is een outlier als zijn `price` meer dan `OUTLIER_FACTOR`× afwijkt van de **mediaan van zijn buren**
  (robuust tegen één losse uitschieter én tegen een legitieme trend; een echte pump van ~2-3x blijft
  staan). Functies: `is_price_outlier`, `outlier_indices`, `filter_outliers(DT,PX,VV)`,
  `null_price_outliers(conn, symbols, indicators)`.
- **Twee plekken (defense-in-depth):**
  1. **LEIDEND — bij ingest** (`import_indicators.py`): na de `INSERT..SELECT` zet
     `null_price_outliers` `price=NULL` voor elke outlier per (symbol, indicator) in `brain.indicators`.
     Een re-import kopieert de glitch wél opnieuw uit legacy, maar de guard verwijdert hem **meteen weer**.
     `bot_signals` blijft strikt read-only.
  2. **Vangnet — in `SellEngine.__init__`**: `filter_outliers` gooit een outlier-tick alsnog uit `PX`
     vóór het scannen, mocht er toch een ongezuiverde tick in de DB staan.
- **NULL i.p.v. delete**: alles downstream filtert al op `price IS NOT NULL`, dus NULL-en verwijdert de
  tick uit alle prijslogica terwijl de `value`-rij blijft staan.
- **Opschonen na een glitch**: draai de guard tegen de huidige data (of re-import), dán
  `sell_promising.py <coin> --run`. Verifieer: `coin_moment_sells WHERE profit_loss>1000` → 0 en
  `indicators WHERE price>1` → 0. Een fires-rebuild is alleen nodig als een **executed** trade in het
  glitch-venster viel (bij DOGEAI was dat niet zo → `coin_fires` was al schoon).
- **Tijdelijk vangnet in de UI**: `Trades\Index::summaryRows()` filtert `>1000%` weg
  (`SANE_MIN_PL`/`SANE_MAX_PL`). Sinds deze guard hoeft die niets meer weg te filteren; laat hem staan
  als defense-in-depth.
- Tests: `test_outlier_guard.py` (plat assert-script) — glitch afgewezen, normale reeks + echte pump
  intact, drempel-grens, `filter_outliers`-uitlijning.

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
- **`validate_sell.py`** — oracle-replay (bot_signals). **Geparametriseerd op symbol** (`validate_sell.py [symbol_id]`,
  default 2525); leest `SELL_MULT`/`ROUNDING` per munt uit `wp_trading_symbols` (NOS rondt op 5 af, DOGEAI 16).
  Baseline win/loss-richting: DOGEAI 630/661 = 95,3%, **NOS 626/768 = 81,5%** (NOS reproduceert legacy minder
  getrouw → oracle-floor per munt = "niet slechter dan eigen basislijn", niet absoluut 90%).
- **`sell_compare.py`** — 4 varianten (`bare`, `no_ratchet`, `full`, `smooth`) per coin/rule/totaal
  + klasse-verdeling. Fundament voor het meet-instrument.
- **`sell_tuning.py`** — **meet-instrument tuning-routine** (read-only). `measure()` → per (coin,rule)
  mediaan-split (oude helft=train, nieuwe=holdout), netto Σprofit-ruil, klasse-transitiematrix, flips,
  verdict `SAFE/OVERFIT/ZWAK/INERT/UNSAFE`. Knob-injectie via `eng.sl_by_rule` (geen monkey-patch).
  Schrijft `out/opt/sell_tuning_<date>.json`.
- **`sell_apply.py`** — **gated apply** (`apply_safe(emit, apply, report)`). Default propose-only;
  muteert alleen met `--apply`. Schrijft de override naar `coin_strategies`, refire, gate (Σprofit niet
  omlaag ÉN verliezers niet omhoog), keep-of-revert. Handmatige overrides nooit aangeraakt.
- **`persist_to_brain.py`** — canonical re-fire. Snapshot vorige `profit_loss` vóór de DELETE,
  leest `hard_sell_datetime` uit `coin_moment_labels`, schrijft de changelog. Env `CHANGELOG_REASON`
  bepaalt de changelog-reden (default `sell-engine-rerun`; de tuning zet `tuning-routine-<rule>-<knob>`).

## Tabellen (brain)

| Tabel | Wat |
|---|---|
| `coin_fires` (= trades) | Eindstand per trade: `selling_price`, `best_sell_price`, `best_sell_datetime` (begrensd tot volgende koop), `selling_datetime`, `profit_loss`. `is_executed=1` = echte trade. |
| `coin_sell_ticks` | 1 rij per (trade, tick): `marketprice`, `profit`, `highest_profit`, `minimum_price`, `lock_price` (pre-clamp), `rule101_mult` (NULL = niet actief), `stoploss_price`, `selling_price`, `orderstatus`. |
| `coin_moment_labels` | Handmatige + legacy overrides. `best_sell_datetime`, `hard_sell_datetime`, `manual_klasse`, `decision`, `category`, `comment`, `source` (`manual`/`legacy`), `manual_set_at` (gezet = "handmatig leidend"). |
| `coin_fires_changelog` | Audit log van klasse-veranderingen door rerun. `field`, `old_value`, `new_value`, `reason` (`sell-engine-rerun` of `tuning-routine-<rule>-<knob>`). |
| `strategies.sl_settings` | JSON per rule met alle knobs (globale defaults). |
| `coin_strategies` | **Per-(coin,rule) override-laag** bovenop `strategies` (`UNIQUE(trading_symbol_id, rule_number)`, `sl_settings` JSON met alleen de afwijkende knobs). `SellEngine` merget per-coin eroverheen (`merge_sl()`, per-coin wint mits NOT NULL, erft de rest). Leeg = byte-identiek aan globaal. Hierdoor kan DOGEAI (snel) anders afgesteld zijn dan NOS (traag). |

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

## Per-coin tuning-routine (gebouwd — [[sell-tuning-routine-plan]])

Een dagelijkse routine die de instelknoppen **per munt** beter afstelt op nieuwe data. Filosofie:
*faithful first, measurably better, gated apply*.

- **Opslag**: `coin_strategies` (dunne override-laag, zie tabel boven). Leeg = nul gedragsverandering.
- **Meten** (`sell_tuning.py`): per (coin,rule) een kleine grid rond de huidige waarde
  (`hp6/hp7/min_sl1/minimal_profit`; `hp_setting8` is dood, uitgesloten). Meetlat = **netto Σprofit**
  (won−lost), **holdout leidend**. De holdout-split is **per regel** (pure `split_per_rule()` op de eigen
  mediaan — niet één globale knip, anders krijgt een laat-begonnen regel een lege/scheve testperiode), met
  `MIN_SPLIT=4` per helft (anders `GEEN_HOLDOUT`). `ZWAK` = knop raakt 0 holdout-trades → geen bewijs.
- **Toepassen** (`sell_apply.py`): apply-stack = **toeval-toets → echte refire → GATE 3**. Eerst
  `_toeval_filter` (sign-flip `opt_lib.signflip_pvalue` op de per-trade verschillen, Šidák over de
  SAFE-familie; te weinig geraakte trades = "kan niet certificeren") — alleen wat dóórkomt gaat naar de
  dure refire. Dan `persist_to_brain` herrekent → houden iff Σprofit niet omlaag ÉN verliezers niet omhoog,
  **op de munt ÉN op de eigen regel** (dedup kan schade naar een andere regel verschuiven), anders
  terugdraaien. **Oracle-gate bewust weggelaten**: die meet trouw-aan-legacy, terwijl tunen beter wil.
- **Routine** (`routines.py` set `sell-tuning`): `routine_sell_tuning` meet → auto-apply (achter `--apply`)
  → journalt naar `/routines`. Eigen fingerprint (`with_sell` + **`with_fires`**): trades + `coin_fires`-
  drift + `strategies`/`coin_strategies.updated_at` + `manual_set_at` + coins-count — zo hertriggert ook
  een trade-set-drift door een code-deploy/refire. Dagelijks via een Claude Code CLI-routine:
  `routines.py --set sell-tuning --trigger routine --apply`.
- **Tests**: `test_sell_tuning.py` (15 asserts) — faithful-merge, override-isolatie, `metrics`, de
  holdout-poort `verdict` + `MIN_SPLIT`, `split_per_rule`, de sign-flip toets + `_toeval_filter`,
  override-respect (+ `opt_lib` selftest voor `signflip_pvalue`).
- **Resultaat (2026-06-17, live)**: NOS Σ+352,8→+373,9% (274→266 verlies), DOGEAI Σ+379,9→+401,2%
  (359→339 verlies). Sterkste lever overal: `minimal_profit`.
- **v2 (nog niet gebouwd)**: nieuwe **rule-101 verkoopregels ontdekken** (uit `coin_sell_ticks`) +
  leren van handmatige hard-sells (≥3 nodig, nu 2). Eis van Daan: rule-101 mag verzinnen/aanpassen
  **mits de historie laat zien hóé elke regel is gewijzigd** (audit zoals `rules_history`).

## Vuistregels

- **Heranalyse**: `persist_to_brain.py <coin>` — herrekent álles. Snapshot van vorige
  `profit_loss` vóór DELETE; klasse-overgangen automatisch in `coin_fires_changelog`. Handmatige
  `manual_klasse` blijft leidend (UI gebruikt die over de berekende).
- **Eén bron van waarheid**: ratchet-arithmetiek alleen in `sell_lock.py`. Niet dupliceren in
  validator of engine.
- **NOS vs DOGEAI**: zelfde knobs werken niet voor beide. NOS verliest Σprofit terwijl het
  verliezers redt — dáárom de per-coin override-laag (`coin_strategies`) + de tuning-routine (hierboven).
- **Per-tick opslag** als diagnose: bij een gekke uitkomst → kijk eerst de trail in
  `coin_sell_ticks` (welk mechanisme zette welke stop op welke tick).
- **Begrenzing beste sell**: altijd tot de volgende koop (`until_dt`). Een rebuy-rally hoort
  niet bij de vorige trade.

## Open follow-ups

1. ✅ **Per-coin tuning-routine** — GEBOUWD + live (zie sectie hierboven, [[sell-tuning-routine-plan]]).
2. **Rule-101 ontdekking (v2)** — nieuwe verkoopregels verzinnen uit `coin_sell_ticks` + leren van
   handmatige hard-sells (≥3). Propose-only eerst (rule-101 geldt voor beide munten; overfit-risico op
   2 munten). Met volledige wijzigingshistorie per regel (Daans eis).
3. **Keten-analyse**: vroeg eruit + herkopen vs vasthouden (Σprofit over opeenvolgende trades per coin).
4. **Best-sell-gap als lering**: onze sell vs `best_sell_in_window(until_dt=volgende koop)` — NIET
   `coin_fires.best_sell_datetime` (dat is de piek binnen onze hold → gap altijd 0).
5. **Dagelijks plannen** — Claude Code CLI-routine; hoort verder bij epic-08 (daily orchestration).

Zie ook [[brain-engine]] voor de algemene engine-kaart, [[brain-routines]] voor het routine-runner-
framework waarop de tuning-routine gaat draaien, en [[brain-promising-labeler]] voor het
detailscherm waarop de overrides ingevoerd worden.
