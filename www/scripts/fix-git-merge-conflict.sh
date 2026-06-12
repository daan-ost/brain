#!/bin/bash
# Quick fix script voor Git merge conflicts tijdens Ploi deployment
# Gebruik dit script op de server om merge conflicts op te lossen
#
# Uitvoeren op de server:
# cd /path/to/your/project
# bash www/scripts/fix-git-merge-conflict.sh

set -e

echo "🔧 Git Merge Conflict Fix Script"
echo "=================================="
echo ""

# Bepaal de git directory
if [ -d ".git" ]; then
    GIT_DIR="."
elif [ -d "www/.git" ]; then
    GIT_DIR="www"
elif [ -d "../.git" ]; then
    GIT_DIR=".."
else
    echo "❌ Error: .git directory niet gevonden"
    exit 1
fi

echo "📁 Git directory: $GIT_DIR"
echo ""

# Check huidige branch
CURRENT_BRANCH=$(cd "$GIT_DIR" && git symbolic-ref --short HEAD 2>/dev/null || echo "detached")
echo "🌿 Huidige branch: $CURRENT_BRANCH"
echo ""

# Fetch laatste wijzigingen
echo "📥 Fetching laatste wijzigingen van origin..."
(cd "$GIT_DIR" && git fetch origin main 2>/dev/null || git fetch origin 2>/dev/null || true)
echo ""

# Abort eventuele actieve merge EERST
echo "🛑 Aborteren van eventuele actieve merge..."
(cd "$GIT_DIR" && git merge --abort 2>/dev/null || true)
echo ""

# Reset specifieke problematische bestanden - AGRESSIEVE AANPAK
echo "🔧 Force resetten van problematische bestanden..."
PROBLEMATIC_FILES=(
    "www/content/collections/pages/en/developers-api-changes.md"
    "www/content/collections/pages/nl/developers-api-changes.md"
    "content/collections/pages/en/developers-api-changes.md"
    "content/collections/pages/nl/developers-api-changes.md"
)

for file in "${PROBLEMATIC_FILES[@]}"; do
    # Verwijder uit index (zonder te verwijderen van disk)
    echo "  🗑️  Verwijderen van $file uit git index..."
    (cd "$GIT_DIR" && git rm --cached "$file" 2>/dev/null || \
     git rm --cached "www/$file" 2>/dev/null || \
     git rm --cached "${file#www/}" 2>/dev/null || true)
    
    # Verwijder uit working directory
    if [ -f "$GIT_DIR/$file" ]; then
        echo "  🗑️  Verwijderen van $file van disk..."
        rm -f "$GIT_DIR/$file" 2>/dev/null || true
    fi
    if [ -f "$file" ]; then
        echo "  🗑️  Verwijderen van $file van disk (relatief pad)..."
        rm -f "$file" 2>/dev/null || true
    fi
done
echo ""

# Reset ALLE wijzigingen in content directory
echo "🧹 Resetten van hele content directory..."
(cd "$GIT_DIR" && git checkout HEAD -- www/content/ 2>/dev/null || \
 git checkout HEAD -- content/ 2>/dev/null || \
 git checkout origin/main -- www/content/ 2>/dev/null || \
 git checkout origin/main -- content/ 2>/dev/null || true)
echo ""

# Clean index volledig
echo "🧹 Volledig cleanen van git index..."
(cd "$GIT_DIR" && git reset HEAD . 2>/dev/null || true)
(cd "$GIT_DIR" && git reset --mixed HEAD 2>/dev/null || true)
echo ""

# Hard reset naar origin/main - MET FORCE
echo "🔄 Force hard reset naar origin/main..."
(cd "$GIT_DIR" && {
    # Probeer eerst normale reset
    git reset --hard origin/main 2>/dev/null || {
        echo "⚠️  Normale reset gefaald, proberen met force..."
        # Force checkout van main branch
        git checkout -f main 2>/dev/null || git checkout -f -b main origin/main 2>/dev/null || true
        # Force reset
        git reset --hard origin/main 2>/dev/null || {
            echo "⚠️  Hard reset nog steeds gefaald, proberen laatste redmiddel..."
            # Laatste redmiddel: verwijder .git/index en reset
            rm -f .git/index 2>/dev/null || true
            git read-tree HEAD 2>/dev/null || true
            git reset --hard origin/main 2>/dev/null || true
        }
    }
})
echo ""

# Verifieer status
echo "✅ Git status na fix:"
(cd "$GIT_DIR" && git status --short 2>/dev/null || true)
echo ""

echo "✅ Fix voltooid!"
echo ""
echo "💡 Je kunt nu opnieuw proberen te deployen via Ploi"
echo "   Of handmatig: git pull origin main"

