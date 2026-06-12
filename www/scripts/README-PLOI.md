# Ploi.io Deployment Configuratie

## ⚠️ Belangrijk

De `www/content/` en `www/storage/` folders worden **NIET** getracked in Git.
Deze folders moeten **beschermd** worden tijdens deployments om dataverlies te voorkomen.

## ✅ Ploi.io Configuratie

### Optie 1: Deployment Script (Aanbevolen)

In Ploi.io, ga naar je site → **Deployment** → **Deployment Script**:

```bash
#!/bin/bash
cd /path/to/your/site/www
bash scripts/ploi-deploy.sh
```

**Of als Ploi.io automatisch git pull doet, gebruik dan alleen het restore gedeelte:**

Voeg toe aan je **"Activate New Release"** script:

```bash
#!/bin/bash
cd /path/to/your/site/www

# Backup content en storage VOOR deployment
BACKUP_DIR="../../backup/ploi-deploy-$(date +%Y%m%d-%H%M%S)"
mkdir -p "$BACKUP_DIR"

# Backup content
[ -d "content" ] && cp -r content "$BACKUP_DIR/" 2>/dev/null || true

# Backup storage belangrijke bestanden
[ -d "storage" ] && mkdir -p "$BACKUP_DIR/storage" && \
    cp -r storage/app/public "$BACKUP_DIR/storage/" 2>/dev/null || true

# Ploi.io doet hier automatisch git pull/checkout

# Herstel content als verwijderd
[ ! -d "content" ] && [ -d "$BACKUP_DIR/content" ] && \
    mkdir -p content && cp -r "$BACKUP_DIR/content"/* content/ 2>/dev/null || true

# Herstel storage symlink
[ ! -L "public/storage" ] && php artisan storage:link 2>/dev/null || true

# Composer install
composer install --no-dev --optimize-autoloader

# Cache clearing
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan statamic:stache:clear
```

### Optie 2: Pre & Post Deployment Hooks

**Pre-Deployment Hook** (voordat Ploi.io git pull doet):

```bash
#!/bin/bash
cd /path/to/your/site/www

# Backup content en storage
BACKUP_DIR="../../backup/ploi-pre-deploy-$(date +%Y%m%d-%H%M%S)"
mkdir -p "$BACKUP_DIR"
[ -d "content" ] && cp -r content "$BACKUP_DIR/" 2>/dev/null || true
[ -d "storage/app/public" ] && mkdir -p "$BACKUP_DIR/storage" && \
    cp -r storage/app/public "$BACKUP_DIR/storage/" 2>/dev/null || true
```

**Post-Deployment Hook** (na git pull):

```bash
#!/bin/bash
cd /path/to/your/site/www

# Herstel content
BACKUP_DIR=$(ls -td ../../backup/ploi-pre-deploy-* 2>/dev/null | head -1)
if [ -n "$BACKUP_DIR" ] && [ ! -d "content" ] && [ -d "$BACKUP_DIR/content" ]; then
    mkdir -p content
    cp -r "$BACKUP_DIR/content"/* content/ 2>/dev/null || true
fi

# Herstel storage symlink
[ ! -L "public/storage" ] && php artisan storage:link 2>/dev/null || true

# Composer en cache
composer install --no-dev --optimize-autoloader
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan statamic:stache:clear
```

## 📋 Standaard Ploi.io Deployment Commands

Als je alleen de standaard Ploi.io commands gebruikt, voeg dan toe:

**Composer:**
```bash
composer install --no-dev --optimize-autoloader
```

**Storage Link:**
```bash
php artisan storage:link
```

**Cache Clearing:**
```bash
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan statamic:stache:clear
```

**⚠️ BELANGRIJK:** Voeg altijd content/storage backup en restore toe!

## 🔍 Verificatie

Na deployment, controleer:

1. ✅ `www/content/` bestaat en bevat bestanden
2. ✅ `www/storage/` bestaat
3. ✅ `public/storage` is een symlink naar `storage/app/public`
4. ✅ Website laadt correct
5. ✅ Statamic Control Panel werkt

## 🆘 Probleem Oplossen

**Content folder verwijderd?**
```bash
# Zoek laatste backup
ls -td backup/ploi-* | head -1

# Herstel
cp -r backup/ploi-[DATUM]/content www/content/
```

**Storage symlink ontbreekt?**
```bash
cd www
php artisan storage:link
```

**Storage folder leeg?**
```bash
# Herstel van backup
cp -r backup/ploi-[DATUM]/storage/* www/storage/
```

## 📝 Wat wordt beschermd?

- ✅ `www/content/` - Statamic content (niet in Git)
- ✅ `www/storage/app/public/` - Geüploade bestanden
- ✅ `www/storage/framework/` - Cache en sessies
- ✅ `www/storage/logs/` - Log bestanden
- ✅ `public/storage` - Symlink naar storage

## 💡 Tips

1. **Test eerst op staging** voordat je naar productie deployt
2. **Controleer backups regelmatig** - ze worden opgeslagen in `backup/ploi-*`
3. **Gebruik het volledige script** (`ploi-deploy.sh`) voor maximale bescherming
4. **Monitor deployments** - check altijd of content/storage nog bestaat na deploy

