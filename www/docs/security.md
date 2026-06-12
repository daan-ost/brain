# Security

## Overview

Security measures implemented in the application.

## Rate Limiting

### Registration
- **Limit**: 5 attempts per minute
- **Route**: `POST /register`
- **Middleware**: `throttle:5,1`

### Password Reset
- **Limit**: 3 attempts per minute
- **Route**: `POST /forgot-password`
- **Middleware**: `throttle:3,1`

## Webhook Security

### Mollie Webhooks

Protected by IP whitelist:

```env
MOLLIE_WEBHOOK_IPS=87.233.217.240/29,87.233.217.248/30
```

Default Mollie IP ranges are used if not configured.

**Implementation**: `app/Http/Middleware/VerifyMollieWebhook.php`

## Environment Configuration

### Production Settings

```env
APP_DEBUG=false
APP_ENV=production

# Session security
SESSION_SECURE_COOKIE=true
SESSION_SAME_SITE=lax

# Trusted proxies (configure based on infrastructure)
TRUSTED_PROXIES=
```

### Trusted Proxies

For load balancers/reverse proxies:

```env
# Behind CloudFlare
TRUSTED_PROXIES=*

# Specific proxy
TRUSTED_PROXIES=192.168.1.0/24

# Direct VPS (no proxy)
TRUSTED_PROXIES=
```

## Input Validation

All user input is validated using Laravel's validation system.

**Examples**:
- Email validation on registration
- Payment amount validation
- File upload restrictions

## XSS Prevention

All output is escaped using Blade's `{{ }}` syntax.

Use `{!! !!}` only for trusted HTML content.

## CSRF Protection

All POST/PUT/DELETE forms include CSRF tokens:

```blade
<form method="POST">
    @csrf
    ...
</form>
```

## SQL Injection Prevention

All database queries use:
- Eloquent ORM with parameterized queries
- Query Builder with bindings
- No raw SQL without proper escaping

## Logging

Security events are logged to a dedicated channel:

```php
Log::channel('security')->info('Login attempt', ['email' => $email]);
```

**Configuration**: `config/logging.php`

## Authentication

Using Laravel Breeze with:
- Email verification required
- Password confirmation for sensitive actions
- Remember me token support

## Authorization

- Role-based access (user/admin)
- Organization-based permissions (owner/admin/member)
- Filament admin panel restricted to `is_admin = true`

## Secrets Management

Sensitive data in `.env`:
- API keys
- Database credentials
- Mail credentials
- Payment gateway keys

**Never commit `.env` to version control.**

## Security Headers

Recommended headers (configure in web server or middleware):

```
X-Content-Type-Options: nosniff
X-Frame-Options: DENY
X-XSS-Protection: 1; mode=block
Strict-Transport-Security: max-age=31536000; includeSubDomains
Content-Security-Policy: default-src 'self'
```

## Dependency Security

Run regular security audits:

```bash
# PHP dependencies
composer audit

# JavaScript dependencies
npm audit
```

## Related Files

- `app/Http/Middleware/VerifyMollieWebhook.php`
- `app/Http/Kernel.php` (middleware)
- `bootstrap/app.php` (trusted proxies)
- `config/logging.php` (security channel)
- `routes/auth.php` (rate limiting)
