<?php

namespace App\Services;

use App\Models\Order;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class MolliePaymentService
{
    /**
     * Payment methods that Mollie only accepts when the order currency is EUR.
     * Selecting one of these for a non-EUR order returns a 422 from Mollie's API
     * ("payment method not supported by this profile / currency mismatch").
     * Drop the method silently and let Mollie's own picker pick a compatible one.
     */
    private const EUR_ONLY_METHODS = ['ideal', 'bancontact', 'belfius', 'kbc', 'eps', 'mybank', 'przelewy24'];

    private string $apiKey;

    private string $baseUrl;

    public function __construct()
    {
        $apiKey = config('services.mollie.api_key');

        if (empty($apiKey)) {
            throw new \RuntimeException('Mollie API key is not configured. Please set MOLLIE_API_KEY in your environment.');
        }

        $this->apiKey = $apiKey;
        $this->baseUrl = 'https://api.mollie.com/v2';
    }

    /**
     * Create a one-time payment for onetime credits
     *
     * @param  string|null  $paymentMethod  Optional payment method to preselect
     * @return array Payment creation result
     */
    public function createPayment(Order $order, array $billingDetails, ?string $paymentMethod = null): array
    {
        $payload = [
            'amount' => [
                'currency' => strtoupper($order->currency),
                'value' => number_format($order->gross_amount, 2, '.', ''),
            ],
            'description' => $this->getPaymentDescription($order),
            'redirectUrl' => route('checkout.return', ['o' => $order->id]),
            'webhookUrl' => config('services.mollie.webhook_url'),
            'metadata' => [
                'order_id' => $order->id,
                'payer_type' => $order->payer_type,
                'payer_id' => $order->payer_id,
                'license_id' => $order->license_id,
                'type' => 'onetime',
            ],
            'locale' => $this->determineLocale($billingDetails['country'] ?? 'NL'),
        ];

        // Add payment method if specified and compatible with order currency
        if (! empty($paymentMethod) && $this->isMethodCompatibleWithCurrency($paymentMethod, strtoupper($order->currency))) {
            $payload['method'] = $paymentMethod;
        }

        // Add billing details for enhanced checkout
        if (! empty($billingDetails)) {
            $payload['billingAddress'] = $this->formatBillingAddress($billingDetails);
        }

        return $this->makeApiCall('POST', '/payments', $payload);
    }

    /**
     * Create first payment for subscription (to collect mandate)
     *
     * @param  string|null  $paymentMethod  Optional payment method to preselect
     * @return array Payment creation result
     */
    public function createFirstPayment(Order $order, array $billingDetails, ?string $paymentMethod = null): array
    {
        Log::info('Creating first payment', [
            'order_id' => $order->id,
            'payment_method' => $paymentMethod,
            'is_recurring_compatible' => $paymentMethod ? $this->isRecurringCompatibleMethod($paymentMethod) : 'no_method_specified',
        ]);

        // First, create a customer for the subscription
        $customerResult = $this->createCustomer($order, $billingDetails);

        if (! $customerResult['success']) {
            return $customerResult; // Return the customer creation error
        }

        $customerId = $customerResult['data']['id'];

        $payload = [
            'amount' => [
                'currency' => strtoupper($order->currency),
                'value' => number_format($order->gross_amount, 2, '.', ''),
            ],
            'customerId' => $customerId, // Required for first payments
            'description' => $this->getPaymentDescription($order),
            'redirectUrl' => route('checkout.return', ['o' => $order->id]),
            'webhookUrl' => config('services.mollie.webhook_url'),
            'sequenceType' => 'first', // Important for subscription setup
            'metadata' => [
                'order_id' => $order->id,
                'payer_type' => $order->payer_type,
                'payer_id' => $order->payer_id,
                'license_id' => $order->license_id,
                'type' => 'premium_first',
                'customer_id' => $customerId,
            ],
            'locale' => $this->determineLocale($billingDetails['country'] ?? 'NL'),
        ];

        // Add payment method if specified and compatible with recurring payments + currency
        if (! empty($paymentMethod)
            && $this->isRecurringCompatibleMethod($paymentMethod)
            && $this->isMethodCompatibleWithCurrency($paymentMethod, strtoupper($order->currency))
        ) {
            $payload['method'] = $paymentMethod;
        }

        // Add billing details
        if (! empty($billingDetails)) {
            $payload['billingAddress'] = $this->formatBillingAddress($billingDetails);
        }

        return $this->makeApiCall('POST', '/payments', $payload);
    }

    /**
     * Get payment details from Mollie
     */
    public function getPayment(string $paymentId): array
    {
        return $this->makeApiCall('GET', "/payments/{$paymentId}");
    }

    /**
     * Get available payment methods for amount and locale
     *
     * @param  bool  $recurringOnly  Filter to only show recurring-compatible methods
     */
    public function getPaymentMethods(float $amount, string $currency, string $locale = 'en_US', bool $recurringOnly = false): array
    {
        $params = [
            'amount[currency]' => strtoupper($currency),
            'amount[value]' => number_format($amount, 2, '.', ''),
            'locale' => $locale,
            'resource' => 'orders', // Get methods suitable for orders
        ];

        $result = $this->makeApiCall('GET', '/methods?'.http_build_query($params));

        if ($result['success'] && isset($result['data']['_embedded']['methods'])) {
            $methods = $result['data']['_embedded']['methods'];

            // Filter for recurring-compatible methods if requested
            if ($recurringOnly) {
                $methods = array_filter($methods, function ($method) {
                    return $this->isRecurringCompatibleMethod($method['id']);
                });
            }

            return [
                'success' => true,
                'methods' => $this->formatPaymentMethods($methods),
            ];
        }

        return [
            'success' => false,
            'methods' => [],
            'error' => $result['error'] ?? 'Failed to retrieve payment methods',
        ];
    }

    /**
     * Cancel a payment
     */
    public function cancelPayment(string $paymentId): array
    {
        return $this->makeApiCall('DELETE', "/payments/{$paymentId}");
    }

    /**
     * Create a customer for subscription payments
     *
     * @return array Customer creation result
     */
    public function createCustomer(Order $order, array $billingDetails): array
    {
        $customerName = $this->getCustomerName($order, $billingDetails);
        $customerEmail = $this->getCustomerEmail($order, $billingDetails);

        $payload = [
            'name' => $customerName,
            'email' => $customerEmail,
            'locale' => $this->determineLocale($billingDetails['country'] ?? 'NL'),
            'metadata' => [
                'order_id' => $order->id,
                'payer_type' => $order->payer_type,
                'payer_id' => $order->payer_id,
                'source' => 'app_checkout',
            ],
        ];

        return $this->makeApiCall('POST', '/customers', $payload);
    }

    /**
     * Make HTTP request to Mollie API
     */
    private function makeApiCall(string $method, string $endpoint, array $data = []): array
    {
        try {
            $response = Http::withToken($this->apiKey)
                ->timeout(30)
                ->{strtolower($method)}($this->baseUrl.$endpoint, $data);

            $responseData = $response->json();

            if ($response->successful()) {
                Log::info('Mollie API call successful', [
                    'method' => $method,
                    'endpoint' => $endpoint,
                    'status' => $response->status(),
                ]);

                return [
                    'success' => true,
                    'data' => $responseData,
                ];
            } else {
                Log::error('Mollie API call failed', [
                    'method' => $method,
                    'endpoint' => $endpoint,
                    'status' => $response->status(),
                    'response' => $responseData,
                ]);

                return [
                    'success' => false,
                    'error' => $responseData['detail'] ?? 'API request failed',
                    'status' => $response->status(),
                ];
            }

        } catch (\Exception $e) {
            Log::error('Mollie API request exception', [
                'method' => $method,
                'endpoint' => $endpoint,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'error' => 'Connection to payment provider failed: '.$e->getMessage(),
            ];
        }
    }

    /**
     * Get customer name from order and billing details
     */
    private function getCustomerName(Order $order, array $billingDetails): string
    {
        // For company orders, use company name if available
        if (! empty($billingDetails['company_name'])) {
            return $billingDetails['company_name'];
        }

        // For individual orders, construct from first_name + last_name
        $name = trim(($billingDetails['first_name'] ?? '').' '.($billingDetails['last_name'] ?? ''));

        if (! empty($name)) {
            return $name;
        }

        // Fallback: get from user/organization
        if ($order->payer_type === 'organization') {
            $organization = \App\Models\Organization::find($order->payer_id);

            return $organization->name ?? config('app.name') . ' Customer';
        } else {
            $user = \App\Models\User::find($order->payer_id);

            return $user->name ?? config('app.name') . ' Customer';
        }
    }

    /**
     * Get customer email from order and billing details
     */
    private function getCustomerEmail(Order $order, array $billingDetails): string
    {
        // Use email from billing details if available
        if (! empty($billingDetails['email'])) {
            return $billingDetails['email'];
        }

        // Fallback: get from user (organizations don't have email directly)
        if ($order->payer_type === 'organization') {
            // Get the admin user who initiated the purchase
            $user = auth()->user();

            return $user->email ?? config('mail.from.address', 'noreply@example.com');
        } else {
            $user = \App\Models\User::find($order->payer_id);

            return $user->email ?? config('mail.from.address', 'noreply@example.com');
        }
    }

    /**
     * Get payment description for Mollie
     */
    private function getPaymentDescription(Order $order): string
    {
        // Use short order ID (first 8 characters of UUID)
        $shortOrderId = substr($order->id, 0, 8);

        return config('app.name') . " - Order {$shortOrderId}";
    }

    /**
     * Format billing address for Mollie
     */
    private function formatBillingAddress(array $billingDetails): array
    {
        $address = [
            'streetAndNumber' => $billingDetails['street'] ?? '',
            'postalCode' => $billingDetails['postal_code'] ?? '',
            'city' => $billingDetails['city'] ?? '',
            'country' => strtoupper($billingDetails['country'] ?? 'NL'),
        ];

        // Add organization/company name if available
        if (! empty($billingDetails['company_name'])) {
            $address['organizationName'] = $billingDetails['company_name'];
        }

        return $address;
    }

    /**
     * Check if payment method is compatible with recurring payments
     */
    private function isRecurringCompatibleMethod(string $paymentMethod): bool
    {
        // Payment methods that support recurring payments (first payments)
        // Note: PayPal removed as it doesn't support first payments for subscriptions in this configuration
        $recurringCompatibleMethods = [
            'creditcard',
            'directdebit',      // SEPA Direct Debit (if available)
            'banktransfer',
        ];

        return in_array($paymentMethod, $recurringCompatibleMethods);
    }

    /**
     * Check if a payment method is compatible with the given currency.
     * EUR-only methods silently fall back to Mollie's method picker rather than causing a 422.
     */
    private function isMethodCompatibleWithCurrency(string $method, string $currency): bool
    {
        if ($currency !== 'EUR' && in_array($method, self::EUR_ONLY_METHODS, true)) {
            Log::warning('Payment method dropped: not compatible with currency', [
                'method' => $method,
                'currency' => $currency,
            ]);

            return false;
        }

        return true;
    }

    /**
     * Determine Mollie locale from country code
     */
    private function determineLocale(string $country): string
    {
        $localeMap = [
            'NL' => 'nl_NL',
            'DE' => 'de_DE',
            'FR' => 'fr_FR',
            'ES' => 'es_ES',
            'IT' => 'it_IT',
            'BE' => 'nl_BE',
            'AT' => 'de_AT',
            'CH' => 'de_CH',
        ];

        return $localeMap[strtoupper($country)] ?? 'en_US';
    }

    /**
     * Format payment methods for frontend consumption
     */
    private function formatPaymentMethods(array $methods): array
    {
        $formatted = [];

        foreach ($methods as $method) {
            $formatted[] = [
                'id' => $method['id'],
                'description' => $method['description'],
                'image' => $method['image']['svg'] ?? null,
                'status' => $method['status'],
            ];
        }

        // Prioritize common methods
        $priority = ['ideal', 'creditcard', 'bancontact', 'sofort', 'paypal'];

        usort($formatted, function ($a, $b) use ($priority) {
            $posA = array_search($a['id'], $priority);
            $posB = array_search($b['id'], $priority);

            if ($posA === false) {
                $posA = 999;
            }
            if ($posB === false) {
                $posB = 999;
            }

            return $posA <=> $posB;
        });

        return array_slice($formatted, 0, 3); // Limit to top 3 methods
    }

    // === Admin Panel Methods ===

    /**
     * List payments for admin panel with pagination
     */
    public function listPaymentsForAdmin(array $options = []): array
    {
        $params = [
            'limit' => $options['limit'] ?? 50,
            'include' => 'refunds,chargebacks,captures',
        ];

        if (! empty($options['from'])) {
            $params['from'] = $options['from'];
        }

        if ($profileId = config('services.mollie.profile_id')) {
            $params['profileId'] = $profileId;
        }

        $result = $this->makeApiCall('GET', '/payments?'.http_build_query($params));

        if ($result['success'] && isset($result['data']['_embedded']['payments'])) {
            return [
                'success' => true,
                'payments' => $result['data']['_embedded']['payments'],
                'count' => $result['data']['count'],
                '_links' => $result['data']['_links'] ?? [],
            ];
        }

        return [
            'success' => false,
            'payments' => [],
            'error' => $result['error'] ?? 'Failed to retrieve payments',
        ];
    }

    /**
     * Get detailed payment information for admin panel
     */
    public function getPaymentForAdmin(string $paymentId): array
    {
        $params = [
            'include' => 'refunds,chargebacks,captures,settlement,customer,mandate,order',
        ];

        $result = $this->makeApiCall('GET', "/payments/{$paymentId}?".http_build_query($params));

        if ($result['success']) {
            $payment = $result['data'];

            return [
                'success' => true,
                'payment' => $payment,
                'linked_user' => $this->resolveUserFromPaymentData($payment),
                'linked_organization' => $this->resolveOrganizationFromPaymentData($payment),
            ];
        }

        return $result;
    }

    /**
     * Create refund for admin panel
     */
    public function createRefundForAdmin(string $paymentId, array $refundData): array
    {
        $payload = [
            'amount' => [
                'currency' => $refundData['currency'],
                'value' => number_format((float) $refundData['amount'], 2, '.', ''),
            ],
            'description' => $refundData['description'] ?? 'Refund via admin panel',
        ];

        $result = $this->makeApiCall('POST', "/payments/{$paymentId}/refunds", $payload);

        if ($result['success']) {
            Log::info('Refund created successfully', [
                'payment_id' => $paymentId,
                'refund_id' => $result['data']['id'],
                'amount' => $refundData['amount'],
                'admin_user' => auth()->id(),
            ]);
        }

        return $result;
    }

    /**
     * Search payments by various criteria
     */
    public function searchPaymentsForAdmin(string $query, array $options = []): array
    {
        $allPayments = $this->listPaymentsForAdmin($options);

        if (! $allPayments['success']) {
            return $allPayments;
        }

        $filteredPayments = [];
        $query = strtolower(trim($query));

        foreach ($allPayments['payments'] as $payment) {
            if ($this->paymentMatchesSearch($payment, $query)) {
                $filteredPayments[] = $payment;
            }
        }

        return [
            'success' => true,
            'payments' => $filteredPayments,
            'count' => count($filteredPayments),
            'query' => $query,
        ];
    }

    /**
     * Filter payments by criteria
     */
    public function filterPaymentsForAdmin(array $filters, array $options = []): array
    {
        $allPayments = $this->listPaymentsForAdmin($options);

        if (! $allPayments['success']) {
            return $allPayments;
        }

        $filteredPayments = [];

        foreach ($allPayments['payments'] as $payment) {
            if ($this->paymentMatchesFilters($payment, $filters)) {
                $filteredPayments[] = $payment;
            }
        }

        return [
            'success' => true,
            'payments' => $filteredPayments,
            'count' => count($filteredPayments),
            'filters' => $filters,
        ];
    }

    /**
     * Get available payment methods for admin dropdowns
     */
    public function getPaymentMethodsForAdmin(): array
    {
        return [
            'ideal' => 'iDEAL',
            'creditcard' => 'Credit Card',
            'banktransfer' => 'Bank Transfer',
            'paypal' => 'PayPal',
            'sofort' => 'SOFORT Banking',
            'bancontact' => 'Bancontact',
            'eps' => 'EPS',
            'giropay' => 'Giropay',
            'kbc' => 'KBC Payment Button',
            'belfius' => 'Belfius Pay Button',
            'klarnapaylater' => 'Klarna Pay Later',
            'klarnasliceit' => 'Klarna Slice It',
            'przelewy24' => 'Przelewy24',
            'applepay' => 'Apple Pay',
            'mybank' => 'MyBank',
        ];
    }

    /**
     * Get Mollie dashboard URL for payment
     */
    public function getDashboardUrlForAdmin(array $payment): string
    {
        $paymentId = $payment['id'];
        $isTest = str_starts_with($paymentId, 'test_');

        return "https://my.mollie.com/payments/{$paymentId}";
    }

    /**
     * Calculate total refunded amount for payment
     */
    public function getRefundedAmount(array $payment): float
    {
        $totalRefunded = 0.0;

        if (isset($payment['_embedded']['refunds'])) {
            foreach ($payment['_embedded']['refunds'] as $refund) {
                if ($refund['status'] === 'refunded') {
                    $totalRefunded += (float) $refund['amount']['value'];
                }
            }
        }

        return $totalRefunded;
    }

    /**
     * Resolve user from payment metadata
     */
    private function resolveUserFromPaymentData(array $payment): ?User
    {
        if (isset($payment['metadata']['user_id'])) {
            return User::find($payment['metadata']['user_id']);
        }

        // Fallback to email matching
        $email = $this->extractEmailFromPaymentData($payment);
        if ($email) {
            return User::where('email', $email)->first();
        }

        return null;
    }

    /**
     * Resolve organization from payment metadata
     */
    private function resolveOrganizationFromPaymentData(array $payment): ?Organization
    {
        if (isset($payment['metadata']['organization_id'])) {
            return Organization::find($payment['metadata']['organization_id']);
        }

        // Could add more fallback logic here if needed
        return null;
    }

    /**
     * Extract email from payment data
     */
    private function extractEmailFromPaymentData(array $payment): ?string
    {
        return $payment['details']['customerEmail']
            ?? $payment['billingAddress']['email']
            ?? $payment['shippingAddress']['email']
            ?? null;
    }

    /**
     * Check if payment matches search query
     */
    private function paymentMatchesSearch(array $payment, string $query): bool
    {
        $searchFields = [
            $payment['id'],
            $payment['description'] ?? '',
            $payment['metadata']['order_id'] ?? '',
            $payment['metadata']['order_reference'] ?? '',
            $this->extractEmailFromPaymentData($payment),
            json_encode($payment['metadata'] ?? []),
        ];

        foreach ($searchFields as $field) {
            if ($field && str_contains(strtolower((string) $field), $query)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if payment matches filters
     */
    private function paymentMatchesFilters(array $payment, array $filters): bool
    {
        if (! empty($filters['status']) && $payment['status'] !== $filters['status']) {
            return false;
        }

        if (! empty($filters['method']) && $payment['method'] !== $filters['method']) {
            return false;
        }

        if (! empty($filters['has_refunds'])) {
            $hasRefunds = isset($payment['_embedded']['refunds']) && count($payment['_embedded']['refunds']) > 0;
            if (($filters['has_refunds'] === 'yes') !== $hasRefunds) {
                return false;
            }
        }

        if (! empty($filters['amount_min'])) {
            if ((float) $payment['amount']['value'] < (float) $filters['amount_min']) {
                return false;
            }
        }

        if (! empty($filters['amount_max'])) {
            if ((float) $payment['amount']['value'] > (float) $filters['amount_max']) {
                return false;
            }
        }

        if (! empty($filters['created_from'])) {
            $createdAt = new \DateTime($payment['createdAt']);
            if ($createdAt < new \DateTime($filters['created_from'])) {
                return false;
            }
        }

        if (! empty($filters['created_to'])) {
            $createdAt = new \DateTime($payment['createdAt']);
            if ($createdAt > new \DateTime($filters['created_to'])) {
                return false;
            }
        }

        return true;
    }
}
