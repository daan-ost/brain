# Admin Panel Documentatie

Het admin panel is toegankelijk via `/beheer` en is gebouwd met Filament PHP.

## Toegang

Alleen gebruikers met `is_admin = true` hebben toegang tot het admin panel.

## Resources Overzicht

### Users (`/beheer/users`)

Beheer van alle gebruikers in het systeem.

**Tabel Acties:**
- **Grant Credits** - Voeg bonus credits toe aan een gebruiker
  - Kies een bedrag (standaard 100)
  - Selecteer een reden (bonus, compensation, promotion, refund, correction)
  - Optionele notitie toevoegen
  - Credits worden automatisch bijgeschreven en gelogd in de credit ledger

**Relatie Managers:**

#### User Licenses
Overzicht van alle licenties van een gebruiker.

| Kolom | Beschrijving |
|-------|-------------|
| License | Naam van de licentie |
| Tier | Type: free, onetime, premium, enterprise |
| Status | active, inactive, canceled, expired, pending |
| Subscription | Active, Canceled, of N/A |
| Next Renewal | Volgende verlengingsdatum (berekend op basis van billing cycle) |
| Price | Prijs bij aankoop |
| Source | Bron: manual, checkout, upgrade, etc. |

**Acties:**
- **Activate** - Activeer een inactieve licentie
- **Cancel** - Annuleer een actieve subscription (via Mollie API)
- **Mollie** - Open de subscription in Mollie dashboard
- **Edit** - Bewerk licentie details
- **Delete** - Verwijder de licentie

#### Credit Ledger
Volledige credit historie van de gebruiker.

| Kolom | Beschrijving |
|-------|-------------|
| Date | Datum van de transactie |
| Change | Delta (+/- credits) |
| Reason | Reden voor de wijziging |
| Balance After | Saldo na de transactie |
| Expires | Vervaldatum van de credits (kleurgecodeerd) |
| Details | Extra informatie uit metadata |

**Expiratie Kleuren:**
- Groen: Verloopt over meer dan 30 dagen
- Oranje: Verloopt binnen 30 dagen
- Rood: Verlopen

---

### Organizations (`/beheer/organizations`)

Beheer van organisaties en hun licenties/credits.

**Relatie Managers:**

#### Organization Licenses
Vergelijkbaar met User Licenses, met extra ondersteuning voor:
- **Invoice Billing** - Organisaties kunnen op factuur betalen
- Billing method wordt getoond (Online/Invoice)

#### Credit Ledger
Credit historie voor de organisatie, inclusief expiratie weergave.

**Header Actie:**
- **Add Credit Adjustment** - Voeg handmatig credits toe of trek af

---

### Orders (`/beheer/orders`)

Overzicht van alle bestellingen en betalingen.

**Tabel Acties:**

| Actie | Icoon | Beschrijving |
|-------|-------|-------------|
| **Resend** | Envelope | Verstuur de factuur opnieuw per email |
| **Generate Invoice** | Document+ | Genereer een factuur voor een betaalde order zonder factuur |
| **Refund** | Arrow-back | Verwerk een (gedeeltelijke) refund via Mollie |
| **Mollie** | External link | Open de betaling in Mollie dashboard |
| **View/Edit** | Eye/Pencil | Bekijk of bewerk de order |

**Refund Actie:**
- Voer het te refunden bedrag in (max: bruto bedrag)
- Optionele reden opgeven
- Refund wordt direct verwerkt via Mollie API
- Metadata wordt opgeslagen (refund_id, amount, reason, timestamp, admin)

**Zichtbaarheid:**
- Resend: Alleen bij orders met invoice_number EN invoice_file_path
- Generate Invoice: Alleen bij betaalde orders ZONDER invoice_number
- Refund: Alleen bij betaalde orders MET mollie_payment_id
- Mollie: Alleen bij orders MET mollie_payment_id

---

### Licenses (`/beheer/licenses`)

Beheer van beschikbare licentie types.

**Velden:**
- Name, Description
- Tier (free, onetime, premium, enterprise)
- Billing Cycle (onetime, monthly, 6month, yearly)
- Pricing (maandelijks, jaarlijks, 6-maandelijks)
- Credits per cycle
- Active status

---

### Credit Ledger (`/beheer/credit-ledger`)

Globaal overzicht van alle credit transacties in het systeem.

---

## Services

### LicenseRenewalService

Berekent de volgende verlengingsdatum op basis van:
- `starts_at` of `last_credit_reset_at` of `created_at`
- Billing cycle (monthly, yearly, 6month)

```php
$renewalService = app(LicenseRenewalService::class);
$nextRenewal = $renewalService->getNextRenewalDate($startDate, 'yearly');
```

Cancelation via admin:
```php
$result = $renewalService->cancelRenewal($userLicense, 'user');
// of
$result = $renewalService->cancelRenewal($orgLicense, 'organization');
```

### MolliePaymentService

Verwerkt refunds via Mollie:
```php
$service = app(MolliePaymentService::class);
$result = $service->createRefundForAdmin($paymentId, [
    'amount' => 49.00,
    'reason' => 'Customer requested refund',
]);
```

### InvoiceGenerationService

Genereert facturen voor orders:
```php
$service = app(InvoiceGenerationService::class);
$result = $service->generateInvoice($order, sendEmail: true);
```

---

## Database Schema

### Credit Ledger Tabel

```sql
credit_ledger
- id
- user_id
- delta (integer)
- reason (string)
- balance_after (integer)
- meta (json)
- expires_at (timestamp, nullable)  -- Nieuw: credit expiratie
- created_at
```

### Order Status Values

Database enum waarden:
- `initiated` - Order gestart
- `pending` - Wacht op betaling
- `paid` - Betaald
- `failed` - Mislukt
- `canceled` - Geannuleerd
- `invoice_requested` - Factuur aangevraagd

> **Let op:** Refund status wordt opgeslagen in de `meta` kolom, niet als aparte status.

---

## Tests

Admin functionaliteit is getest in:
```
tests/Feature/Filament/AdminActionsTest.php
```

Voer tests uit met:
```bash
./vendor/bin/pest tests/Feature/Filament/AdminActionsTest.php
```

Testdekking:
- Grant credits actie
- Credit ledger entries
- Subscription status berekening (active, canceled, N/A)
- Organization subscription management
- Order refund metadata
- Credit expiratie detectie
- Renewal date berekening
