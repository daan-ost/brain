#!/bin/bash
# FORCE Git Reset - Laatste redmiddel
# Dit script forceert een volledige reset door de index volledig te verwijderen
#
# Uitvoeren vanuit www/ of project root:
# cd ~/staging.example.com/www
# bash scripts/force-git-reset.sh

set -e

echo "💥 FORCE Git Reset"
echo "=================="
echo ""
echo "⚠️  Dit script verwijdert de git index volledig en forceert een reset"
echo ""

# Bepaal git root
if [ -d "../.git" ]; then
    GIT_ROOT=".."
elif [ -d ".git" ]; then
    GIT_ROOT="."
else
    echo "❌ Error: .git directory niet gevonden"
    exit 1
fi

cd "$GIT_ROOT" || exit 1
echo "📁 Working in: $(pwd)"
echo ""

# Abort alles
echo "🛑 Aborteren van alle operaties..."
git merge --abort 2>/dev/null || true
git rebase --abort 2>/dev/null || true
git cherry-pick --abort 2>/dev/null || true
echo ""

# Fetch
echo "📥 Fetching..."
git fetch origin main 2>/dev/null || git fetch origin 2>/dev/null || true
echo ""

# Checkout main
echo "🌿 Checkout main branch..."
git checkout -f main 2>/dev/null || git checkout -f -b main origin/main 2>/dev/null || true
echo ""

# VERWIJDER INDEX VOLLEDIG
echo "🗑️  VERWIJDEREN van git index (dit forceert een volledige rebuild)..."
rm -f .git/index 2>/dev/null || true
rm -f .git/index.lock 2>/dev/null || true
rm -rf .git/index.lock 2>/dev/null || true
echo "✅ Index verwijderd"
echo ""

# Verwijder problematisch bestand
echo "🗑️  Verwijderen van problematisch bestand..."
rm -f www/content/collections/pages/en/developers-api-changes.md 2>/dev/null || true
rm -f content/collections/pages/en/developers-api-changes.md 2>/dev/null || true
echo ""

# Rebuild index van HEAD
echo "🔨 Rebuilden van index van HEAD..."
git read-tree HEAD 2>/dev/null || {
    echo "⚠️  read-tree HEAD gefaald, proberen met origin/main..."
    git read-tree origin/main 2>/dev/null || true
}
echo ""

# Nu hard reset
echo "🔄 Hard reset naar origin/main..."
git reset --hard origin/main 2>/dev/null || {
    echo "⚠️  Reset gefaald, proberen met checkout..."
    
    # Checkout alle bestanden van origin/main
    git checkout origin/main -- . 2>/dev/null || true
    
    # Update HEAD
    git update-ref HEAD origin/main 2>/dev/null || true
    
    # Reset working directory
    git reset --hard HEAD 2>/dev/null || true
}

echo ""
echo "✅ Status:"
git status --short 2>/dev/null || true
echo ""

# Verifieer dat we op de juiste commit zijn
CURRENT_COMMIT=$(git rev-parse HEAD 2>/dev/null || echo "unknown")
ORIGIN_COMMIT=$(git rev-parse origin/main 2>/dev/null || echo "unknown")

echo "📊 Commit verificatie:"
echo "   Local:  $CURRENT_COMMIT"
echo "   Remote: $ORIGIN_COMMIT"

if [ "$CURRENT_COMMIT" = "$ORIGIN_COMMIT" ]; then
    echo "✅ Repository is gesynchroniseerd met origin/main"
else
    echo "⚠️  Commits verschillen nog, maar reset is uitgevoerd"
fi

echo ""
echo "✅ Force reset voltooid!"
echo ""
echo "💡 Probeer nu opnieuw te deployen via Ploi"

