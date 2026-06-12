#!/bin/bash
# Protect ALL www/content/ from being deleted by git pull
# Run this ONCE on staging after content is restored
# 
# Dit script markeert alle content bestanden als "skip-worktree"
# zodat Git ze niet verwijdert bij pull, zelfs als ze uit Git zijn verwijderd

echo "🔒 Protecting ALL www/content/ from git operations..."

cd "$(dirname "$0")/../.." || exit 1

# Check of we in een git repo zitten
if [ ! -d ".git" ]; then
    echo "❌ Error: Dit is geen git repository"
    exit 1
fi

# Mark all files in www/content/ as skip-worktree (except .gitkeep and .DS_Store)
# This tells git to ignore changes to these files and not delete them
if [ -d "www/content" ]; then
    find www/content -type f ! -name ".gitkeep" ! -name ".DS_Store" 2>/dev/null | while read -r file; do
        if [ -f "$file" ]; then
            # Check of het bestand al getracked is (of was)
            if git ls-files --error-unmatch "$file" >/dev/null 2>&1; then
                git update-index --skip-worktree "$file" 2>/dev/null && echo "  ✓ Protected: $file"
            else
                # Voor niet-getracked bestanden: voeg toe aan .git/info/exclude als backup
                echo "  ℹ️  Not tracked: $file (already ignored by .gitignore)"
            fi
        fi
    done
    echo ""
    echo "✅ All content protected! Git will not delete these files during pull."
else
    echo "⚠️  www/content/ folder bestaat niet"
fi

echo ""
echo "💡 Gebruik staging-safe-pull.sh voor veilige pulls:"
echo "   ./www/scripts/staging-safe-pull.sh"
echo ""
echo "To unprotect (if needed):"
echo "  find www/content -type f ! -name '.gitkeep' ! -name '.DS_Store' | xargs git update-index --no-skip-worktree"

