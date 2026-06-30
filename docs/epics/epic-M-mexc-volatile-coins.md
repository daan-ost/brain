# EPIC M: Volatiele MEXC-coins ontdekken — kandidaten-tab onder /coins

> **✅ GEBOUWD + GEDEPLOYED (2026-06-29)** — kern af + 4-uurs cron draait op de 66bio-VPS.
> Zie §Gebouwde staat voor de delta t.o.v. het plan.
> Onderzoeks-findings: `docs/findings/mexc-volatiele-coins-2026-06-19.md`.
> Bouw-beslissingen + architectuur: `docs/findings/mexc-coin-tracking-2026-06-29.md`.
> Server-runbook: `docs/deployment/mexc-scan-server.md`.

---

## Gebouwde staat (2026-06-29)

De vier geplande features zijn gebouwd, maar de implementatie is op drie punten verder gegaan dan het originele plan.

### Wat er staat

| Bestand | Rol |
|---|---|
| `engine/src/mexc_scan.py` | Scan-engine: MEXC 2 bulk-calls → CoinGecko-join → klines-trend → DB schrijven |
| `engine/src/routines.py` | `routine_mexc_scan` + `MEXC_SET_KEY="mexc-scan"` (niet-gegated) |
| `engine/src/db.py` | `mexc()` functie, env-configureerbaar (`MEXC_DB_*`) |
| `engine/sql/mexc_schema.sql` | Drie tabellen: `mexc_market_scan`, `mexc_snapshots`, `mexc_coin_labels` |
| `www/database/migrations/2026_06_29_000000_extend_mexc_tracking.php` | Brain-DB gelijkgetrokken met server-schema |
| `www/app/Livewire/Coins/MexcScan.php` | Livewire-component `/coins/mexc` + `scanNow()` knop |
| `www/resources/views/livewire/coins/mexc-scan.blade.php` | View met filters + "Nu verversen"-knop |

### Delta t.o.v. het originele plan

**1. Uitgebreide data per munt (verder dan v1-plan)**
- `mexc_market_scan` bevat ook: `bid_price/ask_price/bid_qty/ask_qty`, `spread_pct`, `book_pressure` (orderboek-top uit ticker gratis), en candle-trend-kolommen uit `klines 1d`: `ret_7d_pct`, `ret_14d_pct`, `avg_day_range_pct`, `up_days`, `down_days`, `trend_window_d`, `auto_flag` (`faller`/`choppy`/NULL).
- `auto_flag` filtert "alleen-dalers" automatisch (ret_7d < −25%) en "schokkerig maar geen richting" (avg_range > 40% én |ret_7d| < 15%). Drempels zijn eerste gok, later afstemmen op Daans goed/slecht-labels.

**2. 4-uurs tijdreeks op de server (nieuw t.o.v. plan)**
- Tabel `mexc_snapshots` (append-only): elke run een rij per kandidaat — rang + orderboek-momentopname + `snapshot_at`. Op de server: 6 runs/dag = ~1700 rijen/dag. Alleen wat je niet uit klines kunt reconstrueren (klines geeft 500 dagen prijs-history direct).
- DB is een **eigen `mexc`-database op de 66bio-VPS** (`116.203.78.110`), losgekoppeld van de brain-DB op de Mac. Dezelfde code draait lokaal tegen brain (default env) en op de server (via `MEXC_DB_*` env vars in `/opt/mexc-scan/.env`).
- Cron: `/etc/cron.d/mexc-scan` — `5 */4 * * *`, draait als root, logt naar `/opt/mexc-scan/log/scan.log`.

**3. Classificatie-schema klaar, UI nog open**
- Tabel `mexc_coin_labels` (unieke sleutel `base`) bestaat al: `classification ENUM('unrated','good','bad')`, `reasons JSON`, `note`. Overleeft de truncate van `mexc_market_scan`.
- **UI (radio-knoppen + redenen)** is nog **niet gebouwd** — wacht op de website-migratie naar de VPS (epic-SV), zodat de Livewire-laag bij de server-DB kan.

### Server-status
- **Locatie:** `/opt/mexc-scan/` op `116.203.78.110` (Python 3.12 venv, pymysql 2.2.8)
- **Verbinding:** `mexc@127.0.0.1` op poort 3306, wachtwoord in `/opt/mexc-scan/.env` (chmod 600)
- **Verificatie:** `ssh root@116.203.78.110 '/opt/mexc-scan/venv/bin/python -c "..."'` (zie deployment-runbook §5)
- **Code-update:** opnieuw `scp engine/src/db.py engine/src/mexc_scan.py root@116.203.78.110:/opt/mexc-scan/src/` — geen herstart nodig

### Open punten
- **Classificatie-UI (deel C)** — na epic-SV (website naar server)
- **`auto_flag`-drempelwaarden afstemmen** — zodra er goed/slecht-labels + genoeg history zijn
- **`mexc@localhost` wachtwoord-mismatch** — opschonen bij brain-verhuizing (onschadelijk, scan verbindt op IP)
- **Ploi-backup** — checken of de `mexc`-DB onder de backup-routine valt

---

## Epic Specification

Een **niet-gegate dagelijkse routine** scant de hele MEXC-spotmarkt, joint de USDT-paren met CoinGecko-marketcap,
en schrijft een **snapshot** van volatiele, handelbare kandidaten naar een nieuwe brain-tabel `mexc_market_scan`.
Een **apart Livewire-component** toont die snapshot als **tweede tab onder /coins ("Munten")**, gesorteerd op
24u-volatiliteit, met instelbare filters voor marketcap (default >10M), 24u-volume (default >100k USDT) en
leeftijd ("verberg <7 dagen" default aan). Read-only: er verandert niets aan rules of trades. De kandidaten zijn
**externe** coins (mogelijk later in de engine op te nemen), los van de bestaande engine-coins.

## Rationale

We zoeken volatile trades en willen kunnen **roteren** tussen veel coins. De #1 blocker uit eerder onderzoek
is dat er te weinig coins gelijktijdig leven, waardoor rotatie niet bewijsbaar is. Epic V mat de beweeglijkheid
van de 2 coins die we al handelen; dit epic levert van buitenaf **nieuwe kandidaten** aan. Een werkend prototype
toonde dat dit kan (reële top: ASTEROID +88% / volat 164% / mcap 68M / 63d). Dit epic maakt het robuust,
herhaalbaar en zichtbaar in de UI.

## Dependencies

- Routine-framework (`engine/src/routines.py`) — nieuwe niet-gegate set toevoegen (precedent: `coin-metrics`).
- Brain DB-verbinding (`engine/src/db.py` → `brain()`, MAMP poort 8889).
- Bestaande /coins-route + component (`App\Livewire\Coins\Ranking`, route `coins.ranking`) — tab-buur.
- Python venv `engine/.venv` — **blocker: `requests` ontbreekt** (`pymysql`/`pandas`/`numpy` aanwezig).
- Een gratis **CoinGecko Demo-key** (account, geen betaalkaart; header `x-cg-demo-api-key`).
- Geen auth nodig voor MEXC marktdata.

## Bestaande Code (referentie)

### Routine-precedent (`engine/src/routines.py`) — niet-gegate set
```python
VOL_SET_KEY = "coin-metrics"
REGISTRY_VOL = [("coin-metrics", routine_coin_metrics)]
SETS = { ..., VOL_SET_KEY: (VOL_SET_NAME, REGISTRY_VOL, False) }  # laatste False = niet gegated
```
Een routine is een functie `routine_x(j)` die `j.add(msg, level=, data=)` journaalt en draait elke run.

### Brain DB (`engine/src/db.py`)
```python
def brain(dict_cursor=True):
    return pymysql.connect(host="127.0.0.1", port=8889, user="root", password="root",
                           database="brain", ...)
```

### Migratie-stijl (`www/database/migrations/2026_06_19_010000_create_coin_daily_metrics_table.php`)
`bigIncrements`, `decimal(p,s)->nullable()`, named unique (`cdm_coin_date`) + index (`cdm_lookup`), JSDoc-blok.

### Livewire/route/nav-conventie
- `App\Livewire\Coins\Ranking::mount()` → `abort_unless(auth()->user()?->is_admin, 403)`, `#[Layout('layouts.trading')]`.
- Route: `Route::get('/coins', \App\Livewire\Coins\Ranking::class)->name('coins.ranking');` (web.php:322).
- Sidebar highlight via `request()->routeIs('coins.*')` → een nieuwe route `coins.mexc` highlight 'Munten' vanzelf.
- Tabelstijl in `ranking.blade.php`: `bg-white rounded-xl shadow-sm border border-gray-100`, thead
  `bg-gray-50 text-xs uppercase`, `tabular-nums`, gekleurde balk (`emerald` ≥0.66×max / `amber` ≥0.33×max / `gray`).

### Live-geverifieerde API-feiten (bouw hierop voort, herontdek niet)
- MEXC `/exchangeInfo` (1 call): symbol, baseAsset, quoteAsset, status, isSpotTradingAllowed, permissions,
  contractAddress (1638/1916 echt), **firstOpenTime** (1868/1916 = exacte listingdatum), st (bool). Geen marketcap.
- MEXC `/ticker/24hr` (1 call, alle symbolen): lastPrice, priceChangePercent, highPrice, lowPrice, quoteVolume(=24u USDT).
- MEXC rate-limit: 300 weight/10s per IP; ticker=40, exchangeInfo=10, klines=1. 429+Retry-After / 418=ban.
- CoinGecko `/coins/markets?vs_currency=usd&order=market_cap_desc&per_page=250&page=1..5`: mcap>10M tot ~rank 1160 → 5 pagina's.
- CoinGecko `/coins/list?include_platform=true`: id→{platform:contractAddress} over alle chains (join-map).

## Beslissingen

| # | Vraag | Beslissing |
|---|---|---|
| 1 | Bron kandidaten | MEXC publieke spot-API (`https://api.mexc.com/api/v3`, geen auth): `/exchangeInfo` + `/ticker/24hr`, beide 1 bulk-call. |
| 2 | Marketcap-bron | CoinGecko (MEXC heeft géén native marketcap). `/coins/markets`, 5 pagina's (rank 1-1250), client-side filter `market_cap>10M`. |
| 3 | CoinGecko-toegang | Gratis **Demo-key** (header `x-cg-demo-api-key`), 100/min, **10.000/maand**. Keyless geeft 429-muur bij bursts → key verplicht. |
| 4 | CoinGecko-licentie | Demo-tier mág commercieel; **verplichte "Powered by CoinGecko"-attributie** (min. fontgrootte 10, met link) in de view. |
| 5 | Join-sleutel | **Primair contractadres** (lowercase/trim, alle chains via `/coins/list?include_platform=true`). **Fallback base-symbool, alleen unieke match** binnen mcap>10M-set; dubbele ticker → overslaan. |
| 6 | NULL-mcap coins | Tonen met markering "mcap onbekend", **niet** stil weg-filteren (anders verdwijnen mid-caps buiten CG-top onzichtbaar). |
| 7 | Volatiliteit-maat | **v1 = 24u-range** `(highPrice-lowPrice)/lowPrice*100` uit `/ticker/24hr` (gratis), opgeslagen als `volat_pct`, sorteersleutel. Klines-maat = v2. |
| 8 | mcap-drempel | `mcap_usd > 10.000.000` (10M), instelbaar (slider, default 10M). |
| 9 | volume-drempel | `vol24h_usd > 100.000` USDT, instelbaar (slider, default 100k). Weert illiquide uitschieters (MMUI: 972% volat / $19k vol). |
| 10 | Leeftijd-bron | **`exchangeInfo.firstOpenTime`** (exact, 97,5%, 0 extra calls, geen cap-valkuil). Fallback: oudste 1d-kline voor de ~48 zonder firstOpenTime. Niet CoinGecko genesis_date. |
| 11 | Leeftijd-drempels | <14d rood/te-jong; 14-90d amber (mits mcap>10M EN volume>drempel); >90d groen. UI-default filter "verberg <7 dagen" aan. |
| 12 | Handelbaar-filter | `quoteAsset=USDT` EN status online EN `isSpotTradingAllowed=true` EN permissions bevat `SPOT` EN **`st=false`** (ST-tag = delisting-risico). |
| 13 | status-enum | **Live geverifieerd (hoofdagent):** `status='1'` (2353) = normaal, `'2'` (1) = uitzondering. Selecteer op **`isSpotTradingAllowed=true`** (2223) + `st=false`; niet op een gegokte status-enum. |
| 14 | Verversingscadans | Dagelijks, niet-gegate Local routine op de Mac (brain-DB lokaal, geen Cloud). CoinGecko ~6 calls/dag, MEXC 2 bulk-calls. Vaker schedulebaar. |
| 15 | Tabel-schema | Nieuwe `mexc_market_scan`, snapshot (truncate+herschrijf in 1 transactie, **pas truncaten NA geslaagde fetch**). Geen `trading_symbol_id` FK (externe kandidaten). |
| 16 | Fetch-mechanisme | Python-module `engine/src/mexc_scan.py` + routine, **geen** Laravel Console command. **Gebruik stdlib `urllib` (geen `requests`-dependency)** — bewezen werkend in het prototype, scheelt een venv-install. |
| 17 | UI-plek | Apart component `App\Livewire\Coins\MexcScan`, route `coins.mexc`, tab-balk bovenaan beide /coins-views. Niet tabs binnen Ranking. |
| 18 | Sorteersleutel UI | `volat_pct` desc (kerninzicht: prijs-volatiliteit, niet volume). Volume+mcap = filters, geen sorteersleutel. |

## Features (4)

### 1. Brain-tabel `mexc_market_scan` (migratie)

**Status:** ✅ Gebouwd

```php
// www/database/migrations/2026_06_20_010000_create_mexc_market_scan_table.php
return new class extends Migration {
    public function up(): void {
        Schema::create('mexc_market_scan', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->string('symbol', 40);                       // ASTEROIDUSDT
            $t->string('base', 30);                         // ASTEROID
            $t->string('quote', 12)->default('USDT');
            $t->decimal('price', 24, 10)->nullable();
            $t->decimal('change24h_pct', 10, 2)->nullable();// priceChangePercent
            $t->decimal('volat_pct', 10, 2)->nullable();    // (high-low)/low*100 — sorteersleutel
            $t->decimal('vol24h_usd', 20, 2)->nullable();   // quoteVolume — liquiditeit-filter
            $t->decimal('mcap_usd', 20, 2)->nullable();     // CoinGecko market_cap (null = onbekend)
            $t->unsignedInteger('age_days')->nullable();    // uit firstOpenTime (of kline-fallback)
            $t->string('age_source', 20)->nullable();       // 'firstOpenTime' | 'kline' | 'unknown'
            $t->string('contract', 120)->nullable();        // MEXC contractAddress
            $t->string('cg_id', 80)->nullable();            // CoinGecko id (join-traceability)
            $t->string('status', 20)->nullable();           // MEXC status (genormaliseerd)
            $t->timestamp('fetched_at');                    // gedeeld per scan
            $t->timestamps();
            $t->unique('symbol', 'mms_symbol');
            $t->index('volat_pct', 'mms_volat');
            $t->index(['mcap_usd', 'vol24h_usd'], 'mms_filters');
        });
    }
    public function down(): void { Schema::dropIfExists('mexc_market_scan'); }
};
```

#### Acceptance Criteria
- [ ] Migratie draait via `/Applications/MAMP/bin/php/php8.4.17/bin/php artisan migrate` en is reversibel.
- [ ] `SHOW CREATE TABLE mexc_market_scan` toont alle kolommen + unique `mms_symbol` + index `mms_volat` + `mms_filters`.
- [ ] Geen `trading_symbol_id`-kolom (externe kandidaten, geen FK naar `coins`).
- [ ] Tabel is leeg na migratie.

---

### 2. Python fetch-module `engine/src/mexc_scan.py` (fetch + join + schrijf)

**Status:** ✅ Gebouwd — uitgebreid met spread/orderboek, candle-trends en 4-uurs history (zie §Gebouwde staat)

Module met `run(verbose=True) -> {fetched, kept, top:[...]}`. Stappen:
1. **HTTP via stdlib `urllib.request`** (geen `requests`-dependency nodig — bewezen werkend in het prototype).
2. MEXC `GET /exchangeInfo` (1 call) → enabled USDT-paren. Filter: `quoteAsset=USDT` EN
   `isSpotTradingAllowed=true` EN `permissions` bevat `SPOT` EN `st=false` (status-enum live geverifieerd, zie
   beslissing 13 — selecteer op `isSpotTradingAllowed`, niet op de enum). Bewaar `contractAddress`, `firstOpenTime`.
3. MEXC `GET /ticker/24hr` (1 call) → per symbol `lastPrice`, `priceChangePercent`, `volat_pct=(high-low)/low*100`, `quoteVolume`.
4. Leeftijd: `age_days` uit `firstOpenTime` (ms → dagen), `age_source='firstOpenTime'`. Voor paren zonder
   firstOpenTime: 1d-kline-fallback (1 weight elk), `age_source='kline'`; markeer count==500 als ">=Nd".
5. CoinGecko: `/coins/markets?per_page=250&page=1..5` (Demo-key header) → mcap-map; `/coins/list?include_platform=true`
   → contract→cg_id-map over **alle** chains (lowercased). **Join primair op contract, fallback op uniek symbool**.
6. **Schrijf snapshot atomair:** verzamel alle rijen in geheugen; **alleen bij volledig geslaagde fetch+parse**
   in 1 transactie `TRUNCATE mexc_market_scan` + bulk-insert met gedeelde `fetched_at`. Bij elke fout: journal
   een error en **behoud de vorige snapshot** (niet truncaten). Alle HTTP-calls met timeout + try/except + 429-backoff.
7. **Nooit naar `bot_signals`** — alleen `db.brain()`.

#### Acceptance Criteria
- [ ] De module gebruikt uitsluitend stdlib (`urllib`) — `grep -i requests mexc_scan.py` → leeg (geen nieuwe dependency).
- [ ] `cd engine/src && ../.venv/bin/python mexc_scan.py` schrijft >0 rijen; elke rij heeft `symbol`, `volat_pct`, `vol24h_usd`, `fetched_at`.
- [ ] Alle rijen van één run delen dezelfde `fetched_at`.
- [ ] Een rij met mcap>10M EN vol24h>100k EN age_days>=7 staat in de output (bv. een ASTEROID-achtige).
- [ ] Een illiquide uitschieter (volat hoog, vol24h<100k, type MMUI) wordt **niet** als top getoond na het volume-filter.
- [ ] `age_source='firstOpenTime'` voor de overgrote meerderheid; fallback-rijen hebben `age_source='kline'`.
- [ ] Join: rijen met een echt contract hebben bij voorkeur een `cg_id`; symbool-fallback wordt overgeslagen bij dubbele ticker (geen foute mcap).
- [ ] Gesimuleerde fetch-fout (mock 500 op CoinGecko-pagina 3) → tabel **niet** getruncate, vorige snapshot intact, error gejournald.
- [ ] `grep bot_signals mexc_scan.py` → leeg.
- [ ] **Build-gate — contract-join al GEMETEN (hoofdagent, live):** 1379/1507 (92%) contract-paren resolven naar CoinGecko; symbool-join 93% maar 520/1789 tickers ambigu → contract primair, symbool alleen bij unieke match. Resteert: tel hoeveel van de >10M-set daadwerkelijk een mcap krijgt, en rapporteer het.

---

### 3. Routine-set `mexc-scan` in `engine/src/routines.py`

**Status:** ✅ Gebouwd

```python
def routine_mexc_scan(j):
    """Scan de MEXC-markt op volatiele, handelbare USDT-kandidaten; schrijf snapshot mexc_market_scan."""
    import mexc_scan
    res = mexc_scan.run(verbose=False)
    j.add(f"MEXC-scan: {res['fetched']} paren opgehaald, {res['kept']} kandidaten bewaard "
          f"(mcap>10M & vol>100k & leeftijd-filter).", level="result", data=res)
    for c in res.get("top", [])[:5]:
        j.add(f"  {c['symbol']}: volat {c['volat_pct']}% · 24u-vol ${c['vol24h_usd']} · "
              f"mcap ${c.get('mcap_usd','?')} · {c.get('age_days','?')}d", level="finding")
    return f"mexc-scan · {res['fetched']} paren · {res['kept']} kandidaten"

MEXC_SET_KEY = "mexc-scan"
MEXC_SET_NAME = "MEXC-markt — volatiele kandidaten (dagelijks)"
REGISTRY_MEXC = [("mexc-scan", routine_mexc_scan)]
# In SETS (laatste boolean False = niet gegated):
SETS = { ..., MEXC_SET_KEY: (MEXC_SET_NAME, REGISTRY_MEXC, False) }
```

#### Acceptance Criteria
- [ ] `routines.py` bevat `routine_mexc_scan(j)` + `MEXC_SET_KEY="mexc-scan"` + `MEXC_SET_NAME`.
- [ ] `SETS` heeft `MEXC_SET_KEY: (MEXC_SET_NAME, REGISTRY_MEXC, False)`.
- [ ] `cd engine/src && ../.venv/bin/python routines.py --set mexc-scan` draait en schrijft een `routine_runs`-rij met `set_key='mexc-scan'`.
- [ ] `routine_run_log` heeft ≥1 rij level=`result` met "MEXC-scan:".
- [ ] Tweede run direct erna produceert weer een journal-entry (niet gegated).

---

### 4. UI-tab `App\Livewire\Coins\MexcScan` onder /coins met filters

**Status:** ✅ Gebouwd — inclusief "Nu verversen"-knop (Livewire `scanNow()`). Classificatie-UI (deel C, radio-knoppen) volgt bij epic-SV.

- Nieuw component `App\Livewire\Coins\MexcScan` (`mount()` → `abort_unless(auth()->user()?->is_admin, 403)`,
  `#[Layout('layouts.trading')]`), view `livewire/coins/mexc-scan.blade.php`.
- Route: `Route::get('/coins/mexc', \App\Livewire\Coins\MexcScan::class)->name('coins.mexc');`.
- **Tab-balk** bovenaan **beide** /coins-views (gedeelde partial of inline): "Kansrijk (engine)" → `coins.ranking`,
  "MEXC-markt" → `coins.mexc`, actieve staat via `request()->routeIs(...)`.
- **Kolommen:** Munt | **Volatiliteit** (sorteersleutel, gekleurde balk emerald/amber/gray op % van max) |
  24u-wijziging (groen/rood) | 24u-volume (USDT) | Marketcap (of "mcap onbekend") | Leeftijd (badge "nieuw" bij <7d, ">Nd" bij kline-fallback) | Contract (monospace, afgekort).
- **Filters (Livewire public props, `wire:model.live`):** `$mcapMin` (slider, default `10_000_000`),
  `$minVol24h` (slider, default `100_000`), `$hideUnder7d` (bool, default `true`). Sorteer default `volat_pct` desc.
- **Attributie (licentie-eis):** "Powered by CoinGecko"-regel met link onderaan de view + "Bron: mexc_market_scan ·
  bijgewerkt {fetched_at}".
- **Empty state:** "Nog geen scan — draai de routine `mexc-scan`."

#### Acceptance Criteria
- [ ] `/coins/mexc` is bereikbaar voor admin, geeft 403 voor niet-admin.
- [ ] Beide /coins-views tonen een tab-balk; de actieve tab is gemarkeerd via `routeIs`.
- [ ] Standaard worden alleen rijen getoond met `mcap_usd > $mcapMin` (of mcap onbekend, zichtbaar gemarkeerd) EN `vol24h_usd > $minVol24h` EN (`$hideUnder7d` ⇒ `age_days >= 7`).
- [ ] Wijzigen van een slider/checkbox (`wire:model.live`) herfiltert de lijst zonder pagina-reload.
- [ ] Default-sortering is `volat_pct` desc; volat toont een gekleurde balk; 24u-wijziging is groen/rood; `age_days<7` toont badge "nieuw".
- [ ] "Powered by CoinGecko"-attributie (met link) is zichtbaar in de view; `fetched_at` wordt getoond.
- [ ] Lege tabel → empty state met de routine-naam.
- [ ] De engine-tab (`coins.ranking`) is ongewijzigd in gedrag.

## Aanbevolen Implementatie Volgorde

1. CoinGecko Demo-key aanmaken (gratis account). Geen `pip`-install nodig (urllib); status-enum is al live geverifieerd.
2. Feature 1 — migratie + tabel.
3. Feature 2 — `mexc_scan.py` (MEXC-calls → CoinGecko-join → atomaire snapshot); **build-gate: tel mcap-dekking van de >10M-set** (contract-join zelf is al 92% gemeten).
4. Feature 3 — routine registreren + 1× draaien om de tabel te vullen.
5. Feature 4 — Livewire-component + view + route + tab-balk, browser-check.

## Nieuwe bestanden

| Bestand | Type | Feature |
|---|---|---|
| `www/database/migrations/2026_06_20_010000_create_mexc_market_scan_table.php` | Migratie | 1 |
| `engine/src/mexc_scan.py` | Python-module | 2 |
| `www/app/Livewire/Coins/MexcScan.php` | Livewire-component | 4 |
| `www/resources/views/livewire/coins/mexc-scan.blade.php` | Blade-view | 4 |
| `engine/src/test_mexc_scan.py` | Test (plat assert, mock HTTP) | Tests |
| `www/tests/Feature/CoinsMexcScanTest.php` | Test (Pest) | Tests |

## Te wijzigen bestanden

| Bestand | Wat | Feature |
|---|---|---|
| `engine/src/routines.py` | `routine_mexc_scan` + SET-constanten + SETS-entry | 3 |
| `www/routes/web.php` | Route `coins.mexc` toevoegen | 4 |
| `www/resources/views/livewire/coins/ranking.blade.php` | Tab-balk toevoegen bovenaan | 4 |

## Tests

| Bestand | Type | Dekt |
|---|---|---|
| `engine/src/test_mexc_scan.py` | plat assert + mock HTTP | volat_pct-berekening uit high/low; firstOpenTime→age_days (ms→dagen); kline-fallback markeert count==500 als ">=Nd"; join primair contract, symbool-fallback overslaan bij dubbele ticker; volume-filter weert MMUI-achtige; atomaire snapshot (fout op CG-pagina ⇒ geen truncate). |
| `www/tests/Feature/CoinsMexcScanTest.php` | Pest | admin-gate (403 voor niet-admin); seed `mexc_market_scan` + assert filters (`$mcapMin`/`$minVol24h`/`$hideUnder7d`) verbergen/tonen de juiste rijen; default-sortering volat desc; "Powered by CoinGecko"-attributie aanwezig; empty state. |

```bash
# engine-tests = plat assert-script (pytest zit NIET in de venv; volg het patroon van test_coin_metrics.py)
cd /Users/daanvantongeren/Documents/Sites/brain/engine/src && ../.venv/bin/python test_mexc_scan.py
/Applications/MAMP/bin/php/php8.4.17/bin/php artisan test tests/Feature/CoinsMexcScanTest.php
```

## Niet in scope

- **Klines-gebaseerde volatiliteit-maat** (% uur-candles met |log-return|≥3%) — v2-verfijning; v1 gebruikt de gratis 24u-range.
- **Automatisch opnemen van een kandidaat in de engine** — blijft een handmatige mens-stap (symbol toevoegen + indicator-ingestie); daarna pakt `coin-metrics` de coin vanzelf op.
- **Cross-coin rotatie-rangschikking / pauzeer-advies** — vervolg (Epic V-vervolg), zodra meer coins gelijktijdig leven.
- **Betaalde CoinGecko-tier** — niet nodig bij ~180 calls/maand; alleen overwegen bij bredere dekking (>top 1250) of als attributie ongewenst wordt.
- **Backtest-validatie van de 14/90d-leeftijddrempels** — geblokkeerd door te weinig gelijktijdige coins; volgt later.
- **Ongedocumenteerde MEXC web-endpoints voor marketcap** — bewust niet (instabiel/ban-risico).
