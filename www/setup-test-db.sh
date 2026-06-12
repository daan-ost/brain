#!/bin/bash

# Test Database Setup Script
# Run this after: git pull, new migrations, or when tests fail with database errors

set -e  # Exit on error

echo "🔧 Setting up test database..."

# Step 1: Create test database if not exists
echo "→ Creating basewebsite_test database..."
php -r "
try {
    \$pdo = new PDO('mysql:host=127.0.0.1;port=8889', 'root', 'root');
    \$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    \$pdo->exec('CREATE DATABASE IF NOT EXISTS basewebsite_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    echo \"  ✓ Database created/verified\n\";
} catch (PDOException \$e) {
    echo \"  ✗ Error: \" . \$e->getMessage() . \"\n\";
    exit(1);
}
"

# Step 2: Run migrations on test database
echo "→ Running migrations on test database..."
php artisan migrate:fresh --env=testing --database=mysql --force

echo ""
echo "✅ Test database ready!"
echo ""
echo "You can now run: php artisan test"
