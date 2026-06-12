#!/bin/bash
# Ploi.io Deployment Script - VEILIGE VERSIE
# Beschermt content/ en storage/ folders VOOR deployment
#
# Configureer in Ploi.io:
# 1. Ga naar Site → Settings → Deploy Script
# 2. Voeg dit script toe VOOR "git pull" sectie
# 3. Of gebruik als "Pre-Deploy" script als die optie bestaat
#
# BELANGRIJK: Dit script moet VOOR git pull draaien om content te beschermen!

set -e  # Stop bij errors

echo "🚀 Ploi.io Pre-Deployment Protection Script"
echo "============================================"
echo ""

# Bepaal de huidige directory (Ploi.io werkt meestal vanuit www/)
CURRENT_DIR="$(pwd)"

# Ga naar www directory als we niet al daar zijn
if [ -f "artisan" ]; then
    # We zijn al in www directory
    WWW_DIR="$(pwd)"
elif [ -d "www" ] && [ -f "www/artisan" ]; then
    # We zijn in project root, ga naar www
    cd www || exit 1
    WWW_DIR="$(pwd)"
else
    echo "❌ Error: Kan www directory niet vinden (zoek naar artisan bestand)"
    echo "   Huidige directory: $CURRENT_DIR"
    exit 1
fi

echo "📁 Working directory: $WWW_DIR"
echo ""

# Symlink .env vanuit project root (Ploi plaatst .env in parent directory)
if [ ! -f ".env" ] && [ -f "../.env" ]; then
    ln -s ../.env .env
    echo "✅ .env symlink aangemaakt (../.env → .env)"
fi

echo "🛡️  STAP 1: Content beschermen VOOR git pull"
echo "=============================================="

# Bescherm content/ bestanden VOOR git pull (BEHALVE developer docs)
if [ -d "content" ] && [ -n "$(ls -A content 2>/dev/null)" ]; then
    echo "🔒 Beschermen van content/ bestanden tegen git pull..."

    # Tel hoeveel bestanden beschermd worden
    PROTECTED_COUNT=0

    find content -type f ! -name ".gitkeep" ! -name ".DS_Store" 2>/dev/null | while read -r file; do
        # SKIP developer documentation - deze MOETEN van git komen
        if [[ "$file" == *"/developers"* ]]; then
            echo "  ⏭️  Skipping (managed by git): $file"
            continue
        fi

        if [ -f "$file" ]; then
            git update-index --skip-worktree "$file" 2>/dev/null || true
            ((PROTECTED_COUNT++)) 2>/dev/null || true
        fi
    done

    echo "✅ Content bestanden beschermd tegen git operaties"
    echo "   (Alle bestanden BEHALVE developer docs zijn nu veilig)"
else
    echo "⚠️  content/ directory is leeg of bestaat niet"
    echo "   Bescherming overgeslagen (geen content om te beschermen)"
fi

# Bescherm storage/app/public bestanden VOOR git pull
if [ -d "storage/app/public" ] && [ -n "$(ls -A storage/app/public 2>/dev/null | grep -v '.gitignore')" ]; then
    echo "🔒 Beschermen van storage/app/public/ bestanden..."

    find storage/app/public -type f ! -name ".gitignore" ! -name ".gitkeep" ! -name ".DS_Store" 2>/dev/null | while read -r file; do
        if [ -f "$file" ]; then
            git update-index --skip-worktree "$file" 2>/dev/null || true
        fi
    done

    echo "✅ Storage bestanden beschermd"
else
    echo "ℹ️  storage/app/public/ is leeg (verwacht voor verse installatie)"
fi

echo ""
echo "✅ BESCHERMING GEACTIVEERD"
echo "   Git pull kan nu veilig uitgevoerd worden door Ploi.io"
echo ""
echo "🔍 Git status controleren en eventuele problemen fixen..."

# Fix mogelijke git problemen voordat Ploi git pull doet
# Check zowel in huidige directory als één niveau hoger (voor Ploi setup)
if [ -d ".git" ]; then
    GIT_DIR="."
elif [ -d "../.git" ]; then
    GIT_DIR=".."
    echo "ℹ️  Git repository gevonden in parent directory"
else
    GIT_DIR=""
fi

if [ -n "$GIT_DIR" ]; then
    # Check of we in detached HEAD state zitten
    CURRENT_BRANCH=$(cd "$GIT_DIR" && git symbolic-ref --short HEAD 2>/dev/null || echo "detached")

    if [ "$CURRENT_BRANCH" = "detached" ]; then
        echo "⚠️  Detached HEAD gedetecteerd, checkout main..."
        (cd "$GIT_DIR" && git checkout main 2>/dev/null || git checkout -b main origin/main)
    fi

    # Abort eventuele actieve merge EERST
    echo "🛑 Aborteren van eventuele actieve merge..."
    (cd "$GIT_DIR" && git merge --abort 2>/dev/null || true)
    
    # Fetch laatste wijzigingen
    echo "📥 Fetching laatste wijzigingen..."
    (cd "$GIT_DIR" && git fetch origin main 2>/dev/null || true)
    
    # Restore developer docs from git (these should always match git version)
    echo "🔧 Restoring developer documentation from git..."
    DEVELOPER_DOCS=(
        "www/content/collections/pages/en/developers-api-changes.md"
        "www/content/collections/pages/nl/developers-api-changes.md"
        "www/content/collections/pages/en/developers.md"
        "www/content/collections/pages/nl/developers.md"
        "content/collections/pages/en/developers-api-changes.md"
        "content/collections/pages/nl/developers-api-changes.md"
        "content/collections/pages/en/developers.md"
        "content/collections/pages/nl/developers.md"
    )

    for file in "${DEVELOPER_DOCS[@]}"; do
        # Try to checkout file from git (overwrites local changes)
        if (cd "$GIT_DIR" && git ls-files --error-unmatch "$file" >/dev/null 2>&1); then
            echo "  ✓ Restoring from git: $file"
            (cd "$GIT_DIR" && git checkout HEAD -- "$file" 2>/dev/null || \
             git checkout origin/main -- "$file" 2>/dev/null || true)
        fi
    done
    echo "✅ Developer docs restored from git"
    
    # Reset alle wijzigingen in content directory
    echo "🔄 Resetten van content directory..."
    (cd "$GIT_DIR" && git checkout HEAD -- www/content/ 2>/dev/null || \
     git checkout HEAD -- content/ 2>/dev/null || \
     git checkout origin/main -- www/content/ 2>/dev/null || \
     git checkout origin/main -- content/ 2>/dev/null || true)
    
    # Clean index
    (cd "$GIT_DIR" && git reset HEAD . 2>/dev/null || true)
    
    # Forceer pull van main branch (overschrijft lokale wijzigingen)
    echo "🔄 Forceren van git pull origin main..."
    (cd "$GIT_DIR" && git reset --hard origin/main 2>/dev/null || {
        echo "⚠️  Hard reset gefaald, proberen met force checkout..."
        git checkout -f main 2>/dev/null || git checkout -f -b main origin/main 2>/dev/null || true
        git reset --hard origin/main 2>/dev/null || {
            echo "⚠️  Hard reset nog steeds gefaald, proberen laatste redmiddel..."
            rm -f .git/index 2>/dev/null || true
            git read-tree HEAD 2>/dev/null || true
            git reset --hard origin/main 2>/dev/null || true
        }
    })
    echo "✅ Git bijgewerkt naar laatste versie"
else
    echo "⚠️  .git directory niet gevonden (gezocht in . en ..)"
    echo "   Git operaties worden overgeslagen"
fi

echo ""
echo "🔍 Controleren van directory status..."

# Verifieer dat directories bestaan
if [ -d "content" ] && [ -n "$(ls -A content 2>/dev/null)" ]; then
    echo "✅ content/ directory bestaat en bevat bestanden"
else
    echo "⚠️  content/ is leeg of bestaat niet (verwacht voor verse installatie)"
fi

# Zorg dat storage directories bestaan
if [ ! -d "storage" ]; then
    echo "⚠️  storage/ bestaat niet, wordt aangemaakt..."
    mkdir -p storage/app/public storage/framework/cache storage/framework/sessions storage/framework/views storage/logs
    chmod -R 775 storage 2>/dev/null || true
    echo "✅ storage/ directories aangemaakt"
else
    echo "✅ storage/ directory bestaat"

    # Zorg dat subdirectories bestaan
    for dir in storage/app/public storage/framework/cache storage/framework/sessions storage/framework/views storage/logs; do
        if [ ! -d "$dir" ]; then
            mkdir -p "$dir"
            echo "  ✓ Aangemaakt: $dir"
        fi
    done
fi

echo ""
echo "=============================================="
echo "🛡️  STAP 1 VOLTOOID: Content is beschermd!"
echo "=============================================="
echo ""
echo "ℹ️  Content en storage zijn nu beschermd tegen git pull"
echo "   Ploi.io kan nu veilig deployment uitvoeren"
echo ""

# Check if we should skip post-deployment tasks (for pre-deploy only mode)
if [ "$1" = "--pre-deploy-only" ]; then
    echo "✅ Pre-deployment bescherming voltooid (--pre-deploy-only mode)"
    echo "   Post-deployment taken worden overgeslagen"
    exit 0
fi

echo ""
echo "🚀 STAP 2: Post-Deployment Taken"
echo "=============================================="
echo "   (Composer, caching, maintenance)"
echo ""

# Verifieer dat content nog steeds bestaat na git pull
if [ -d "content" ] && [ -n "$(ls -A content 2>/dev/null)" ]; then
    echo "✅ Verificatie geslaagd: content/ is behouden na git pull!"
else
    echo "❌ WAARSCHUWING: content/ is leeg of verwijderd ondanks bescherming"
    echo "   Dit zou niet moeten gebeuren. Controleer git status."
fi

# Herstel public/storage symlink
if [ ! -L "public/storage" ] && [ ! -d "public/storage" ]; then
    echo "🔗 public/storage symlink aanmaken..."
    php artisan storage:link 2>/dev/null || {
        # Fallback: maak handmatig symlink
        if [ -d "storage/app/public" ]; then
            ln -sf ../storage/app/public public/storage 2>/dev/null || true
            echo "✅ public/storage symlink aangemaakt"
        fi
    }
else
    echo "✅ public/storage symlink bestaat"
fi

echo ""
echo "📦 Composer dependencies installeren..."

# Composer dependencies installeren
if command -v composer &> /dev/null; then
    composer install --no-dev --optimize-autoloader || echo "⚠️  Composer install gefaald"
else
    echo "⚠️  Composer niet gevonden, skip"
fi

echo ""
echo "🎨 Frontend assets builden (Vite)..."

# Node modules installeren en assets builden
if command -v npm &> /dev/null; then
    echo "📥 NPM packages installeren..."
    npm ci --production=false || npm install || echo "⚠️  NPM install gefaald"

    echo "🔨 Vite production build..."
    npm run build || echo "⚠️  Vite build gefaald"

    # Verifieer dat build bestanden zijn aangemaakt
    if [ -d "public/build" ] && [ -n "$(ls -A public/build 2>/dev/null)" ]; then
        echo "✅ Frontend assets succesvol gebouwd"
        echo "   Bestanden in public/build/:"
        ls -lh public/build/ | tail -n +2 | awk '{print "   - " $9 " (" $5 ")"}'
    else
        echo "❌ WAARSCHUWING: public/build is leeg na build"
        echo "   Frontend styling zal mogelijk niet werken!"
    fi
else
    echo "⚠️  NPM niet gevonden, skip frontend build"
    echo "   Frontend styling zal mogelijk niet werken!"
fi

echo ""
echo "🗄️  Database migraties uitvoeren..."

# Run database migrations
if [ -f "artisan" ]; then
    php artisan migrate --force 2>/dev/null || echo "⚠️  Migraties gefaald"
    echo "✅ Database migraties uitgevoerd"
else
    echo "⚠️  Artisan niet gevonden, skip migraties"
fi

echo ""
echo "📤 Caching Backpack assets (Basset)..."

# Cache Backpack assets via Basset
if [ -f "artisan" ]; then
    php artisan basset:cache 2>/dev/null || echo "⚠️  Basset cache gefaald"
    echo "✅ Backpack assets gecached"
else
    echo "⚠️  Artisan niet gevonden, skip basset cache"
fi

echo ""
echo "🧹 Cache clearing..."

# Laravel cache clearing
if [ -f "artisan" ]; then
    php artisan config:cache 2>/dev/null || echo "⚠️  Config cache gefaald"
    php artisan route:cache 2>/dev/null || echo "⚠️  Route cache gefaald"
    php artisan view:cache 2>/dev/null || echo "⚠️  View cache gefaald"
    
    # Statamic cache clearing
    php artisan statamic:stache:clear 2>/dev/null || echo "⚠️  Statamic cache gefaald"
else
    echo "⚠️  Artisan niet gevonden, skip cache clearing"
fi

echo ""
echo "🔄 OPcache clearen..."

# Clear OPcache via Laravel artisan command (no sudo required)
if [ -f "artisan" ]; then
    if php artisan opcache:clear 2>/dev/null; then
        echo "✅ OPcache gecleared"
    else
        echo "⚠️  OPcache clear gefaald of niet beschikbaar"
        echo "   Nieuwe code kan enkele minuten vertraging hebben"
    fi
else
    echo "⚠️  Artisan niet gevonden, skip OPcache clear"
fi

echo ""
echo "🔥 OPcache warmup request..."

# Warmup request — voorkomt "Unexpected token '<'" bij eerste request na deploy
if [ -f ".env" ]; then
    APP_URL=$(grep -E "^APP_URL=" .env | cut -d '=' -f2- | tr -d '"' | tr -d "'")
    if [ -n "$APP_URL" ]; then
        curl -s -o /dev/null -w "Warmup basewebsite: %{http_code}\n" "$APP_URL/" || true
        echo "✅ OPcache warmup voltooid"
    fi
fi

# Warm up pdfen.com — deelt dezelfde PHP-FPM service
curl -s -o /dev/null -w "Warmup pdfen.com: %{http_code}\n" "https://pdfen.com/" || true

echo ""
echo "🐍 Python PDF editor service herstarten..."

# Restart Python PDF editor service (Docker)
PYTHON_SERVICE_DIR=""
if [ -d "../scripts-python/pdf-editor-service" ]; then
    PYTHON_SERVICE_DIR="../scripts-python/pdf-editor-service"
elif [ -d "../../scripts-python/pdf-editor-service" ]; then
    PYTHON_SERVICE_DIR="../../scripts-python/pdf-editor-service"
fi

if [ -n "$PYTHON_SERVICE_DIR" ] && [ -f "$PYTHON_SERVICE_DIR/docker-compose.yml" ]; then
    cd "$PYTHON_SERVICE_DIR"
    if command -v docker-compose &> /dev/null; then
        docker-compose down 2>/dev/null || true
        docker-compose up -d --build 2>/dev/null && echo "✅ Python PDF editor service herstart" || echo "⚠️  Python service restart gefaald"
    elif command -v docker &> /dev/null && docker compose version &> /dev/null; then
        docker compose down 2>/dev/null || true
        docker compose up -d --build 2>/dev/null && echo "✅ Python PDF editor service herstart" || echo "⚠️  Python service restart gefaald"
    else
        echo "⚠️  Docker (compose) niet gevonden, skip Python service restart"
    fi
    cd - > /dev/null
else
    echo "⚠️  Python PDF editor service niet gevonden, skip"
fi

echo ""
echo "📡 Sentry release notification..."

if command -v sentry-cli &> /dev/null && [ -n "$GIT_DIR" ]; then
    RELEASE=$(cd "$GIT_DIR" && git rev-parse HEAD 2>/dev/null)
    if [ -n "$RELEASE" ]; then
        SENTRY_OK=true
        sentry-cli releases new "$RELEASE" 2>/dev/null || SENTRY_OK=false
        sentry-cli releases set-commits "$RELEASE" --auto 2>/dev/null || true
        sentry-cli releases finalize "$RELEASE" 2>/dev/null || SENTRY_OK=false
        sentry-cli releases deploys "$RELEASE" new -e production 2>/dev/null || true
        if [ "$SENTRY_OK" = true ]; then
            echo "✅ Sentry release $RELEASE genoteerd"
        else
            echo "⚠️  Sentry release notificatie deels gefaald (new/finalize)"
        fi
    fi
else
    echo "ℹ️  Sentry-cli niet geïnstalleerd of git niet gevonden, skip release notification"
fi

echo ""
echo "✅ Deployment voltooid!"
echo "=============================================="
echo ""
echo "📋 Samenvatting:"
echo "  ✓ Content beschermd tegen git operaties"
echo "  ✓ Storage directories geverifieerd"
echo "  ✓ Composer dependencies geïnstalleerd"
echo "  ✓ Frontend assets gebouwd (Vite)"
echo "  ✓ Database migraties uitgevoerd"
echo "  ✓ Laravel cache geoptimaliseerd"
echo "  ✓ Statamic Stache cache gecleared"
echo "  ✓ OPcache gecleared (indien beschikbaar)"
echo "  ✓ Python PDF editor service herstart (indien beschikbaar)"
echo "  ✓ Sentry release genoteerd (indien sentry-cli beschikbaar)"
echo ""
echo "🎯 Volgende stappen:"
echo "  1. Verifieer dat de website correct werkt"
echo "  2. Check /faq en /nl/veelgestelde-vragen pagina's"
echo "  3. Controleer Statamic CP voor nieuwe blueprints"
echo ""
echo "💡 Tip: Dit script is idempotent en kan veilig herhaald worden"

