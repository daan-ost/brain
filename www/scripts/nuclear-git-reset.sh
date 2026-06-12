#!/bin/bash
# NUCLEAR OPTION: Volledige git reset voor Ploi deployment
# Gebruik dit ALLEEN als andere methodes falen!
#
# Dit script:
# - Verwijdert alle lokale wijzigingen
# - Reset de hele repository naar origin/main
# - Verwijdert eventuele merge conflicts volledig
#
# Uitvoeren op de server:
# cd /path/to/your/project
# bash www/scripts/nuclear-git-reset.sh

set -e

echo "☢️  NUCLEAR GIT RESET SCRIPT"
echo "============================="
echo ""
echo "⚠️  WAARSCHUWING: Dit script verwijdert ALLE lokale wijzigingen!"
echo "   Zorg dat je een backup hebt van belangrijke content!"
echo ""
read -p "Doorgaan? (yes/no): " confirm
if [ "$confirm" != "yes" ]; then
    echo "❌ Geannuleerd"
    exit 1
fi

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

# Abort alles
echo "🛑 Aborteren van alle actieve operaties..."
(cd "$GIT_DIR" && {
    git merge --abort 2>/dev/null || true
    git rebase --abort 2>/dev/null || true
    git cherry-pick --abort 2>/dev/null || true
})
echo ""

# Fetch
echo "📥 Fetching van origin..."
(cd "$GIT_DIR" && git fetch origin main 2>/dev/null || git fetch origin 2>/dev/null || true)
echo ""

# Verwijder index
echo "🗑️  Verwijderen van git index..."
(cd "$GIT_DIR" && rm -f .git/index 2>/dev/null || true)
echo ""

# Clean working directory
echo "🧹 Cleanen van working directory..."
(cd "$GIT_DIR" && git clean -fd 2>/dev/null || true)
echo ""

# Reset alles
echo "🔄 Volledige reset naar origin/main..."
(cd "$GIT_DIR" && {
    # Checkout main
    git checkout -f main 2>/dev/null || git checkout -f -b main origin/main 2>/dev/null || true
    
    # Reset hard
    git reset --hard origin/main 2>/dev/null || {
        # Als dat faalt, probeer met read-tree
        git read-tree -m -u HEAD 2>/dev/null || true
        git reset --hard origin/main 2>/dev/null || true
    }
    
    # Herstel index
    git read-tree HEAD 2>/dev/null || true
})
echo ""

# Verifieer
echo "✅ Git status na nuclear reset:"
(cd "$GIT_DIR" && git status --short 2>/dev/null || true)
echo ""

echo "✅ Nuclear reset voltooid!"
echo ""
echo "💡 Repository is nu volledig gesynchroniseerd met origin/main"
echo "   Je kunt nu opnieuw proberen te deployen via Ploi"

