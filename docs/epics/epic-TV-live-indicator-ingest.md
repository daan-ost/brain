# EPIC TV: Live indicator-ontvangst (TradingView → eigen feed)

**Phase:** 4 — Execution (live data-aanvoer)
**Status:** ✅ GEBOUWD (2026-06-30) — endpoint live op server, wacht op DNS-cutover + TradingView-heractivatie
**Depends on:** niets in code; vervangt operationeel de legacy `retrieve_signal_tv.php` op de TransIP-VPS

## Voortgang (2026-06-30)

- **Gebouwde bestanden:**
  - `www/public/app/retrieve_signal_tv.php` — PHP-ontvanger (auth, validatie, upsert)
  - `schema_feed.sql` — DB-schema + seed (5 bestaande munten met brain-id)
  - `config.feed.php.example` — config-template (voor git); echte config op server (buiten webroot)
- **Server-staat:**
  - DB `nobrainers_feed` aangemaakt, schema uitgevoerd, seed geladen (NOS/TURBO/DOGEAI/MUMU/FARTCOIN)
  - Config `/home/ploi/nobrainersbot.com/config.feed.php` (chmod 600, ploi-owned)
  - Endpoint bereikbaar via `http://127.0.0.1/app/retrieve_signal_tv.php` (Host: nobrainersbot.com)
- **Getest (2026-06-30):**
  - Geldige NOS mfi alert → HTTP 200, `trading_symbol_id=244`, rij in `tv_indicators` ✅
  - Dubbele alert zelfde minuut → zelfde `id=1`, waarde bijgewerkt (geen duplicaat) ✅
  - Nieuwe munt CLAW → `id=100001` auto-aangemaakt in `tv_symbols` ✅
  - Fout token → 401, onbekende indicator → 422, foute timeframe → 422, volumeud=0 → 422 ✅
  - Afkeuringen belanden in `tv_ingest_log` ✅
- **Open:** DNS-cutover (epic-SV F7) → daarna TradingView-alerts heractiveren

## Goal

Een eigen, kleine ontvanger op de server waar de QR-codes draaien (66bio-host, PHP) die de
TradingView-alert-webhooks ontvangt, valideert en in een **eigen feed-database** opslaat — zodat de
NoBrainersBot-indicatoren niet langer via de TransIP-VPS hoeven binnen te komen. Eén stap, niet meer:
**ontvangen + opslaan**. De engine importeert later uit die feed (vervolgepic).

## Rationale

De legacy-bot draait op een TransIP-VPS die Daan wil opzeggen. De indicatoren (de enige data die we uit
de buitenwereld halen) komen nu binnen op `nobrainersbot.com/app/retrieve_signal_tv.php` op die VPS. Om
de VPS te kunnen verlaten moet de webhook-ontvangst verhuizen naar de QR-server, die toch al 24/7 PHP +
MySQL draait. We bouwen een schone, ontkoppelde ontvanger in plaats van de legacy-rommel te kopiëren: de
legacy-versie vuurt na elke insert synchroon koop/verkoop/order-checks af en gebruikt lockfiles +
`usleep(random)` om gelijktijdige signalen te de-syncen — dat laten we allemaal vallen. Wij willen één
ding dat betrouwbaar werkt onder gelijktijdige aanvoer.

## Bestaande code (referentie)

**Legacy-ontvanger** — `/Users/daanvantongeren/Documents/Sites/bot/legacy/retrieve_signal_tv.php`
Ontvangt een JSON-POST via `php://input`, valideert, zoekt de munt op (`wp_trading_symbols` op
coinpair+timeframe, maakt 'm desnoods aan), doet één `INSERT` in `wp_trading_indicator`, en tikt dan
de koop/verkoop-keten aan. Het **datacontract** dat TradingView stuurt (uit de alert-template, zie
`docs/findings/tradingview-alert-automation-2026-06-19.md`):
```json
{"action":"", "signal":"mfi", "signalvalue":"{{plot_0}}", "coin":"{{ticker}}", "price":"{{close}}", "timeframe":"{{interval}}"}
```
TradingView vult de placeholders bij het afvuren in → bv. `signal=mfi`, `signalvalue="59.6"` (string!),
`coin="CLAWUSDT"`, `price="0.02304"`, `timeframe="5"`. `action` is doorgaans leeg.

**Legacy DB-schema** — `bot/legacy/database_structure.sql`: `wp_trading_indicator`
(`trading_symbol_id, datetime, action, value, price, volume_found, volume, indicator, remote_ip`) en
`wp_trading_symbols` (`ID, symbol, coinpair, timeframe, active, …`).

**Brain-import (de afnemer)** — `engine/src/import_indicators.py`. Kopieert de 5 ruwe indicatoren uit
`bot_signals.wp_trading_indicator` JOIN `wp_trading_symbols` naar brain `indicators`:
```sql
INSERT INTO indicators (trading_symbol_id, symbol, indicator, datetime, value, price, volume_found)
SELECT i.trading_symbol_id, s.symbol, i.indicator, i.datetime, i.value, i.price, i.volume_found
FROM bot_signals.wp_trading_indicator i
JOIN bot_signals.wp_trading_symbols s ON s.ID = i.trading_symbol_id
WHERE i.trading_symbol_id IN (…) AND i.indicator IN ('vzo','phobos','obv-x-value','mfi','volumeud')
  AND i.value IS NOT NULL
```
**Belangrijk:** brain neemt enkel `trading_symbol_id, symbol, indicator, datetime, value, price`
(+ legacy `volume_found`) over. `action`, `volume`, `remote_ip` worden **niet** geïmporteerd. De
feed-tabel moet dus exact die kolommen kunnen leveren. (De import draait nu als server-side
`INSERT..SELECT` omdat beide DB's op één MySQL staan; met de feed op de QR-server wordt dat een
cross-server kopie — dat is de vervolgepic, niet deze.)

**De 5 indicatoren (vaste lijst, hardgecodeerd in de engine):** `vzo`, `phobos`, `obv-x-value`, `mfi`,
`volumeud`. Granulariteit: **per minuut**, tijd in **UTC** (legacy zet `date_default_timezone_set('UTC')`).

**Server-context (66bio)** — `/Users/daanvantongeren/Documents/Sites/66bio`: AltumCode (PHP), Docker
(php-fpm) + Caddy (TLS), per site een eigen MySQL-database. De ontvanger draait dáár, maar **los van**
het Altum-framework en met een **eigen database**.

## Beslissingen

| # | Vraag | Beslissing |
|---|---|---|
| 1 | Waar draait de ontvanger? | `nobrainersbot.com` wordt een **eigen site** op de QR-server (eigen webroot `/sites/nobrainers/www`, eigen Caddy-vhost), náást de QR-sites maar onafhankelijk van de Altum-bootstrap. Het domein wijst straks naar deze server i.p.v. de TransIP-VPS. Deploy-runbook: [../deployment/qr-server.md](../deployment/qr-server.md). |
| 1b | Webhook-URL | **Behoud** `https://nobrainersbot.com/app/retrieve_signal_tv.php` op de nieuwe server → een DNS-cutover migreert de feed zonder dat één TradingView-alert aangepast hoeft te worden. Schoner pad pas ná bewezen migratie. |
| 2 | Welke database? | Eigen DB `nobrainers` op dezelfde MySQL 8.0-server (eigen DB-user), los van 66bio's per-site-DB's. |
| 3 | Route naar de engine | Aparte feed-tabel; de engine importeert daaruit (vervangt `bot_signals` als bron). Die import-aanpassing is een **vervolgepic** — buiten scope hier. |
| 4 | Munt-ID's | Eigen `tv_symbols`-tabel. `AUTO_INCREMENT=100000` zodat nieuw aangemaakte munten nooit botsen met bestaande brain-`trading_symbol_id`'s (allemaal < 100000: bv. NOS 244, TURBO 32, DOGEAI 2525, MUMU 2735, FARTCOIN 6419). Bestaande munten **pre-seeden** met hun bekende brain-id. |
| 5 | Idempotentie | `UNIQUE (trading_symbol_id, indicator, datetime)` + `INSERT … ON DUPLICATE KEY UPDATE`. Een herhaalde/dubbele alert in dezelfde minuut overschrijft, dupliceert niet. |
| 6 | Tijdstempel | `datetime` afgerond op de **minuut** (sec=0), in **UTC**. Een extra `received_at` (ms) bewaart de echte ontvangsttijd voor audit. |
| 7 | Concurrency | Stateless endpoint, één korte transactie per request, row-level upserts. **Geen** lockfiles, **geen** `usleep`, **geen** synchrone vervolg-HTTP-calls. |
| 8 | `action`-veld | Wordt opgeslagen zoals binnengekomen (meestal leeg); **geen** afleidingslogica (legacy's `get_current_indicator` vervalt) — brain importeert `action` toch niet. |
| 9 | Auth | Shared-secret token (query of header) + optionele TradingView-IP-allowlist + HTTPS (Caddy). Token buiten de webroot, niet in git. |

## Scope

1. **Eigen feed-database `nobrainers_feed`** met drie tabellen (zie schema hieronder): `tv_symbols`
   (munt-mapping + auto-create), `tv_indicators` (de feed), `tv_ingest_log` (afgekeurde/foutieve
   requests). Plus een seed van de bestaande live-munten met hun brain-id.

2. **De ontvanger** (één PHP-endpoint, bv. `POST /nobrainers/ingest`):
   - Auth: shared-secret token controleren; fout/ontbrekend → `401`. Optioneel IP-allowlist.
   - Body lezen via `php://input`, JSON-decoden; geen object → `400 invalid_json`.
   - Valideren (zie validatieregels). Afkeur → juiste HTTP-status + `{error, message}` + rij in
     `tv_ingest_log`; **geen** feed-rij.
   - Munt race-safe resolven: `INSERT … ON DUPLICATE KEY UPDATE` op `tv_symbols(coinpair,timeframe)`,
     daarna `SELECT id`. Twee gelijktijdige eerste-alerts van dezelfde nieuwe munt → één rij.
   - `datetime` = huidige tijd in UTC, afgerond op de minuut.
   - Upsert in `tv_indicators` op `uq_tick`. Antwoord `{data:{…}}` met HTTP `200`.
   - Eén transactie, daarna klaar — niets aftikken.

3. **Validatieregels** (overgenomen uit legacy, aangescherpt):
   - `signal` ∈ {`vzo`,`phobos`,`obv-x-value`,`mfi`,`volumeud`} → anders `422 unknown_indicator`.
   - `signalvalue` aanwezig en numeriek (`is_numeric`, string→float); leeg/0 specifiek bij
     `volumeud` → `422 empty_volume_value` (legacy-gedrag). Niet-numeriek → `422 invalid_signalvalue`.
   - `timeframe` ∈ {1,3,5,15,30,45,60,120,240} → anders `422 invalid_timeframe`.
   - `coin` niet leeg → anders `422 missing_coin`.
   - `price` aanwezig en numeriek → anders `422 missing_price`.

4. **Configuratie** in een bestand buiten de webroot (DB-creds, secret token, IP-allowlist) + een
   Caddy-route + deploy-notitie voor de QR-server.

5. **Health/observability** (klein): de endpoint logt afkeuringen/fouten in `tv_ingest_log` met reden +
   ruwe body + IP. Successen worden niet apart gelogd — `tv_indicators` ís het succes-spoor.

### Feed-schema (`nobrainers_feed`)

```sql
CREATE TABLE tv_symbols (
  id            INT UNSIGNED      NOT NULL AUTO_INCREMENT,
  symbol        VARCHAR(50)       NOT NULL,        -- bv. CLAW (coinpair zonder USDT)
  coinpair      VARCHAR(50)       NOT NULL,        -- ruwe coin uit de webhook, bv. CLAWUSDT
  timeframe     SMALLINT UNSIGNED NOT NULL,        -- 1/3/5/15/30/45/60/120/240
  active        TINYINT(1)        NOT NULL DEFAULT 1,
  created_at    DATETIME          NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    DATETIME          NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_pair_tf (coinpair, timeframe)
) ENGINE=InnoDB AUTO_INCREMENT=100000 DEFAULT CHARSET=utf8mb4;

CREATE TABLE tv_indicators (
  id                INT UNSIGNED   NOT NULL AUTO_INCREMENT,
  trading_symbol_id INT UNSIGNED   NOT NULL,
  symbol            VARCHAR(50)    NULL,
  coinpair          VARCHAR(50)    NULL,
  indicator         VARCHAR(30)    NOT NULL,       -- vzo/phobos/obv-x-value/mfi/volumeud
  datetime          DATETIME       NOT NULL,       -- bar-minuut (sec=0), UTC
  value             DOUBLE         NULL,
  price             DOUBLE         NULL,
  action            VARCHAR(10)    NULL,           -- as-is opgeslagen, niet gebruikt
  volume_found      TINYINT(1)     NOT NULL DEFAULT 0,   -- TV-munten hebben geen legacy-vlag → 0
  received_at       DATETIME(3)    NOT NULL DEFAULT CURRENT_TIMESTAMP(3),  -- echte ontvangst (audit)
  remote_ip         VARCHAR(45)    NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_tick (trading_symbol_id, indicator, datetime)  -- dient ook als query-index
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE tv_ingest_log (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  received_at DATETIME(3)     NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  status      VARCHAR(20)     NOT NULL,            -- 'rejected' / 'error'
  reason      VARCHAR(255)    NOT NULL,
  raw_body    TEXT            NULL,
  remote_ip   VARCHAR(45)     NULL,
  PRIMARY KEY (id),
  KEY idx_received (received_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

### Endpoint-contract (concreet)

**Request** (TradingView stuurt `text/plain` met JSON-body; lees via `php://input`):
```bash
curl -X POST "https://<qr-host>/nobrainers/ingest?token=<SECRET>" \
  -H "Content-Type: application/json" \
  --data '{"action":"", "signal":"mfi", "signalvalue":"59.6", "coin":"CLAWUSDT", "price":"0.02304", "timeframe":"5"}'
```

**Succes** — HTTP `200`:
```json
{"data":{"id":12345,"trading_symbol_id":100001,"indicator":"mfi","datetime":"2026-06-29 14:23:00"}}
```

**Fout** — juiste HTTP-status + altijd `{error, message}`:
```json
{"error":"invalid_timeframe","message":"timeframe '7' is not in the allowed set"}
```

| Situatie | HTTP | `error` |
|---|---|---|
| Token fout/ontbreekt | 401 | `unauthorized` |
| Body geen JSON-object | 400 | `invalid_json` |
| Onbekende indicator | 422 | `unknown_indicator` |
| signalvalue niet numeriek | 422 | `invalid_signalvalue` |
| signalvalue leeg/0 bij volumeud | 422 | `empty_volume_value` |
| timeframe niet toegestaan | 422 | `invalid_timeframe` |
| coin leeg | 422 | `missing_coin` |
| price leeg/niet numeriek | 422 | `missing_price` |

### Concurrency — "alle indicatoren tegelijk op de seconde"

Per minuut vuurt elke munt 5 alerts (1 per indicator), allemaal rond `:00`–`:02` van de minuut. Bij N
munten zijn dat N×5 vrijwel gelijktijdige POSTs. Dit is de kern van de epic en moet aantoonbaar werken:

- **Geen gedeelde lock.** Legacy gebruikte een lockfile + `usleep(random 0.1–0.3s)` om de
  `volumeud`-trigger te de-syncen; dat hoort bij de koop/verkoop-keten (out of scope) en vervalt.
- **Onafhankelijke rijen.** Elke POST is een aparte `(trading_symbol_id, indicator, datetime)` →
  een eigen unique-key → InnoDB row-level locking, geen tabel-contention. 60+ gelijktijdige inserts
  raken elkaar niet.
- **Idempotente upsert.** `INSERT … ON DUPLICATE KEY UPDATE value=VALUES(value), price=VALUES(price),
  received_at=VALUES(received_at)` — een dubbele alert in dezelfde minuut is veilig.
- **Race-safe munt-aanmaak.** De eerste 5 alerts van een nieuwe munt komen samen binnen; de
  `INSERT … ON DUPLICATE KEY UPDATE` op `uq_pair_tf` + `SELECT id` zorgt dat er precies één
  `tv_symbols`-rij ontstaat.
- **Snel & klaar.** Geen vervolg-HTTP-calls; endpoint < ~50ms, geef meteen `200` terug zodat
  TradingView geen retry-storm veroorzaakt.

## Acceptance criteria

- [ ] Een POST met geldige JSON van één indicator levert precies één rij in `tv_indicators` op, met
      `datetime` op de minuut afgerond (UTC), en antwoordt HTTP `200` met `{data:{…}}`.
- [ ] Een nieuwe `(coin, timeframe)` maakt automatisch één `tv_symbols`-rij (id ≥ 100000); een tweede,
      gelijktijdige alert voor dezelfde nieuwe munt maakt **geen** tweede rij.
- [ ] 5 indicatoren van dezelfde munt in dezelfde minuut → 5 rijen met **dezelfde** `datetime`, één per
      indicator.
- [ ] Een herhaalde alert (zelfde munt + indicator + minuut) overschrijft de bestaande rij in plaats van
      een duplicaat aan te maken.
- [ ] Elke ongeldige payload (onbekende indicator, niet-numerieke/lege signalvalue, ongeldige timeframe,
      missende coin/price, fout/ontbrekend token) wordt afgekeurd met de juiste HTTP-status +
      `{error, message}` en belandt in `tv_ingest_log`; er ontstaat **geen** feed-rij.
- [ ] Een lasttest van ≥ 60 gelijktijdige POSTs (12 munten × 5 indicatoren) binnen ~2s levert alle
      verwachte rijen op, zonder deadlocks of verloren rijen.
- [ ] De endpoint doet geen enkele synchrone vervolg-HTTP-call.
- [ ] `tv_indicators` levert exact de kolommen die `import_indicators.py` joint
      (`trading_symbol_id, symbol, indicator, datetime, value, price, volume_found`) — contract-getoetst.
- [ ] Secret token + HTTPS staan aan; config met creds/token staat buiten de webroot en niet in git.

## Out of scope

- **De koop/verkoop-keten**: `bot_check_buysignal`, `bot_process_selling`, `bot_process_new_orders`,
  `trading_job_run_minute` — alles wat legacy ná de insert aftikt. Bewust niet meegenomen.
- **Doorsturen naar een tweede DB** (`retrieve_signal_tv2.php`, de "all signals"-kopie).
- **De engine-import-aanpassing** (engine leest live-feed i.p.v. `bot_signals`) — vervolgepic; deze
  epic levert alleen het contract-compatibele schema.
- **TradingView-alert-aanmaak** — dat is de aparte automatisering uit
  `docs/findings/tradingview-alert-automation-2026-06-19.md`.
- **min_volume seeden / `build_indicator_metrics`** — gebeurt aan de brain-kant bij munt-onboarding.

## Notes

- **Tijdzone is kritiek.** Brain's `indicators.datetime` is legacy-UTC. De ontvanger moet de minuut in
  UTC stempelen, anders sluit de feed niet aan op de historische reeks. Caddy/PHP-FPM op UTC zetten of
  expliciet `gmdate()`/`DateTimeZone('UTC')` gebruiken.
- **`signalvalue` komt als string binnen** (`"59.6"`) — cast naar float, valideer met `is_numeric`,
  bewaar als `DOUBLE`. Legacy doet `round(…,1)`; wij bewaren de volle precisie (afronden is een
  brain-keuze, niet die van de feed).
- **`coinpair` vs `symbol`.** TradingView's `{{ticker}}` = de coinpair (bv. `CLAWUSDT`). Leid `symbol`
  af door `USDT` (case-insensitive) eraf te knippen, net als legacy. Bewaar beide.
- **Pre-seed de bestaande munten** in `tv_symbols` met hun brain-id (NOS 244, TURBO 32, DOGEAI 2525,
  MUMU 2735, FARTCOIN 6419, …) vóór livegang, zodat de feed voor bekende munten naar dezelfde
  `trading_symbol_id` schrijft als de historische import.
- **Beveiliging legacy was zwak** (hash `12323423452345822` in de URL, IP-check uitgecommentarieerd). Niet
  overnemen — gebruik een echt secret + HTTPS en zet eventueel de bekende TradingView-webhook-IP's op de
  allowlist.

## Nieuwe bestanden aan te maken

| Bestand | Type | Doel |
|---|---|---|
| `public/app/retrieve_signal_tv.php` | PHP | de ontvanger (auth, validatie, upsert) — in Laravel's public/, als standalone bestand |
| `schema_feed.sql` | SQL | `tv_symbols` + `tv_indicators` + `tv_ingest_log` + seed bestaande munten |
| `config.feed.php` (buiten webroot, niet in git) | PHP-config | DB-creds, secret token, IP-allowlist, tijdzone |
| Caddy-route-snippet + deploy-notitie | config/docs | TLS-route + hoe te draaien naast 66bio |

**Let op:** de ontvanger komt in `public/app/` van de Laravel-webroot (zie
[epic-SV-server-verhuizing.md](epic-SV-server-verhuizing.md) beslissing 3). Caddy serveert bestaande
bestanden vóór de try_files-fallback naar Laravel's `index.php`, dus de feed-receiver draait als
standalone PHP zonder de Laravel-bootstrap.
