# Getting Started

## Requirements

- PHP 8.2+
- MySQL 8.0+ / MariaDB 10.6+
- Node.js 18+
- Composer 2.x

## Installation

```bash
# Clone repository
git clone <repository-url>
cd basewebsite

# Install PHP dependencies
composer install

# Install Node dependencies
npm install

# Copy environment file
cp .env.example .env

# Generate application key
php artisan key:generate

# Run migrations
php artisan migrate

# Seed database (optional)
php artisan db:seed

# Build assets
npm run build
```

## Configuration

### Environment Variables

Essential configuration in `.env`:

```env
APP_NAME="Your App Name"
APP_URL=https://your-domain.com
APP_ENV=production
APP_DEBUG=false

# Database
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=your_database
DB_USERNAME=your_username
DB_PASSWORD=your_password

# Mollie Payments
MOLLIE_API_KEY=live_xxx
MOLLIE_WEBHOOK_IPS=87.233.217.240/29,87.233.217.248/30

# Mail (Postmark)
MAIL_MAILER=postmark
POSTMARK_TOKEN=xxx

# Session
SESSION_DRIVER=database
SESSION_SECURE_COOKIE=true
```

### Admin User

Create an admin user:

```bash
php artisan tinker
```

```php
$user = User::find(1);
$user->is_admin = true;
$user->save();
```

Or via database:

```sql
UPDATE users SET is_admin = 1 WHERE email = 'admin@example.com';
```

## Directory Structure

```
app/
├── Console/Commands/       # Artisan commands
├── Filament/Resources/     # Admin panel resources
├── Http/Controllers/       # Web controllers
├── Models/                 # Eloquent models
├── Services/               # Business logic services
├── Jobs/                   # Queue jobs
└── Enums/                  # Enums (OrderStatus, etc.)

resources/
├── views/
│   ├── profile/            # User profile pages
│   ├── checkout/           # Checkout flow
│   └── livewire/           # Livewire components

routes/
├── web.php                 # Web routes
├── auth.php                # Authentication routes
└── api.php                 # API routes

docs/                       # Documentation
```

## Key URLs

| URL | Description |
|-----|-------------|
| `/` | Homepage |
| `/login` | User login |
| `/register` | User registration |
| `/profile` | User profile |
| `/profile/plans` | Manage subscription |
| `/profile/credits` | View credits |
| `/profile/invoices` | View invoices |
| `/pricing` | Pricing page |
| `/checkout` | Checkout flow |
| `/beheer` | Admin panel (Filament) |
| `/webhooks/mollie` | Mollie webhook endpoint |

## Development

```bash
# Start development server
php artisan serve

# Watch for asset changes
npm run dev

# Run tests
./vendor/bin/pest

# Run code formatter
./vendor/bin/pint
```

## Deployment Checklist

- [ ] Set `APP_ENV=production`
- [ ] Set `APP_DEBUG=false`
- [ ] Configure production database
- [ ] Set up Mollie live API key
- [ ] Configure mail provider
- [ ] Set `SESSION_SECURE_COOKIE=true`
- [ ] Configure trusted proxies if behind load balancer
- [ ] Set up SSL certificate
- [ ] Configure queue worker (supervisor)
- [ ] Set up cronjobs (see scheduled-tasks.md)
- [ ] Run `php artisan config:cache`
- [ ] Run `php artisan route:cache`
- [ ] Run `npm run build`
