<?php

namespace App\Livewire;

use App\Models\License;
use App\Models\Order;
use Illuminate\Support\Facades\Log;
use Livewire\Component;

class ActivationWizard extends Component
{
    // Order data
    public ?string $orderId = null;

    public ?array $orderData = null;

    public string $status = 'unknown'; // 'success', 'error', 'pending', 'invoice_pending', 'unknown'

    public string $message = '';

    public ?string $invoiceNumber = null;

    public function mount()
    {
        $this->checkActivationStatus();
    }

    /**
     * Check activation status from session or URL parameters
     */
    protected function checkActivationStatus(): void
    {
        // Check for invoice pending status first
        if (session()->has('invoice_pending') && session('invoice_pending')) {
            $this->status = 'invoice_pending';
            $this->invoiceNumber = session('invoice_number');
            $this->message = __('checkout.license_request_submitted_description');
            $this->loadOrderData();

        } elseif (session()->has('success')) {
            $this->status = 'success';
            $this->message = session('success');

            // Try to load order data from session or URL
            $this->loadOrderData();

        } elseif (session()->has('error')) {
            $this->status = 'error';
            $this->message = session('error');

        } elseif (session()->has('info')) {
            $this->status = 'pending';
            $this->message = session('info');

        } else {
            // Check if we have an order parameter - load order and determine status from it
            $orderId = request('order');
            if ($orderId) {
                $order = Order::find($orderId);
                if ($order && $order->isPaid()) {
                    $this->status = 'success';
                    $this->message = __('checkout.payment_successful_message');
                    $this->loadOrderData();
                } elseif ($order && $order->isFailed()) {
                    $this->status = 'error';
                    $this->message = __('checkout.payment_failed_message');
                } elseif ($order && $order->isPending()) {
                    $this->status = 'pending';
                    $this->message = __('checkout.processing_payment_message');
                } else {
                    $this->status = 'unknown';
                    $this->message = __('checkout.activation_status_unknown_message');
                }
            } else {
                $this->status = 'unknown';
                $this->message = __('checkout.activation_status_unknown_message');
            }
        }

        Log::info('Activation wizard loaded', [
            'status' => $this->status,
            'order_id' => $this->orderId,
            'has_order_data' => ! is_null($this->orderData),
        ]);
    }

    /**
     * Load order data for display
     */
    protected function loadOrderData(): void
    {
        // Try to get order ID from session or URL
        $orderId = session('order_id') ?? request('order');

        if ($orderId) {
            $order = Order::with('license')->find($orderId);

            if ($order) {
                $this->orderId = $order->id;
                $this->orderData = [
                    'id' => $order->id,
                    'status' => $order->status,
                    'gross_amount' => $order->gross_amount,
                    'currency' => $order->currency,
                    'formatted_amount' => $this->formatAmount($order->gross_amount, $order->currency),
                    'payment_provider' => $order->meta['payment_provider'] ?? null,
                    'is_invoice_payment' => ($order->meta['payment_provider'] ?? null) === 'invoice',
                    'license' => $order->license ? [
                        'id' => $order->license->id,
                        'name' => $order->license->name,
                        'tier' => $order->license->tier,
                        'credits' => $order->license->credits,
                        'period' => $order->license->period,
                    ] : null,
                ];
            }
        }
    }

    /**
     * Format amount for display using locale-aware formatting.
     *
     * Delegates to PricingCalculatorService::formatAmount() which uses
     * LocaleService for decimal/thousands separators based on auth user.
     */
    protected function formatAmount(float $amount, string $currency): string
    {
        return app(\App\Services\PricingCalculatorService::class)->formatAmount($amount, $currency, true);
    }

    /**
     * Get validity text for onetime licenses
     */
    public function getValidityText(?int $period): string
    {
        return License::formatValidityPeriod($period);
    }

    /**
     * Refresh page to check status
     */
    public function refreshStatus()
    {
        return redirect(request()->url());
    }

    public function render()
    {
        return view('livewire.activation-wizard');
    }
}
