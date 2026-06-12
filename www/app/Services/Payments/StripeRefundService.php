<?php

namespace App\Services\Payments;

use App\Enums\OrderStatus;
use App\Models\Order;
use Illuminate\Support\Facades\Log;
use Stripe\Refund;

class StripeRefundService
{
    // Stripe SDK is initialized in AppServiceProvider::boot()

    /**
     * Create a refund for a Stripe order.
     * Amount in euros (decimal); pass null to refund the full amount.
     */
    public function createRefund(Order $order, ?float $amountEur = null, string $reason = 'requested_by_customer'): array
    {
        if ($order->payment_provider !== 'stripe') {
            return ['success' => false, 'error' => 'Order is not a Stripe payment.'];
        }

        $paymentIntentId = $this->resolvePaymentIntentId($order);

        if (! $paymentIntentId) {
            return ['success' => false, 'error' => 'No Stripe payment intent found for this order.'];
        }

        $params = [
            'payment_intent' => $paymentIntentId,
            'reason' => $reason,
            'metadata' => [
                'order_id' => $order->id,
                'admin_user_id' => (string) (auth()->id() ?? 'system'),
            ],
        ];

        if ($amountEur !== null) {
            $params['amount'] = (int) round($amountEur * 100);
        }

        try {
            $refund = Refund::create($params);

            $isFullRefund = $amountEur === null
                || (int) round($amountEur * 100) >= (int) round((float) $order->gross_amount * 100);

            $order->update([
                'status' => $isFullRefund ? OrderStatus::Refunded : $order->status,
            ]);

            Log::info('Stripe refund created', [
                'order_id' => $order->id,
                'refund_id' => $refund->id,
                'amount_eur' => $amountEur,
                'full_refund' => $isFullRefund,
            ]);

            return [
                'success' => true,
                'refund_id' => $refund->id,
                'amount' => $refund->amount / 100,
            ];
        } catch (\Stripe\Exception\ApiErrorException $e) {
            Log::error('Stripe refund failed', [
                'order_id' => $order->id,
                'error' => $e->getMessage(),
            ]);

            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    private function resolvePaymentIntentId(Order $order): ?string
    {
        // Checkout sessions store session ID in provider_payment_id; we need the payment intent
        // Try provider_payment_id directly (could be a pi_ or cs_)
        $id = $order->provider_payment_id;

        if (str_starts_with((string) $id, 'pi_')) {
            return $id;
        }

        // If it's a checkout session ID, retrieve the session to get payment_intent
        if (str_starts_with((string) $id, 'cs_')) {
            try {
                $session = \Stripe\Checkout\Session::retrieve($id);

                return $session->payment_intent;
            } catch (\Throwable) {
                return null;
            }
        }

        return null;
    }
}
