# BaseWebsite

Generic SaaS starter met auth, users, organizations, payments, en admin backend.

## Tech Stack

- **Framework:** Laravel 12 met PHP 8.4 (MAMP: `/Applications/MAMP/bin/php/php8.4.17/bin/php`)
- **Frontend:** Livewire 3, Tailwind CSS, Alpine.js
- **Admin:** Filament 3
- **Database:** MySQL
- **Email:** Postmark (transactioneel + templates)
- **Payments:** Mollie
- **PDF:** DomPDF, FPDF/FPDI
- **Permissions:** Spatie Laravel Permission
- **Testing:** Pest

## Project Structuur

```
app/
├── Models/           # Eloquent models
├── Livewire/         # Livewire components
├── Filament/         # Admin panels en resources
├── Services/         # Business logic services
├── Jobs/             # Queue jobs
├── Mail/             # Mailable classes
└── Http/
    ├── Controllers/  # API en web controllers
    └── Middleware/   # Custom middleware
```

## Core Models

| Model | Beschrijving |
|-------|--------------|
| User | Gebruikers met multi-org support |
| Organization | Tenant/workspace |
| OrganizationUser | Pivot met roles |
| License | Licentie definities |
| CreditLedger | Credit transacties |
| InboundEmail | Inkomende emails |
| PostmarkTemplate | Email templates |

## Multi-tenant Architectuur

- `Organization` is de centrale tenant
- Users kunnen lid zijn van meerdere organizations
- Data isolatie via `organization_id` foreign key
- Organization switching via session/middleware

## Conventies

### Database
- ULIDs voor primary keys (waar mogelijk)
- Timestamps: `created_at`, `updated_at`
- Soft deletes alleen waar business logic dit vereist
- Foreign keys met cascade of set null

### Models
- Definieer altijd `$fillable` of `$guarded`
- Relationships als aparte methods
- Query scopes voor herbruikbare filters
- Casts voor JSON en date fields

### Services
- Business logic in Service classes
- Controllers alleen voor HTTP handling
- Jobs voor async/heavy processing

### Livewire
- Components in `app/Livewire/`
- Gebruik `wire:model.blur` ipv `wire:model.live` waar mogelijk
- Form validation via Livewire rules

### Filament
- Resources voor CRUD in admin
- Custom pages voor dashboards/reports
- Widgets voor statistieken

## Credit System

- Organizations hebben credit pools
- CreditLedger tracked alle transacties
- Credits kunnen gekocht worden via Mollie
- Negatieve balans = actie geblokkeerd

## Belangrijke Instructies

### NIET DOEN
- `migrate:fresh` of `migrate:reset` - wist alle data
- Business logic in Controllers of Models
- Directe DB queries zonder Eloquent
- Hardcoded organization IDs

### WEL DOEN
- Migrations voor alle schema changes
- Tests voor nieuwe features (Pest)
- Form Requests voor validatie
- Events voor side effects (mail, logging)
- Scope queries op organization waar relevant

## Claude Code CLI

Dit project kan gebouwd worden via AIfactory's spec pipeline.

### Concurrent Sessions

Bij "API Error: 400 due to tool use concurrency issues":
- Gebruik `--tools ""` om built-in tools uit te schakelen
- Gebruik `--strict-mcp-config` om MCP servers uit te schakelen
- `--allowedTools ''` alleen is NIET voldoende

```bash
# Concurrent-safe uitvoering:
claude --print --output-format json \
  --tools "" \
  --strict-mcp-config \
  < prompt.txt
```

### Authenticatie

- CLI gebruikt OAuth (Max subscription), niet API key
- Bij auth errors: `claude /login` in terminal
- Unset `ANTHROPIC_API_KEY` en `CLAUDE_API_KEY` in shell voor OAuth
