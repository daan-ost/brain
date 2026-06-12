# Maintenance Scripts

This directory contains maintenance and debug scripts that were moved from `public/` for security reasons.

## Security Notice

**These scripts should NEVER be publicly accessible via the web.**

They have been moved here to prevent:
- Unauthorized cache clearing (DoS attacks)
- Information disclosure
- Database manipulation
- System resource abuse

## Available Scripts

### Cache Management

- **`clear-all-cache.php`** - Clears all application caches
- **`nuclear-cache-clear.php`** - Aggressive cache clearing (use with caution)

### Database Operations

- **`migrate-to-eloquent.php`** - Database migration helper
- **`input_user.php`** - POC5b baseline data seeding script

### Debugging & Diagnostics

- **`debug-paths.php`** - Displays application path information
- **`path-finder.php`** - Advanced path debugging utility

### Performance

- **`optimize-performance.php`** - Performance optimization tasks

### File System

- **`fix-absolute-paths.php`** - Fixes absolute path issues

## Usage

All scripts must be run directly via PHP CLI with SSH/server access:

```bash
# Navigate to project root
cd /path/to/project/www

# Run a script
php scripts/maintenance/clear-all-cache.php

# Or with arguments (if supported)
php scripts/maintenance/input_user.php
```

## Access Requirements

- SSH access to the server
- Appropriate file permissions
- Knowledge of script functionality and risks

## Migration from public/

**Date:** October 12, 2025
**Reason:** Security audit (docs/todosecurity.md #13)

These scripts were previously accessible at URLs like:
- `https://yoursite.com/clear-all-cache.php` ❌ (insecure)

Now only accessible via:
- `php scripts/maintenance/clear-all-cache.php` ✅ (secure)

## Best Practices

1. **Never** commit these scripts to public repositories without review
2. **Always** test in staging before running in production
3. **Document** what each script does before running it
4. **Backup** data before running database scripts
5. **Monitor** logs after running maintenance tasks

## Future Improvements

Consider converting these scripts to Artisan commands for:
- Better error handling
- Integration with Laravel's queue system
- Role-based access control
- Proper logging and audit trails

Example:
```bash
# Instead of: php scripts/maintenance/clear-all-cache.php
# Use: php artisan cache:nuclear-clear
```

---

**Last Updated:** October 12, 2025
**Security Audit:** docs/todosecurity.md
