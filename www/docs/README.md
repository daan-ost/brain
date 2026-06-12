# Basewebsite Documentation

A Laravel SaaS boilerplate with Mollie payments, license management, and user/organization support.

## Table of Contents

- [Getting Started](./getting-started.md)
- [License System](./licenses.md)
- [Payment Integration](./payments.md)
- [Scheduled Tasks](./scheduled-tasks.md)
- [Security](./security.md)
- [Testing](./testing.md)

## Quick Links

### For Users
- `/profile/plans` - Manage subscription, cancel renewal
- `/profile/credits` - View credit balance
- `/profile/invoices` - Download invoices

### For Admins
- `/beheer/licenses` - Manage license types and pricing
- `/beheer/users` - Manage users
- `/beheer/orders` - View all orders

## Key Features

- **Multi-tier Licensing**: Free, One-time, Premium, Enterprise tiers
- **Mollie Integration**: Payments, subscriptions, webhooks
- **Credit System**: User and organization credit pools with LIFO handling
- **Price Change Management**: Scheduled price changes with email notifications
- **Organization Support**: Team management with admin/member roles
