# Payment Integration (Mollie)

## Overview

Payments are handled through Mollie. The system supports:
- One-time payments
- Recurring subscriptions
- Webhook handling for payment status updates

## Configuration

```env
MOLLIE_API_KEY=live_xxx
MOLLIE_WEBHOOK_IPS=87.233.217.240/29,87.233.217.248/30  # Optional IP whitelist
```

## Payment Flow

### First Payment (Subscription)

```
1. User selects plan on /pricing
2. CheckoutController creates Order (status: initiated)
3. MolliePaymentService creates payment with:
   - sequenceType: first
   - Creates Mollie customer
4. User redirected to Mollie
5. Payment completed
6. Webhook received at /webhooks/mollie
7. MollieWebhookService processes:
   - Order status → paid
   - PaymentFulfillmentService::fulfillOrder()
     - Creates UserLicense
     - Creates Mollie subscription
     - Adds credits
```

### Subscription Renewal

```
1. Mollie charges subscription automatically
2. Webhook received with subscription ID
3. MollieWebhookService::handleSubscriptionWebhook()
   - Finds license by subscription ID
   - Creates renewal order
   - Resets credits
```

## Services

### MolliePaymentService

Creates payments and retrieves payment status.

```php
// Create payment
$result = $service->createPayment([
    'amount' => ['currency' => 'EUR', 'value' => '49.00'],
    'description' => 'Premium License',
    'redirectUrl' => route('checkout.return'),
    'webhookUrl' => route('webhooks.mollie'),
    'metadata' => ['order_id' => $order->id],
]);

// Get payment
$payment = $service->getPayment($paymentId);
```

### MollieSubscriptionService

Manages subscriptions.

```php
// Create subscription
$result = $service->createSubscription($order, $customerId);

// Update subscription amount (for price changes)
$result = $service->updateSubscriptionAmount($customerId, $subscriptionId, $newAmount, 'EUR');

// Cancel subscription
$result = $service->cancelSubscription($customerId, $subscriptionId);

// Get subscription payments
$payments = $service->getSubscriptionPayments($customerId, $subscriptionId);
```

### MollieWebhookService

Processes Mollie webhooks.

```php
// Payment webhook (paid, canceled, expired, failed)
$service->handlePaymentWebhook($paymentId);

// Subscription webhook (renewal payments)
$service->handleSubscriptionWebhook($subscriptionId);
```

## Webhook Security

Webhooks are verified by IP whitelist (configurable via `MOLLIE_WEBHOOK_IPS`).

Default Mollie IPs:
- `87.233.217.240/29`
- `87.233.217.248/30`

## Order Statuses

| Status | Description |
|--------|-------------|
| `initiated` | Order created, awaiting payment |
| `pending` | Payment in progress |
| `paid` | Payment successful |
| `canceled` | Payment canceled by user |
| `expired` | Payment expired |
| `failed` | Payment failed |
| `refunded` | Payment refunded |
| `charged_back` | Chargeback received |

## Database Schema

### `orders` table

| Field | Description |
|-------|-------------|
| `uuid` | Public order identifier |
| `payer_type` | user/organization |
| `payer_id` | User or organization ID |
| `license_id` | License being purchased |
| `type` | onetime/subscription/subscription_renewal |
| `currency` | EUR/USD |
| `net_amount` | Amount excluding VAT |
| `tax_amount` | VAT amount |
| `gross_amount` | Total amount |
| `country` | For VAT calculation |
| `status` | Order status |
| `mollie_payment_id` | Mollie payment reference |
| `mollie_customer_id` | Mollie customer reference |
| `mollie_subscription_id` | Mollie subscription reference |
| `payment_method` | ideal/creditcard/etc |
| `paid_at` | Payment timestamp |
| `billing_snapshot` | Billing details at purchase |
| `meta` | Additional metadata |

## Webhook Endpoint

```
POST /webhooks/mollie
```

Handles both payment and subscription webhooks based on the ID format.

## Related Files

- `app/Services/MolliePaymentService.php`
- `app/Services/MollieSubscriptionService.php`
- `app/Services/MollieWebhookService.php`
- `app/Services/PaymentFulfillmentService.php`
- `app/Http/Controllers/WebhookController.php`
- `app/Http/Controllers/CheckoutController.php`
- `routes/web.php` (webhook route)
