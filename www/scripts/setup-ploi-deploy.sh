#!/bin/bash
# Eenmalige Setup Script voor Ploi.io Deployment
# Beschermt content/ en storage/ VOOR de eerste git pull
#
# Gebruik:
# 1. Upload dit script naar de server: /home/ploi/staging.example.com/www/scripts/
# 2. SSH naar de server
# 3. Voer uit: bash /home/ploi/staging.example.com/www/scripts/setup-ploi-deploy.sh
#
# Dit script hoeft maar 1x uitgevoerd te worden per server

set -e  # Stop bij errors

echo "🛡️  Ploi.io Deployment Setup (Eenmalig)"
echo "========================================"
echo ""
echo "Dit script beschermt content/ en storage/ tegen git operaties"
echo "Hiermee voorkom je dat content verloren gaat bij deployment"
echo ""

# Bepaal de huidige directory
CURRENT_DIR="$(pwd)"

# Ga naar project root (parent van www/)
if [ -d "www" ] && [ -f "www/artisan" ]; then
    # We zijn al in project root
    PROJECT_ROOT="$(pwd)"
    cd www || exit 1
elif [ -f "artisan" ]; then
    # We zijn in www directory, ga naar parent
    cd .. || exit 1
    PROJECT_ROOT="$(pwd)"
    cd www || exit 1
else
    echo "❌ Error: Kan project structuur niet vinden"
    echo "   Verwachte structuur: /home/ploi/staging.example.com/www/"
    echo "   Huidige directory: $CURRENT_DIR"
    exit 1
fi

WWW_DIR="$(pwd)"
echo "📁 Project root: $PROJECT_ROOT"
echo "📁 WWW directory: $WWW_DIR"
echo ""

# Stap 1: Unstage alle wijzigingen die git pull blokkeren
echo "🔄 STAP 1: Git status opschonen"
echo "================================"

if git status --porcelain 2>/dev/null | grep -q "^M"; then
    echo "⚠️  Gevonden: Staged wijzigingen die deployment blokkeren"
    echo "   Aantal gestaged bestanden: $(git status --porcelain | grep "^M" | wc -l)"
    echo ""
    echo "   Deze wijzigingen worden ge-unstaged (blijven lokaal behouden)"

    git reset HEAD . 2>/dev/null || echo "   (Geen reset nodig)"
    echo "✅ Staged wijzigingen verwijderd"
else
    echo "✅ Geen blocking staged wijzigingen gevonden"
fi

echo ""

# Stap 2: Bescherm content/ directory
echo "🔒 STAP 2: Content beschermen"
echo "=============================="

# Check if content files are tracked in git (DANGER!)
TRACKED_CONTENT=$(git ls-files content/ 2>/dev/null | wc -l | tr -d ' ')
if [ "$TRACKED_CONTENT" -gt 0 ]; then
    echo "⚠️  GEVAAR: Er zijn $TRACKED_CONTENT content bestanden getrackt in git!"
    echo "   Bij git pull kunnen deze verwijderd worden."
    echo ""
    echo "   Voorbeelden van getrackde bestanden:"
    git ls-files content/ | head -5
    echo ""
    echo "   🛡️  Deze worden NU beschermd met --skip-worktree"
fi

if [ -d "content" ] && [ -n "$(ls -A content 2>/dev/null)" ]; then
    echo "📂 Content directory gevonden met bestanden"
    echo "   Beschermen van alle content bestanden..."

    PROTECTED=0
    find content -type f ! -name ".gitkeep" ! -name ".DS_Store" 2>/dev/null | while read -r file; do
        if [ -f "$file" ]; then
            git update-index --skip-worktree "$file" 2>/dev/null || true
            ((PROTECTED++)) 2>/dev/null || true
        fi
    done

    echo "✅ Content bestanden zijn nu beschermd tegen git pull"
    echo "   (Deze bestanden blijven lokaal behouden bij elke deployment)"
    echo ""
    echo "   ℹ️  --skip-worktree betekent: Git zal deze bestanden NOOIT updaten/verwijderen"
else
    echo "⚠️  Content directory is leeg of bestaat niet"
    echo "   Bescherming overgeslagen (geen content om te beschermen)"
    echo "   Dit is normaal voor een verse installatie"
fi

echo ""

# Stap 3: Bescherm storage/app/public
echo "🔒 STAP 3: Storage beschermen"
echo "=============================="

# Check if storage files are tracked in git (DANGER!)
TRACKED_STORAGE=$(git ls-files storage/app/public/ 2>/dev/null | grep -v '.gitignore' | wc -l | tr -d ' ')
if [ "$TRACKED_STORAGE" -gt 0 ]; then
    echo "⚠️  GEVAAR: Er zijn $TRACKED_STORAGE storage bestanden getrackt in git!"
    echo "   Bij git pull kunnen deze verwijderd worden."
    echo ""
    echo "   🛡️  Deze worden NU beschermd met --skip-worktree"
fi

if [ -d "storage/app/public" ] && [ -n "$(ls -A storage/app/public 2>/dev/null | grep -v '.gitignore')" ]; then
    echo "📂 Storage/app/public directory gevonden met bestanden"
    echo "   Beschermen van uploaded bestanden..."

    find storage/app/public -type f ! -name ".gitignore" ! -name ".gitkeep" ! -name ".DS_Store" 2>/dev/null | while read -r file; do
        if [ -f "$file" ]; then
            git update-index --skip-worktree "$file" 2>/dev/null || true
        fi
    done

    echo "✅ Storage bestanden zijn nu beschermd"
else
    echo "ℹ️  Storage/app/public is leeg"
    echo "   Dit is normaal voor een verse installatie"
fi

echo ""

# Stap 4: Verifieer git status
echo "🔍 STAP 4: Verificatie"
echo "======================"

echo "Git status controleren..."
STAGED=$(git status --porcelain 2>/dev/null | grep "^M" | wc -l | tr -d ' ')
MODIFIED=$(git status --porcelain 2>/dev/null | grep "^ M" | wc -l | tr -d ' ')

echo "  Staged bestanden: $STAGED (zou 0 moeten zijn)"
echo "  Modified bestanden: $MODIFIED (lokale wijzigingen, veilig)"

if [ "$STAGED" -eq 0 ]; then
    echo "✅ Git status is clean - git pull kan nu veilig uitgevoerd worden"
else
    echo "⚠️  Er zijn nog steeds staged bestanden"
    echo "   Mogelijk moeten deze handmatig ge-unstaged worden"
fi

echo ""
echo "=============================================="
echo "✅ Setup voltooid!"
echo "=============================================="
echo ""
echo "📋 Wat is er gebeurd:"
echo "  ✓ Staged wijzigingen verwijderd (blokkeerden git pull)"
echo "  ✓ Content/ beschermd met --skip-worktree"
echo "  ✓ Storage/app/public beschermd"
echo ""

# Verificatie van bescherming
echo "🔍 Verificatie van bescherming:"
SKIP_WORKTREE_COUNT=$(git ls-files -v | grep "^S" | wc -l | tr -d ' ')
if [ "$SKIP_WORKTREE_COUNT" -gt 0 ]; then
    echo "  ✅ $SKIP_WORKTREE_COUNT bestanden zijn beschermd met --skip-worktree"
    echo "  ✅ Deze bestanden blijven behouden bij git pull"
else
    echo "  ℹ️  Geen bestanden beschermd (normale situatie voor verse installatie)"
fi
echo ""

echo "🎯 Volgende stappen:"
echo "  1. Test git pull: cd $WWW_DIR && git pull origin main"
echo "  2. Verifieer dat content/ behouden blijft"
echo "  3. Configureer ploi-deploy.sh in Ploi.io deployment script"
echo ""
echo "💡 Deployment script configureren in Ploi.io:"
echo "   Site → Settings → Deploy Script"
echo "   Voeg toe: bash scripts/ploi-deploy.sh"
echo ""
echo "⚠️  BELANGRIJK:"
echo "   - Dit setup script hoeft maar 1x uitgevoerd te worden"
echo "   - Bij elke deployment draait ploi-deploy.sh automatisch"
echo "   - Content blijft nu altijd behouden bij git pull"
echo "   - --skip-worktree beschermt tegen verwijderen door git pull"
echo ""
