# Deploy: MEXC-scan job op de 66bio-VPS

Verzamel-job die elke 4 uur de MEXC-markt scant en de tijdreeks + classificatie-basis opbouwt in een
eigen `mexc`-database. Staat los van de Laravel-website (die migreert later, samen). Ontwerp +
beslissingen: [`docs/findings/mexc-coin-tracking-2026-06-29.md`](../findings/mexc-coin-tracking-2026-06-29.md).

**Server:** `root@116.203.78.110` (Hetzner `qr-hetzner`, Ubuntu 24.04, MySQL 8.4.10, Python 3.12, Ploi-managed).
**Raakt NIET:** de bestaande `gratisqrcode_prod`-site of de Ploi-cron.

---

## 0. Vooraf gecheckt (2026-06-29)
- Python 3.12.3 aanwezig; **`pymysql` ontbreekt** → eigen venv.
- MySQL 8.4.10 draait; alleen `gratisqrcode_prod` bestaat.
- Cron actief, **root-crontab leeg** (Ploi draait z'n eigen cron onder user `ploi`).
- Uitgaand naar `api.mexc.com` → http 200. ✅

---

## 1. Code op de server
Minimale footprint — alleen de 2 scripts + het schema. Map: `/opt/mexc-scan/`.

```bash
ssh root@116.203.78.110 'mkdir -p /opt/mexc-scan/src /opt/mexc-scan/log'
scp engine/src/db.py engine/src/mexc_scan.py        root@116.203.78.110:/opt/mexc-scan/src/
scp engine/sql/mexc_schema.sql                       root@116.203.78.110:/opt/mexc-scan/
```

> Alternatief (bij voorkeur voor onderhoud): git sparse-checkout van de brain-repo met een deploy-key,
> zodat `git pull` volstaat. Voor nu houden we het bij `scp` van 2 bestanden.

## 2. Python-venv + pymysql
```bash
ssh root@116.203.78.110 '
  apt-get update -qq && apt-get install -y python3-venv >/dev/null
  python3 -m venv /opt/mexc-scan/venv
  /opt/mexc-scan/venv/bin/pip install -q --upgrade pip pymysql
  /opt/mexc-scan/venv/bin/python -c "import pymysql; print(\"pymysql\", pymysql.__version__)"
'
```

## 3. Database + user + schema
Eigen DB met een dedicated user (TCP-login voor pymysql; root gebruikt auth_socket en werkt niet over TCP).
Kies een sterk wachtwoord en bewaar het in de env-file (stap 4).

```bash
ssh root@116.203.78.110 'mysql' <<SQL
CREATE DATABASE IF NOT EXISTS mexc CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS 'mexc'@'127.0.0.1' IDENTIFIED BY 'VERVANG_MET_STERK_WW';
GRANT ALL PRIVILEGES ON mexc.* TO 'mexc'@'127.0.0.1';
FLUSH PRIVILEGES;
SQL
ssh root@116.203.78.110 'mysql mexc < /opt/mexc-scan/mexc_schema.sql && mysql -N -e "SHOW TABLES" mexc'
# verwacht: mexc_coin_labels, mexc_market_scan, mexc_snapshots
```

## 4. Env-file
`/opt/mexc-scan/.env` (chmod 600 — bevat het DB-wachtwoord + CoinGecko-key):

```ini
MEXC_DB_HOST=127.0.0.1
MEXC_DB_PORT=3306
MEXC_DB_USER=mexc
MEXC_DB_PASS=VERVANG_MET_STERK_WW
MEXC_DB_NAME=mexc
CG_DEMO_KEY=CG-Ued2J5LkYYVJhoGD9dYvHSHS
```

```bash
ssh root@116.203.78.110 'chmod 600 /opt/mexc-scan/.env'
```

## 5. Run-wrapper
`/opt/mexc-scan/run.sh` (sourcet env, draait de scan, logt met tijdstempel):

```bash
#!/usr/bin/env bash
set -euo pipefail
cd /opt/mexc-scan/src
set -a; source /opt/mexc-scan/.env; set +a
exec /opt/mexc-scan/venv/bin/python mexc_scan.py >> /opt/mexc-scan/log/scan.log 2>&1
```

```bash
ssh root@116.203.78.110 'chmod +x /opt/mexc-scan/run.sh'
# eerste handmatige run (verifieer dat snapshot + history vullen):
ssh root@116.203.78.110 '/opt/mexc-scan/run.sh && tail -5 /opt/mexc-scan/log/scan.log'
ssh root@116.203.78.110 'mysql -N mexc -e "SELECT COUNT(*) scan FROM mexc_market_scan; SELECT COUNT(*) hist, MAX(snapshot_at) laatste FROM mexc_snapshots;"'
```

## 6. Cron — elke 4 uur
Eigen bestand in `/etc/cron.d/` (raakt de Ploi-crontab niet). Draait als root, op de hele 4-uurs grens:

```bash
ssh root@116.203.78.110 'cat > /etc/cron.d/mexc-scan <<CRON
# MEXC-marktscan — elke 4 uur (00:05, 04:05, ...). Logt naar /opt/mexc-scan/log/scan.log
5 */4 * * * root /opt/mexc-scan/run.sh
CRON
chmod 644 /etc/cron.d/mexc-scan'
```

> Cadans: 6×/dag. De `:05`-offset vermijdt samenval met andere `00:00`-jobs.

## 7. Verificatie na ~1 dag
```bash
ssh root@116.203.78.110 'mysql -N mexc -e "
  SELECT DATE_FORMAT(snapshot_at,\"%Y-%m-%d %H:00\") uur, COUNT(*) n
  FROM mexc_snapshots GROUP BY 1 ORDER BY 1 DESC LIMIT 8;"'
```
Verwacht ~6 rijen-groepen/dag (één per 4-uurs run), elk met de kandidaat-set.

---

## Onderhoud / aandachtspunten
- **Logrotatie:** `scan.log` groeit langzaam; later een `logrotate`-snippet als het nodig is.
- **Backups:** valt de `mexc`-DB onder de bestaande 66bio-backup-routine? → checken; zo niet, toevoegen.
- **Code-update:** opnieuw `scp` (stap 1) — geen herstart nodig, de volgende cron pakt het op.
- **auto_flag-drempels** (`FALLER_RET7`/`CHOPPY_*` in `mexc_scan.py`) zijn eerste-gok; later afstemmen op
  Daans goed/slecht-labels.
- **Website-koppeling:** het classificatie-scherm leest/schrijft `mexc_coin_labels` en komt live bij de
  website-migratie (samen). Tot dan bouwt deze job alleen de data op.

---

## Werkelijk gevolgde route (2026-06-29) — afwijkingen van bovenstaand runbook

- **Schema laden + queries via pymysql, NIET de `mysql`-CLI.** De CLI resolveert `127.0.0.1`→`localhost` en
  matcht dan `user@localhost` (verkeerd ww) → "Access denied". pymysql (host=127.0.0.1) matcht `user@127.0.0.1`.
  Forceer anders `mysql --protocol=TCP`. Zie [[qr-vps-mysql-access]].
- **`run.sh` apart aangemaakt** (ontbrak doordat stap 3 eerder op de DB-fout afbrak).
- **Wachtwoorden op de server gegenereerd** (`openssl rand -hex 20`) i.p.v. handmatig in het runbook.

## §A — Server-MySQL-recovery (was nodig vóór de deploy kon)

MySQL stond sinds 17 juni in `mysqld_safe --skip-grant-tables --skip-networking` (geen TCP → Ploi kon geen
DB maken). Hersteld (qr-sites stonden niet live):

```bash
# 1. skip-instantie stoppen
pkill -f mysqld_safe; sleep 2; pkill -x mysqld
for i in $(seq 1 20); do pgrep -x mysqld >/dev/null || break; sleep 1; done

# 2. verse skip-grant-tables (socket-only) voor root-toegang
mysqld_safe --skip-grant-tables --skip-networking >/dev/null 2>&1 &
sleep 8

# 3. wachtwoorden resetten + mexc aanmaken (één mysql --no-defaults sessie, FLUSH eerst)
mysql --no-defaults <<SQL
FLUSH PRIVILEGES;
ALTER USER 'root'@'localhost' IDENTIFIED BY '<ROOT_WW>';
ALTER USER 'ploi'@'127.0.0.1' IDENTIFIED BY '<PLOI_WW>';
ALTER USER 'ploi'@'%' IDENTIFIED BY '<PLOI_WW>';
CREATE DATABASE IF NOT EXISTS mexc CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS 'mexc'@'127.0.0.1' IDENTIFIED BY '<MEXC_WW>';
GRANT ALL PRIVILEGES ON mexc.* TO 'mexc'@'127.0.0.1';
FLUSH PRIVILEGES;
SQL

# 4. skip-instantie stoppen, normaal via systemd starten (unit is schoon)
pkill -f mysqld_safe; sleep 2; pkill -x mysqld
for i in $(seq 1 20); do pgrep -x mysqld >/dev/null || break; sleep 1; done
systemctl start mysql
```

Daarna: PLOI_WW in Ploi → server → Database settings → "Database ploi user password" + Save.
Root-ww in Daans kluis. Verifieer: `ss -tlnp | grep 3306` (TCP terug), root-login werkt, qr-DBs intact.
