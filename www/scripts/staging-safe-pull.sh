#!/bin/bash
# Safe git pull voor staging - beschermt content/ folder
# Gebruik dit script in plaats van gewone 'git pull' op staging

set -e  # Stop bij errors

echo "🛡️  Staging Safe Pull - Beschermt content/ folder"
echo ""

# Ga naar project root
cd "$(dirname "$0")/../.." || exit 1

# Check of we in een git repo zitten
if [ ! -d ".git" ]; then
    echo "❌ Error: Dit is geen git repository"
    exit 1
fi

# Backup content folder VOOR pull
CONTENT_BACKUP_DIR="backup/content-before-pull-$(date +%Y%m%d-%H%M%S)"
if [ -d "www/content" ] && [ "$(ls -A www/content 2>/dev/null)" ]; then
    echo "📦 Backup maken van www/content/..."
    mkdir -p "$CONTENT_BACKUP_DIR"
    cp -r www/content "$CONTENT_BACKUP_DIR/" 2>/dev/null || true
    echo "✅ Backup gemaakt in: $CONTENT_BACKUP_DIR"
else
    echo "⚠️  www/content/ bestaat niet of is leeg, geen backup nodig"
fi

echo ""
echo "🔄 Git pull uitvoeren..."

# Voer git pull uit
if git pull origin main; then
    echo "✅ Git pull succesvol"
else
    echo "❌ Git pull gefaald!"
    exit 1
fi

echo ""
echo "🔍 Controleren of content/ folder nog bestaat..."

# Als content/ verwijderd is, restore van backup
if [ ! -d "www/content" ] || [ -z "$(ls -A www/content 2>/dev/null)" ]; then
    echo "⚠️  www/content/ is verwijderd of leeg na pull!"
    
    if [ -d "$CONTENT_BACKUP_DIR/content" ]; then
        echo "🔄 Herstellen van backup..."
        mkdir -p www
        cp -r "$CONTENT_BACKUP_DIR/content" www/content
        echo "✅ Content hersteld van backup"
    else
        echo "❌ Geen backup beschikbaar om te herstellen!"
        exit 1
    fi
else
    echo "✅ www/content/ bestaat nog, alles OK"
    # Verwijder backup als alles goed is
    if [ -d "$CONTENT_BACKUP_DIR" ]; then
        echo "🗑️  Backup verwijderen (niet meer nodig)..."
        rm -rf "$CONTENT_BACKUP_DIR"
    fi
fi

echo ""
echo "✅ Safe pull voltooid!"
echo ""
echo "💡 Tip: Gebruik dit script altijd op staging:"
echo "   ./www/scripts/staging-safe-pull.sh"

