# EPIC SV: Server-verhuizing — brain-stack naar de QR-server

**Phase:** 4 — Execution (infra)
**Status:** In uitvoering (2026-06-30) — F1 ✅ klaar; volledige verhuizing gestart op verzoek van Daan
**Depends on:** Epic TV (feed-ontvanger als eerste stap op de server) — **losgekoppeld:** de MEXC-scan bewijst
al dat de server stabiel draait, dus SV gaat door zonder op TV te wachten. bot_signals/feed blijven los (beslissing #1).

## Voortgang (2026-06-30)

- **F1 (db.py env-configureerbaar) ✅** — `brain()`/`legacy()`/`mexc()` lezen `BRAIN_DB_*`/`LEGACY_DB_*`/`MEXC_DB_*`
  via gedeelde `_cfg()`. **Afwijking van het plan:** defaults wijzen naar **lokale MAMP** (niet de server), net als
  `mexc()`. Reden: Daan blijft lokaal testen → frictieloos lokaal; de server-cron sourcet de env-file toch.
  `legacy()` geeft nu een duidelijke melding (RuntimeError) als bot_signals ontbreekt i.p.v. een cryptische crash.
  Lokaal geverifieerd: `brain()` → MAMP 8889, 485 rules.
- **Server-staat:** lege `brain`-DB aangemaakt via Ploi (2026-06-30). Laravel-app + engine nog niet gedeployed.
  MySQL-toegang + Ploi-quirks: zie [[qr-vps-mysql-access]] / `docs/deployment/mexc-scan-server.md` §A/§B.
- **Repo:** `github.com/daan-ost/brain` (privé → deploy-key nodig op de server). Lokale brain-DB = 3050 MB.

---

## Epic Specification

De hele brain-stack (MySQL-database, Python trading-engine, Laravel dashboard-app) verplaatsen van
Daans laptop (MAMP PRO) naar de QR-server (66bio-host), zodat:
- De laptop niet langer als dataserver dient (~28 GB aan databases + engine vrijgeven)
- Routines en refires 24/7 op de server draaien zonder dat de laptop open hoeft
- Data en compute op één plek staan (geen cross-server import nodig)
- Het dashboard remote bereikbaar is op `nobrainersbot.com`

## Rationale

De brain-database (3 GB) + bot_signals (22,7 GB) + engine-cache (600 MB) beslaat ~28 GB op de laptop.
De engine draait alleen als Daan handmatig een sessie start, en routines worden gekilld als de sessie
afloopt. Door alles naar de server te verplaatsen die toch al 24/7 draait (voor de QR-sites en straks
de feed-ontvanger), wordt de laptop vrij en krijgt de engine een stabiele omgeving. Epic-TV zet al een
voet op de server (feed-ontvanger); dit epic verhuist de rest.

## Dependencies

- **Epic TV** (feed-ontvanger + Caddy-vhost + DB `nobrainers`) — moet eerst staan, is het bewijs dat
  de server goed draait
- **Server hardware** — ✅ Hetzner CX53: 16 vCPU, 32 GB RAM, 320 GB disk (IP `116.203.78.110`,
  Neurenberg, SSH `root@116.203.78.110`). Ruim voldoende voor engine + QR-sites samen
- **Werkelijke stack** (geverifieerd 2026-06-29, wijkt af van 66bio/deploy-scripts):
  Nginx 1.30.2 (niet Caddy), Ploi-managed, PHP 8.4 (niet 8.2), MySQL 8.4 (niet 8.0),
  Python 3.12 systeem, webroot `/home/ploi/<domain>/www` (niet `/sites/`),
  TLS via certbot (niet Caddy auto-TLS)

## Bestaande code (referentie)

**Engine `db.py`** — `engine/src/db.py`
```python
_COMMON = dict(host="127.0.0.1", port=8889, user="root", password="root", autocommit=True,
               read_timeout=600, write_timeout=600, connect_timeout=10, ssl=None)
```
Hardgecodeerd op MAMP (poort 8889, root/root). Moet env-configureerbaar worden (zoals `mexc()` al is).

**Engine `import_indicators.py`** — `engine/src/import_indicators.py`
Leest uit `bot_signals.wp_trading_indicator` → schrijft naar `brain.indicators`. Na de verhuizing leest
de engine uit de TV-feed (`nobrainers.tv_indicators`) i.p.v. `bot_signals` — dat is een vervolgepic.

**Laravel `.env`** — `www/.env` (niet in git)
```
DB_HOST=127.0.0.1
DB_PORT=8889
DB_DATABASE=brain
DB_USERNAME=root
DB_PASSWORD=root
```

**Deploy-infra** — `66bio/deploy/` (Caddyfile, deploy-site.sh, setup-vps.sh)

## Beslissingen

| # | Vraag | Beslissing |
|---|---|---|
| 1 | Wat verhuist er? | `brain` database (3 GB) + Python engine + Laravel app. `bot_signals` (22,7 GB) verhuist **niet** — historische data is al geïmporteerd in `brain.indicators`, en na de verhuizing stappen we over op de TV-feed. Doe een laatste import voor alle benodigde munten vóór de VPS-afscheid. |
| 2 | Eén domein of twee? | Alles onder `nobrainersbot.com` — het dashboard (Laravel) is de hoofdsite, de feed-ontvanger (epic-TV) draait als standalone PHP in `public/app/retrieve_signal_tv.php`. |
| 3 | Webroot-structuur | Nginx root = `/home/ploi/nobrainersbot.com/www/public` (Laravel-standaard, Ploi-conventie). De feed-receiver uit epic-TV komt in `public/app/retrieve_signal_tv.php` — Nginx serveert dat als bestaand PHP-bestand vóórdat de try_files naar `index.php` valt. |
| 4 | Database op de server | De `brain` DB draait in dezelfde MySQL 8.4 als de QR-sites en de `nobrainers` feed-DB. Eigen DB-user `brain` met beperkte rechten. |
| 5 | Engine DB-connectie | `db.py` wordt env-configureerbaar (zoals `mexc()` al is): `BRAIN_DB_HOST`, `BRAIN_DB_PORT`, `BRAIN_DB_USER`, `BRAIN_DB_PASS`. Default = server (`localhost:3306`). Lokaal override je via env naar MAMP. |
| 6 | Python-versie | Server heeft Python 3.12.3 (systeem). De engine is geschreven voor 3.10 maar zou op 3.12 moeten werken (numpy<2.0, pysubgroup 0.9.0 zijn compatibel). Testen; anders deadsnakes PPA voor 3.10. |
| 7 | Routines | De dagelijkse sell-tuning + rule-precision + refire draaien als cron of via `screen`/`tmux` op de server. Geen Laravel scheduler — dat zijn aparte Python-processen. |
| 8 | Development workflow | Code in git (GitHub) → lokaal bewerken → `git push` → op server `git pull`. Claude Code kan ook via SSH remote werken. Geen CI/CD — handmatige deploy. |
| 9 | bot_signals afsluiting | Vóór de VPS-opzegging: verifieer dat **alle** benodigde historische data (alle munten, alle indicatoren) in `brain.indicators` staat. Daarna is bot_signals niet meer nodig. Optioneel: een mysqldump als archief. |
| 10 | Laravel scheduler | De basewebsite-scheduled-tasks (license processing, cleanup, etc.) zijn leeg/ongebruikt voor brain. Niet opstarten op de server tenzij nodig. |
| 11 | SSL/MAMP-rommel | `ssl=None` en de MAMP-workarounds in db.py vervallen op de server (echte MySQL, geen SSL-drops). Houd de retry-logic voor robuustheid. |

## Features (7)

### 1. Engine `db.py` env-configureerbaar maken

**Status:** ✅ Gebouwd (2026-06-30) — defaults = lokale MAMP i.p.v. server (afwijking, zie Voortgang)

De hardgecodeerde MAMP-connectie (`127.0.0.1:8889`, `root/root`) vervangen door environment
variables, naar het patroon van de bestaande `mexc()`-functie:

```python
# engine/src/db.py — NIEUW
_COMMON = dict(
    host=os.environ.get("BRAIN_DB_HOST", "127.0.0.1"),
    port=int(os.environ.get("BRAIN_DB_PORT", "3306")),
    user=os.environ.get("BRAIN_DB_USER", "brain"),
    password=os.environ.get("BRAIN_DB_PASS", ""),
    autocommit=True, read_timeout=600, write_timeout=600, connect_timeout=10, ssl=None,
)
```

Defaults wijzen naar de **server** (localhost:3306, user brain). Lokaal override je:
```bash
export BRAIN_DB_HOST=127.0.0.1 BRAIN_DB_PORT=8889 BRAIN_DB_USER=root BRAIN_DB_PASS=root
```

De `legacy()`-functie blijft bestaan maar wordt conditioneel — op de server is er geen bot_signals.

#### Acceptance Criteria
- [ ] `brain()` en `legacy()` lezen host/port/user/pass uit env-variabelen
- [ ] Zonder env-variabelen: defaults wijzen naar de server (localhost:3306)
- [ ] Bestaande lokale workflow werkt met `export BRAIN_DB_*=MAMP-waarden`
- [ ] `legacy()` geeft een duidelijke foutmelding als bot_signals niet bestaat (niet een cryptische crash)

### 2. Database-export en -import

**Status:** Approved

De `brain` database (3 GB) exporteren van MAMP en importeren op de server.

```bash
# Lokaal — export
/Applications/MAMP/Library/bin/mysql80/bin/mysqldump -u root -proot -P 8889 -h 127.0.0.1 \
  --single-transaction --routines --triggers brain > /tmp/brain_export.sql

# Op de server — import
mysql -u root -p -e "CREATE DATABASE brain CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
mysql -u root -p -e "CREATE USER 'brain'@'localhost' IDENTIFIED BY '<sterk-wachtwoord>';"
mysql -u root -p -e "GRANT ALL PRIVILEGES ON brain.* TO 'brain'@'localhost'; FLUSH PRIVILEGES;"
mysql -u brain -p brain < brain_export.sql
```

Tabellen met de meeste data (~97% van de 3 GB):
- `indicators` — 785 MB, 10,6M rijen
- `indicator_metrics` — 1,2 GB, 3,5M rijen

#### Acceptance Criteria
- [ ] `brain` database staat op de server met alle tabellen en data
- [ ] Eigen DB-user `brain` met beperkte rechten (alleen `brain.*`)
- [ ] Tabeltellingen kloppen (steekproef: `indicators`, `coin_fires`, `rules`)
- [ ] Engine kan verbinden en een read-query uitvoeren

### 3. Python engine op de server

**Status:** Approved

Python 3.10 + venv + dependencies installeren, engine-code deployen.

```bash
# Ubuntu 24.04 — Python 3.10 via deadsnakes
sudo add-apt-repository ppa:deadsnakes/ppa
sudo apt update && sudo apt install python3.10 python3.10-venv python3.10-dev

# Engine-code (git clone of als subdir van de brain-repo)
cd /sites/nobrainers
git clone git@github.com:<repo>.git engine  # of: het zit in de brain-repo
cd engine
python3.10 -m venv .venv
.venv/bin/pip install -r requirements.txt

# Test
.venv/bin/python src/run_engine.py --help
```

Engine-directory op de server:
```
/home/ploi/nobrainersbot.com/engine/
├── .venv/           ← Python 3.12 venv (niet in git)
├── src/             ← de engine-code
├── data/            ← cache (regenereert)
├── out/             ← output (regenereert)
└── requirements.txt
```

#### Acceptance Criteria
- [ ] `python3.12 -m venv .venv` + `pip install -r requirements.txt` slaagt op de server
- [ ] `run_engine.py` draait en kan de brain-DB bereiken
- [ ] Een test-refire op één munt produceert dezelfde `coin_fires` als lokaal
- [ ] pysubgroup/wittgenstein laden correct (geen SIGBUS — geen Apple Silicon op de server)
- [ ] NUMBA_DISABLE_JIT niet meer nodig op x86_64 — verifieer dat pysubgroup JIT normaal werkt

### 4. Laravel app deployen

**Status:** Approved

Het brain-dashboard (Laravel 12, Livewire 3, Filament 3) deployen op de server.

```bash
# Webroot
sudo mkdir -p /sites/nobrainers/www
cd /sites/nobrainers/www
git clone git@github.com:<repo>.git .  # of git pull als het al staat

# Dependencies
composer install --no-dev --optimize-autoloader
npm install && npm run build

# Config
cp .env.example .env
# Edit .env: DB_HOST=127.0.0.1, DB_PORT=3306, DB_DATABASE=brain, DB_USERNAME=brain, ...
php artisan key:generate
php artisan migrate --force
php artisan config:cache && php artisan route:cache && php artisan view:cache
```

**Let op:** de feed-ontvanger uit epic-TV komt in `public/app/retrieve_signal_tv.php` (standalone
PHP, geen Laravel). Caddy serveert dat als bestaand bestand vóór de try_files naar index.php.

#### Acceptance Criteria
- [ ] `nobrainersbot.com` toont het brain-dashboard (login-pagina)
- [ ] Admin-login werkt (user-data staat in de geïmporteerde brain-DB)
- [ ] `/coins`, `/coins/weekly`, `/rules` pagina's laden correct
- [ ] Feed-ontvanger (`/app/retrieve_signal_tv.php`) blijft werken naast Laravel

### 5. Caddy-vhost aanpassen (feed + Laravel samen)

**Status:** Approved

De Caddy-vhost uit epic-TV's deployment-doc aanpassen zodat zowel de feed-ontvanger als de Laravel
app werken onder `nobrainersbot.com`:

Zie `docs/deployment/qr-server.md` §4 voor de complete Nginx-vhost (patroon van de bestaande
`gratisqrcode.nl`-config, aangepast voor Laravel + standalone feed-receiver). Kern: root =
`/home/ploi/nobrainersbot.com/www/public`, `try_files $uri $uri/ /index.php?$query_string`,
PHP via `php8.4-fpm.sock`.

#### Acceptance Criteria
- [ ] `curl -X POST nobrainersbot.com/app/retrieve_signal_tv.php` bereikt de feed-ontvanger (standalone PHP)
- [ ] `curl nobrainersbot.com/login` bereikt de Laravel login-pagina
- [ ] Statische assets (CSS/JS/images) worden correct geserveerd via Nginx
- [ ] `.env`/vendor/storage-paden zijn geblokkeerd (403/deny)

### 6. Engine-routines op de server draaien

**Status:** Approved

De dagelijkse routines die nu handmatig in een terminal draaien, kunnen op de server als cron of via
`screen`/`tmux`.

**Routines (Python, lang-lopend):**
- `sell_tuning/apply.py --apply-run` — dagelijks, ~30 min (4 munten)
- `optimize.py` — rule-precision, ~15 min
- `refire.py` — na rule-wijzigingen, ~15 min koud / seconden warm (fires-cache)

**Voorbeeld crontab:**
```cron
# Dagelijkse sell-tuning (06:00 UTC, na de nachtelijke TV-indicatoren)
0 6 * * * cd /home/ploi/nobrainersbot.com/engine && .venv/bin/python src/sell_tuning/apply.py --apply-run >> /var/log/brain/sell-tuning.log 2>&1
```

**Log-directory:**
```bash
sudo mkdir -p /var/log/brain
sudo chown www-data:www-data /var/log/brain
```

De routines draaien met dezelfde env-variabelen als de engine (BRAIN_DB_* niet nodig als defaults
naar de server wijzen).

#### Acceptance Criteria
- [ ] Een handmatige sell-tuning run slaagt op de server
- [ ] Output wordt gelogd in `/var/log/brain/`
- [ ] Cron-entries draaien zonder interactie (geen prompt, geen tty)
- [ ] Lange runs (>30 min) worden niet gekilld door systemd/timeout

### 7. Laatste import + laptop-opschoning

**Status:** Approved

Voordat de laptop en VPS opgeschoond/opgezegd worden:

**Vóór de VPS-opzegging:**
1. Verifieer dat alle benodigde historische data in `brain.indicators` staat voor alle munten
2. Optioneel: `mysqldump bot_signals > bot_signals_archief.sql.gz` als noodbackup (~22 GB, compressie
   brengt het naar ~4-5 GB)
3. Verifieer dat de TV-feed binnenkomt op de server (epic-TV live)
4. DNS-cutover voor nobrainersbot.com naar de QR-server

**Laptop opschonen na verificatie:**
- MAMP PRO: `brain` database droppen (3 GB vrij)
- MAMP PRO: `bot_signals` database droppen (22,7 GB vrij)
- `brain/engine/data/` cache-bestanden (600 MB vrij)
- `brain/engine/src/discovery/.cache/` (328 MB vrij)
- `brain/engine/.venv/` (lokale venv niet meer nodig als engine op server draait)

**Totaal terug te winnen: ~28 GB**

#### Acceptance Criteria
- [ ] Steekproef: `brain.indicators` op de server heeft dezelfde rij-tellingen als lokaal
- [ ] TV-feed rijen komen binnen op de server
- [ ] Dashboard draait op `nobrainersbot.com`
- [ ] Alle engine-routines produceren dezelfde output als lokaal (bit-identiek refire-test)
- [ ] Pas daarna lokaal droppen — niet eerder

## Aanbevolen implementatie-volgorde

1. **Feature 1** (db.py env-configureerbaar) — kan nu al, is een code-wijziging zonder server
2. **Epic TV bouwen** — de feed-ontvanger, eerste bewijs dat de server draait
3. **Feature 2** (database-export/import) — brain DB naar de server
4. **Feature 3** (Python engine) — engine installeren en test-refire
5. **Feature 5** (Caddy-vhost) — feed + Laravel samen
6. **Feature 4** (Laravel deploy) — dashboard live
7. **Feature 6** (routines) — cron/screen instellen
8. **Feature 7** (opschoning) — pas als alles bewezen werkt

Feature 1 kan **nu** al gebouwd worden, onafhankelijk van de server. De rest volgt na epic-TV.

## Nieuwe bestanden aan te maken

| Bestand | Type | Feature |
|---|---|---|
| `engine/src/db.py` (wijziging) | Python | F1 — env-configureerbare connectie |
| `/home/ploi/nobrainersbot.com/engine/.venv/` (op server) | Python venv | F3 — niet in git |
| `/etc/nginx/sites-available/nobrainersbot.com` (op server) | Nginx config | F5 — vhost |
| `/var/log/brain/` (op server) | Log-directory | F6 — routine-logs |
| crontab-entries (op server) | Cron | F6 — routine-schedule |

## Niet in scope

- **Engine-import aanpassing** (lezen uit TV-feed i.p.v. bot_signals) — apart vervolgepic
- **CI/CD pipeline** — handmatige git pull is voldoende voor de frequentie van deploys
- **Monitoring/alerting** — later, als de stack bewezen stabiel draait
- **Meerdere environments** (staging/prod) — niet nodig bij dit volume
- **Docker** — de server draait native PHP + Python, geen containerisatie nodig
- **Automatische backups** — checken of de bestaande 66bio-backup-routine de brain-DB meepakt; zo niet,
  apart inrichten (maar buiten deze epic)
