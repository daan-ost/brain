<?php

namespace App\Services;

use App\Models\Order;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class MollieSubscriptionService
{
    private string $apiKey;

    private string $baseUrl;

    public function __construct()
    {
        $this->apiKey = config('services.mollie.key', env('MOLLIE_API_KEY'));
        $this->baseUrl = 'https://api.mollie.com/v2';
    }

    /**
     * Create subscription after successful first payment
     *
     * @param  string  $customerId  Mollie customer ID from first payment
     * @return array Subscription creation result
     */
    public function createSubscription(Order $order, string $customerId): array
    {
        $license = $order->license;
        $interval = $this->getMollieInterval($license?->billing_cycle ?? 'yearly');

        // Calculate startDate for first recurring payment (prevents immediate double charge)
        $startDate = $this->calculateStartDate($license);

        $payload = [
            'amount' => [
                'currency' => strtoupper($order->currency),
                'value' => number_format($order->gross_amount, 2, '.', ''),
            ],
            'interval' => $interval,
            'startDate' => $startDate,
            'description' => $this->getSubscriptionDescription($order),
            'webhookUrl' => route('webhooks.mollie'),
            'metadata' => [
                'order_id' => $order->id,
                'payer_type' => $order->payer_type,
                'payer_id' => $order->payer_id,
                'license_id' => $order->license_id,
                'type' => 'premium_recurring',
                'billing_cycle' => $license?->billing_cycle,
            ],
        ];

        return $this->makeApiCall('POST', "/customers/{$customerId}/subscriptions", $payload);
    }

    /**
     * Convert billing_cycle to Mollie interval format
     */
    private function getMollieInterval(?string $billingCycle): string
    {
        return match ($billingCycle) {
            'monthly' => '1 month',
            'yearly' => '12 months',
            '6month' => '6 months',
            default => '12 months', // Default to yearly
        };
    }

    /**
     * Calculate subscription start date based on billing cycle
     * This ensures the first recurring payment happens after the initial billing period
     */
    private function calculateStartDate($license): string
    {
        $billingCycle = $license?->billing_cycle ?? 'yearly';

        $startDate = match ($billingCycle) {
            'monthly' => now()->addMonth(),
            '6month' => now()->addMonths(6),
            'yearly' => now()->addYear(),
            default => now()->addYear(),
        };

        return $startDate->format('Y-m-d');
    }

    /**
     * Get subscription details
     */
    public function getSubscription(string $customerId, string $subscriptionId): array
    {
        return $this->makeApiCall('GET', "/customers/{$customerId}/subscriptions/{$subscriptionId}");
    }

    /**
     * Cancel subscription
     */
    public function cancelSubscription(string $customerId, string $subscriptionId): array
    {
        return $this->makeApiCall('DELETE', "/customers/{$customerId}/subscriptions/{$subscriptionId}");
    }

    /**
     * Get customer details
     */
    public function getCustomer(string $customerId): array
    {
        return $this->makeApiCall('GET', "/customers/{$customerId}");
    }

    /**
     * Update subscription amount (for plan changes)
     */
    public function updateSubscriptionAmount(string $customerId, string $subscriptionId, float $newAmount, string $currency): array
    {
        $payload = [
            'amount' => [
                'currency' => strtoupper($currency),
                'value' => number_format($newAmount, 2, '.', ''),
            ],
        ];

        return $this->makeApiCall('PATCH', "/customers/{$customerId}/subscriptions/{$subscriptionId}", $payload);
    }

    /**
     * List payments for a subscription
     */
    public function getSubscriptionPayments(string $customerId, string $subscriptionId, int $limit = 10): array
    {
        $params = [
            'limit' => $limit,
        ];

        return $this->makeApiCall('GET', "/customers/{$customerId}/subscriptions/{$subscriptionId}/payments?".http_build_query($params));
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
                Log::info('Mollie Subscription API call successful', [
                    'method' => $method,
                    'endpoint' => $endpoint,
                    'status' => $response->status(),
                ]);

                return [
                    'success' => true,
                    'data' => $responseData,
                ];
            } else {
                Log::error('Mollie Subscription API call failed', [
                    'method' => $method,
                    'endpoint' => $endpoint,
                    'status' => $response->status(),
                    'response' => $responseData,
                ]);

                return [
                    'success' => false,
                    'error' => $responseData['detail'] ?? 'Subscription API request failed',
                    'status' => $response->status(),
                ];
            }

        } catch (\Exception $e) {
            Log::error('Mollie Subscription API request exception', [
                'method' => $method,
                'endpoint' => $endpoint,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'error' => 'Connection to subscription service failed: '.$e->getMessage(),
            ];
        }
    }

    /**
     * Get subscription description
     */
    private function getSubscriptionDescription(Order $order): string
    {
        $license = $order->license;

        if (! $license) {
            return config('app.name') . ' - Premium Subscription';
        }

        return config('app.name') . " - {$license->name} (Annual renewal)";
    }

    /**
     * Check if subscription is active
     *
     * @param  array  $subscription  Subscription data from Mollie
     */
    public function isSubscriptionActive(array $subscription): bool
    {
        $status = $subscription['status'] ?? '';

        return in_array($status, ['active', 'pending']);
    }

    /**
     * Get next billing date from subscription
     */
    public function getNextBillingDate(array $subscription): ?\Carbon\Carbon
    {
        if (isset($subscription['nextChargeDate'])) {
            return \Carbon\Carbon::parse($subscription['nextChargeDate']);
        }

        return null;
    }

    /**
     * Get subscription status in human-readable format
     */
    public function getSubscriptionStatus(array $subscription): string
    {
        $status = $subscription['status'] ?? 'unknown';

        return match ($status) {
            'active' => 'Active',
            'pending' => 'Pending',
            'canceled' => 'Canceled',
            'suspended' => 'Suspended',
            'completed' => 'Completed',
            default => 'Unknown'
        };
    }
}
