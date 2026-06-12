#!/bin/bash
# Server-side fix voor git merge conflict
# Gebruik dit vanuit www/ directory of project root
#
# Uitvoeren:
# cd ~/staging.example.com/www
# bash scripts/fix-git-conflict-server.sh

set -e

echo "🔧 Git Conflict Fix - Server Edition"
echo "======================================"
echo ""

# Bepaal git root directory
if [ -d "../.git" ]; then
    GIT_ROOT=".."
    echo "📁 Git root gevonden: $(cd .. && pwd)"
elif [ -d ".git" ]; then
    GIT_ROOT="."
    echo "📁 Git root gevonden: $(pwd)"
else
    echo "❌ Error: .git directory niet gevonden"
    exit 1
fi

cd "$GIT_ROOT" || exit 1

echo "📁 Working in: $(pwd)"
echo ""

# Abort alles
echo "🛑 Aborteren van alle actieve operaties..."
git merge --abort 2>/dev/null || true
git rebase --abort 2>/dev/null || true
echo ""

# Fetch
echo "📥 Fetching van origin..."
git fetch origin main 2>/dev/null || git fetch origin 2>/dev/null || true
echo ""

# Het problematische bestand - probeer alle mogelijke paden
PROBLEM_FILE="www/content/collections/pages/en/developers-api-changes.md"

echo "🔧 Fixen van problematisch bestand: $PROBLEM_FILE"
echo ""

# Methode 1: Verwijder uit index met --ignore-unmatch
echo "  Methode 1: Verwijderen uit index..."
git rm --cached --ignore-unmatch "$PROBLEM_FILE" 2>/dev/null || true
git rm --cached --ignore-unmatch "content/collections/pages/en/developers-api-changes.md" 2>/dev/null || true
echo ""

# Methode 2: Verwijder index volledig
echo "  Methode 2: Verwijderen van git index..."
rm -f .git/index 2>/dev/null || true
rm -f .git/index.lock 2>/dev/null || true
echo ""

# Methode 3: Verwijder bestand van disk
echo "  Methode 3: Verwijderen van disk..."
rm -f "$PROBLEM_FILE" 2>/dev/null || true
rm -f "content/collections/pages/en/developers-api-changes.md" 2>/dev/null || true
echo ""

# Methode 4: Reset index met read-tree
echo "  Methode 4: Herstellen van index..."
git read-tree HEAD 2>/dev/null || true
echo ""

# Methode 5: Checkout bestand van origin
echo "  Methode 5: Checkout van origin/main..."
git checkout origin/main -- "$PROBLEM_FILE" 2>/dev/null || \
git checkout origin/main -- "content/collections/pages/en/developers-api-changes.md" 2>/dev/null || true
echo ""

# Methode 6: Reset hele content directory
echo "  Methode 6: Resetten van content directory..."
git checkout HEAD -- www/content/ 2>/dev/null || \
git checkout HEAD -- content/ 2>/dev/null || \
git checkout origin/main -- www/content/ 2>/dev/null || \
git checkout origin/main -- content/ 2>/dev/null || true
echo ""

# Nu proberen hard reset
echo "🔄 Hard reset naar origin/main..."
git reset --hard origin/main 2>/dev/null || {
    echo "⚠️  Hard reset gefaald, proberen alternatieve methode..."
    
    # Alternatief: checkout en dan reset
    git checkout -f main 2>/dev/null || git checkout -f -b main origin/main 2>/dev/null || true
    
    # Verwijder index opnieuw
    rm -f .git/index .git/index.lock 2>/dev/null || true
    
    # Probeer opnieuw
    git reset --hard origin/main 2>/dev/null || {
        echo "⚠️  Nog steeds gefaald, laatste redmiddel..."
        
        # Laatste redmiddel: verwijder .git/index en rebuild
        rm -rf .git/index .git/index.lock 2>/dev/null || true
        git read-tree -m -u HEAD 2>/dev/null || true
        git reset --hard origin/main 2>/dev/null || {
            echo "❌ Alle methodes gefaald. Probeer nuclear-git-reset.sh"
            exit 1
        }
    }
}

echo ""
echo "✅ Git status na fix:"
git status --short 2>/dev/null || true
echo ""

echo "✅ Fix voltooid!"
echo ""
echo "💡 Je kunt nu opnieuw proberen te deployen via Ploi"

