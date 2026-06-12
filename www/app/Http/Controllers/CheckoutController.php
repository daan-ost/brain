<?php

namespace App\Http\Controllers;

use App\Enums\OrderStatus;
use App\Http\Controllers\Traits\AuthorizesOrganizationPayments;
use App\Models\License;
use App\Models\Order;
use App\Models\OrganizationLicense;
use App\Services\IPRegistryService;
use App\Services\MolliePaymentService;
use App\Services\PaymentFulfillmentService;
use App\Services\PaymentProviderManager;
use App\Services\PricingCalculatorService;
use App\Services\VIESValidationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Session;

class CheckoutController extends Controller
{
    use AuthorizesOrganizationPayments;

    public function __construct(
        private PricingCalculatorService $pricingCalculator,
        private IPRegistryService $ipRegistry,
        private VIESValidationService $viesValidator,
        private MolliePaymentService $molliePayment,
        private PaymentFulfillmentService $fulfillmentService,
        private PaymentProviderManager $providers,
    ) {}

    /**
     * Create or update order from checkout form
     */
    public function createOrder(Request $request)
    {
        $request->validate([
            'license_id' => 'required|exists:licenses,id',
            'payer_type' => 'required|in:user,organization',
            'payer_id' => 'nullable|integer',
            'country' => 'required|string|size:2',
            'buyer_type' => 'required|in:individual,company',
            'billing_details' => 'required|array',
        ]);

        try {
            // Authorize organization admin/owner when payer_type is organization
            $authError = $this->authorizeOrganizationPayment(
                $request->payer_type,
                $request->payer_id,
                'json'
            );
            if ($authError) {
                return $authError;
            }

            $license = License::findOrFail($request->license_id);

            // Calculate billing amount (includes billing cycle multiplier)
            $pricing = $this->pricingCalculator->calculateBillingAmount(
                $license,
                $request->country,
                $request->billing_details['vat_id'] ?? null,
                $request->buyer_type === 'company'
            );

            // Create or update order
            $order = Order::updateOrCreate(
                [
                    'payer_type' => $request->payer_type,
                    'payer_id' => $request->payer_id,
                    'license_id' => $license->id,
                    'status' => OrderStatus::Initiated,
                ],
                [
                    'type' => $license->tier === 'premium' ? 'subscription' : 'onetime',
                    'currency' => $pricing['currency'],
                    'net_amount' => $pricing['net_amount'],
                    'tax_amount' => $pricing['tax_amount'],
                    'gross_amount' => $pricing['gross_amount'],
                    'country' => $request->country,
                    'vat_id' => $request->billing_details['vat_id'] ?? null,
                    'billing_snapshot' => $request->billing_details,
                    'meta' => [
                        'pricing_calculation' => $pricing,
                        'created_at' => now()->toISOString(),
                    ],
                ]
            );

            // Store order in session for checkout flow
            Session::put('checkout_order_id', $order->id);

            return response()->json([
                'success' => true,
                'order_id' => $order->id,
                'pricing' => $pricing,
            ]);

        } catch (\Exception $e) {
            Log::error('Order creation failed', [
                'error' => $e->getMessage(),
                'license_id' => $request->license_id,
                'payer_type' => $request->payer_type,
            ]);

            return response()->json([
                'success' => false,
                'error' => 'Failed to create order. Please try again.',
            ], 500);
        }
    }

    /**
     * Start payment flow - create order and redirect to Mollie
     */
    public function startPayment(Request $request)
    {
        $request->validate([
            'license_id' => 'required|exists:licenses,id',
            'payer_type' => 'required|in:user,organization',
            'payer_id' => 'nullable|integer',
            'country' => 'required|string|size:2',
            'buyer_type' => 'required|in:individual,company',
            'billing_details' => 'required|array',
            'payment_method' => 'nullable|string',
        ]);

        try {
            // Authorize organization admin/owner when payer_type is organization
            $authError = $this->authorizeOrganizationPayment(
                $request->payer_type,
                $request->payer_id,
                'redirect'
            );
            if ($authError) {
                return $authError;
            }

            $license = License::findOrFail($request->license_id);

            // Calculate billing amount (includes billing cycle multiplier)
            $pricing = $this->pricingCalculator->calculateBillingAmount(
                $license,
                $request->country,
                $request->billing_details['vat_id'] ?? null,
                $request->buyer_type === 'company'
            );

            // Resolve payment provider for this license
            $provider = $this->providers->for($license);

            // Create order with snapshot fields
            $order = Order::create([
                'payer_type' => $request->payer_type,
                'payer_id' => $request->payer_id,
                'license_id' => $license->id,
                'type' => $license->tier === 'premium' ? 'subscription' : 'onetime',
                'currency' => $pricing['currency'],
                'net_amount' => $pricing['net_amount'],
                'tax_amount' => $pricing['tax_amount'],
                'gross_amount' => $pricing['gross_amount'],
                'country' => $request->country,
                'vat_id' => $request->billing_details['vat_id'] ?? null,
                'status' => OrderStatus::Initiated,
                'payment_provider' => $provider->name(),
                'billing_snapshot' => $request->billing_details,
                'meta' => [
                    'license_code' => $license->code,
                    'credits_amount' => $license->credits,
                    'pricing_calculation' => $pricing,
                    'created_at' => now()->toISOString(),
                ],
            ]);

            // Check if this is an invoice payment
            $paymentMethod = $request->input('payment_method');

            Log::info('CheckoutController startPayment', [
                'payment_method' => $paymentMethod,
                'payment_provider' => $provider->name(),
                'payer_type' => $request->payer_type,
                'payer_id' => $request->payer_id,
                'order_id' => $order->id,
            ]);

            if ($paymentMethod === 'invoice' && $request->payer_type === 'organization') {
                Log::info('Invoice payment detected, calling handleInvoicePayment');

                return $this->handleInvoicePayment($order, $request->payer_id);
            }

            // Create payment via provider
            $result = $order->type === 'subscription'
                ? $provider->createSubscriptionCheckout($order, $order->billing_snapshot, $paymentMethod)
                : $provider->createCheckout($order, $order->billing_snapshot, $paymentMethod);

            // Update order with provider payment ID + customer ID
            $updateData = [
                'provider_payment_id' => $result['provider_payment_id'],
                'provider_customer_id' => $result['provider_customer_id'] ?? null,
                'meta' => array_merge($order->meta ?? [], [
                    'checkout_url' => $result['checkout_url'],
                    'payment_created_at' => now()->toISOString(),
                ]),
            ];

            // Mollie backward-compat: keep mollie_* columns filled for existing webhook handlers
            if ($provider->name() === 'mollie') {
                $updateData['mollie_payment_id'] = $result['provider_payment_id'];
                if (isset($result['provider_customer_id'])) {
                    $updateData['mollie_customer_id'] = $result['provider_customer_id'];
                }
            }

            $order->update($updateData);

            return redirect($result['checkout_url']);

        } catch (\RuntimeException $e) {
            // RuntimeException komt o.a. uit StripeCheckoutService bij ontbrekend
            // stripe_price_id — geef admin een specifieke melding ipv generieke fout.
            Log::error('Payment creation runtime error', [
                'error' => $e->getMessage(),
                'license_id' => $request->license_id,
                'payer_type' => $request->payer_type,
            ]);

            if (isset($order)) {
                $order->update(['status' => OrderStatus::Failed]);
            }

            $userMessage = str_contains($e->getMessage(), 'stripe_price_id')
                ? 'Payment configuration incomplete — administrator must sync Stripe prices. Please try again later or contact support.'
                : 'Failed to create payment. Please try again.';

            return redirect()->route('checkout')->with('error', $userMessage);
        } catch (\Exception $e) {
            Log::error('Payment creation failed', [
                'error' => $e->getMessage(),
                'license_id' => $request->license_id,
                'payer_type' => $request->payer_type,
            ]);

            if (isset($order)) {
                $order->update(['status' => OrderStatus::Failed]);
            }

            return redirect()->route('checkout')
                ->with('error', 'Failed to create payment. Please try again.');
        }
    }

    /**
     * Handle return from payment provider redirect
     */
    public function return(Request $request)
    {
        $orderUuid = $request->get('o');
        if (! $orderUuid) {
            return redirect()->route('pricing')->with('error', 'Order identifier missing');
        }

        $order = Order::find($orderUuid);
        if (! $order) {
            return redirect()->route('pricing')->with('error', 'Order not found');
        }

        // Log the return visit
        $order->update([
            'meta' => array_merge($order->meta ?? [], [
                'returned_at' => now()->toISOString(),
                'return_source' => 'payment_redirect',
                'payment_provider' => $order->payment_provider,
            ]),
        ]);

        // Show processing page with polling functionality
        // The frontend will poll /api/orders/{uuid} to check status
        return view('checkout.processing', [
            'order' => $order,
            'poll_url' => route('api.order.status', ['order' => $order->id]),
            'success_url' => route('activation', ['order' => $order->id]),
            'error_url' => route('checkout'),
        ]);
    }

    /**
     * Show activation/result page
     */
    public function activation(Request $request)
    {
        $orderId = $request->session()->get('order_id') ?: $request->get('order');
        $order = $orderId ? Order::find($orderId) : null;

        // Check for invoice pending status
        $invoicePending = $request->session()->get('invoice_pending', false);
        $invoiceNumber = $request->session()->get('invoice_number');

        return view('activation.wizard', [
            'order' => $order,
            'invoice_pending' => $invoicePending,
            'invoice_number' => $invoiceNumber,
        ]);
    }

    /**
     * Get order status for API polling
     */
    public function getOrderStatus(Request $request, string $orderId)
    {
        $order = Order::find($orderId);

        if (! $order) {
            return response()->json([
                'success' => false,
                'error' => 'Order not found',
            ], 404);
        }

        // Authorization check: only the order owner can view order status
        if (! $this->canAccessOrder($order)) {
            return response()->json([
                'success' => false,
                'error' => 'Unauthorized',
            ], 403);
        }

        // Check if we should sync with Mollie
        $shouldSync = $this->shouldSyncWithMollie($order);
        $paymentStatus = null;
        $paymentError = null;
        $synced = false;

        Log::debug('Order status API called', [
            'order_id' => $order->id,
            'order_status' => $order->status,
            'should_sync' => $shouldSync,
            'mollie_payment_id' => $order->mollie_payment_id,
            'age_seconds' => now()->diffInSeconds($order->created_at),
        ]);

        $isMollieOrder = ($order->payment_provider === 'mollie' || ($order->payment_provider === null && $order->mollie_payment_id));
        $isStripeOrder = $order->payment_provider === 'stripe';

        // Stripe polling: bij trage webhook arrival fallback naar Session::retrieve.
        // Identieke flow als Mollie maar via StripeCheckoutService → Session API.
        if ($isStripeOrder && $order->provider_payment_id && $shouldSync) {
            $stripeCheckout = app(\App\Services\Payments\StripeCheckoutService::class);
            $sessionResult = $stripeCheckout->retrieveSession($order->provider_payment_id);

            if ($sessionResult['success']) {
                $sessionData = $sessionResult['data'];
                $paymentStatus = $sessionData['payment_status'] ?? null;
                $synced = true;

                if ($paymentStatus === 'paid' && ! $order->isPaid()) {
                    Log::info('API polling found Stripe payment, syncing status', [
                        'order_id' => $order->id,
                        'stripe_payment_status' => $paymentStatus,
                        'db_status' => $order->status,
                    ]);

                    $order->update([
                        'status' => OrderStatus::Paid,
                        'paid_at' => now(),
                        'meta' => array_merge($order->meta ?? [], [
                            'api_sync_at' => now()->toISOString(),
                            'api_sync_reason' => 'stripe_polling_found_paid',
                        ]),
                    ]);

                    $order->refresh();

                    // Fallback fulfillment als webhook nog niet aankwam
                    if ($this->shouldTriggerFallbackFulfillment($order)) {
                        Log::info('Triggering fallback fulfillment via Stripe API polling', [
                            'order_id' => $order->id,
                        ]);

                        $fulfillmentSuccess = $this->fulfillmentService->fulfillOrder($order);

                        if ($fulfillmentSuccess) {
                            $order->update([
                                'meta' => array_merge($order->meta ?? [], [
                                    'fallback_fulfillment_at' => now()->toISOString(),
                                    'fallback_fulfillment_trigger' => 'stripe_api_polling',
                                ]),
                            ]);
                            $order->refresh();
                        }
                    }
                }
            } else {
                $paymentError = $sessionResult['error'] ?? 'Could not check Stripe session status';
            }
        }

        if ($isMollieOrder && $order->mollie_payment_id && $shouldSync) {
            $paymentResult = $this->molliePayment->getPayment($order->mollie_payment_id);

            if ($paymentResult['success']) {
                $paymentData = $paymentResult['data'];
                $paymentStatus = $paymentData['status'];
                $synced = true;

                // Sync status if Mollie shows paid but our DB is not yet updated
                if ($paymentStatus === 'paid' && ! $order->isPaid()) {
                    Log::info('API polling found paid payment, syncing status', [
                        'order_id' => $order->id,
                        'mollie_status' => $paymentStatus,
                        'db_status' => $order->status,
                    ]);

                    $order->update([
                        'status' => OrderStatus::Paid,
                        'paid_at' => now(),
                        'meta' => array_merge($order->meta ?? [], [
                            'api_sync_at' => now()->toISOString(),
                            'api_sync_reason' => 'polling_found_paid',
                        ]),
                    ]);

                    // Refresh order instance
                    $order->refresh();

                    // Fallback fulfillment if webhook didn't arrive
                    if ($this->shouldTriggerFallbackFulfillment($order)) {
                        Log::info('Triggering fallback fulfillment via API polling', [
                            'order_id' => $order->id,
                        ]);

                        $fulfillmentSuccess = $this->fulfillmentService->fulfillOrder($order);

                        if ($fulfillmentSuccess) {
                            $order->update([
                                'meta' => array_merge($order->meta ?? [], [
                                    'fallback_fulfillment_at' => now()->toISOString(),
                                    'fallback_fulfillment_trigger' => 'api_polling',
                                ]),
                            ]);
                            $order->refresh();
                        }
                    }
                }

                // Update other statuses if changed
                elseif ($this->shouldUpdateStatusFromMollie($order, $paymentStatus)) {
                    $mappedStatus = $this->mapMollieStatusToOrderStatus($paymentStatus);

                    Log::info('API polling found status change, syncing', [
                        'order_id' => $order->id,
                        'mollie_status' => $paymentStatus,
                        'mapped_status' => $mappedStatus,
                        'current_db_status' => $order->status,
                    ]);

                    $order->update([
                        'status' => $mappedStatus,
                        'meta' => array_merge($order->meta ?? [], [
                            'api_sync_at' => now()->toISOString(),
                            'api_sync_reason' => 'polling_status_change',
                        ]),
                    ]);

                    $order->refresh();
                }
            } else {
                $paymentError = $paymentResult['error'] ?? 'Could not check payment status';
            }
        }

        // Check for fallback fulfillment even if we didn't sync with Mollie
        // This handles cases where order is already marked as paid but webhook never arrived
        if ($order->isPaid() && $this->shouldTriggerFallbackFulfillment($order)) {
            Log::info('Triggering standalone fallback fulfillment check', [
                'order_id' => $order->id,
            ]);

            $fulfillmentSuccess = $this->fulfillmentService->fulfillOrder($order);

            if ($fulfillmentSuccess) {
                $order->update([
                    'meta' => array_merge($order->meta ?? [], [
                        'fallback_fulfillment_at' => now()->toISOString(),
                        'fallback_fulfillment_trigger' => 'standalone_check',
                    ]),
                ]);
                $order->refresh();
            }
        }

        return response()->json([
            'success' => true,
            'order' => [
                'id' => $order->id,
                'status' => $order->status,
                'mollie_payment_id' => $order->mollie_payment_id,
                'payment_status' => $paymentStatus,
                'payment_error' => $paymentError,
                'is_paid' => $order->isPaid(),
                'is_pending' => $order->isPending(),
                'is_failed' => $order->isFailed(),
                'created_at' => $order->created_at,
                'updated_at' => $order->updated_at,
                'synced_at' => $synced ? now() : null,
            ],
        ]);
    }

    /**
     * Determine if we should sync order status with Mollie
     */
    private function shouldSyncWithMollie(Order $order): bool
    {
        $isPaid = $order->isPaid();
        $isDefinitivelyFailed = in_array($order->status, ['Failed', 'Refunded', 'ChargedBack']);

        Log::debug('Sync check - step 1', [
            'order_id' => $order->id,
            'is_paid' => $isPaid,
            'is_definitively_failed' => $isDefinitivelyFailed,
            'status' => $order->status,
        ]);

        // Don't sync if already paid or definitively failed
        if ($isPaid || $isDefinitivelyFailed) {
            return false;
        }

        // Sync if order is still initiated/pending for more than 30 seconds
        $ageInSeconds = abs(now()->diffInSeconds($order->created_at));
        $isInitiatedOrPending = in_array($order->status, ['Initiated', 'Pending', 'initiated', 'pending']);
        $isOldEnough = $ageInSeconds > 30;

        Log::debug('Sync check - step 2', [
            'order_id' => $order->id,
            'age_seconds' => $ageInSeconds,
            'is_initiated_or_pending' => $isInitiatedOrPending,
            'is_old_enough' => $isOldEnough,
            'status' => $order->status,
            'should_sync_age' => $isInitiatedOrPending && $isOldEnough,
        ]);

        if ($isInitiatedOrPending && $isOldEnough) {
            return true;
        }

        // Also sync if we haven't synced recently (last sync > 60 seconds ago)
        $lastSync = $order->meta['api_sync_at'] ?? null;
        if ($lastSync) {
            $lastSyncTime = \Carbon\Carbon::parse($lastSync);
            $timeSinceLastSync = now()->diffInSeconds($lastSyncTime);

            Log::debug('Sync check - step 3', [
                'order_id' => $order->id,
                'last_sync' => $lastSync,
                'time_since_last_sync' => $timeSinceLastSync,
                'should_sync_time' => $timeSinceLastSync > 60,
            ]);

            return $timeSinceLastSync > 60;
        }

        Log::debug('Sync check - final result: false', ['order_id' => $order->id]);

        return false;
    }

    /**
     * Check if we should update order status based on Mollie status
     */
    private function shouldUpdateStatusFromMollie(Order $order, string $mollieStatus): bool
    {
        $mappedStatus = $this->mapMollieStatusToOrderStatus($mollieStatus);

        return $order->status !== $mappedStatus;
    }

    /**
     * Map Mollie payment status to Order status
     */
    private function mapMollieStatusToOrderStatus(string $mollieStatus): string
    {
        return match ($mollieStatus) {
            'paid' => 'Paid',
            'canceled' => 'Canceled',
            'expired' => 'Expired',
            'failed' => 'Failed',
            'refunded' => 'Refunded',
            'charged_back' => 'ChargedBack',
            'pending', 'open' => 'Pending',
            default => 'Initiated'
        };
    }

    /**
     * Determine if we should trigger fallback fulfillment
     */
    private function shouldTriggerFallbackFulfillment(Order $order): bool
    {
        // Don't trigger if order is not paid
        if (! $order->isPaid()) {
            return false;
        }

        // Don't trigger if webhook already processed
        $webhookProcessedAt = $order->meta['webhook_processed_at'] ?? null;
        if ($webhookProcessedAt) {
            return false; // Webhook fulfilled it
        }

        // Don't trigger if fulfillment already done
        $fulfillmentDone = $order->meta['fulfillment_done'] ?? false;
        if ($fulfillmentDone) {
            return false;
        }

        // Don't trigger if fallback already attempted
        $fallbackAttempted = $order->meta['fallback_fulfillment_at'] ?? null;
        if ($fallbackAttempted) {
            return false;
        }

        // CHANGED: Trigger immediately when paid status is detected via polling
        // No grace period needed - if we're polling and detect paid status, fulfill immediately
        // The webhook will be idempotent anyway (checks fulfillment_done flag)
        return true;
    }

    /**
     * Handle invoice payment - create organization license directly
     */
    private function handleInvoicePayment(Order $order, int $organizationId)
    {
        Log::info('HandleInvoicePayment called', [
            'order_id' => $order->id,
            'organization_id' => $organizationId,
            'license_id' => $order->license_id,
        ]);

        try {
            // Create organization license with invoice billing
            $organizationLicense = OrganizationLicense::createInvoiceLicense([
                'organization_id' => $organizationId,
                'license_id' => $order->license_id,
                'source' => 'checkout',
                'external_ref' => $order->id, // Use id instead of uuid since id IS the uuid
                'is_current' => true,
            ]);

            Log::info('Organization license created successfully', [
                'organization_license_id' => $organizationLicense->id,
                'invoice_number' => $organizationLicense->invoice_number,
            ]);

            // Update order status to invoice_requested
            $order->update([
                'status' => OrderStatus::InvoiceRequested,
                'meta' => array_merge($order->meta ?? [], [
                    'payment_provider' => 'invoice',
                    'invoice_license_id' => $organizationLicense->id,
                    'invoice_number' => $organizationLicense->invoice_number,
                    'invoice_due_date' => $organizationLicense->invoice_due_date,
                    'pending_created_at' => now()->toISOString(),
                ]),
            ]);

            Log::info('Invoice license created', [
                'order_id' => $order->id,
                'organization_id' => $organizationId,
                'license_id' => $order->license_id,
                'invoice_number' => $organizationLicense->invoice_number,
                'invoice_due_date' => $organizationLicense->invoice_due_date,
            ]);

            // Redirect to activation page with pending message
            Log::info('Redirecting to activation page', [
                'order_id' => $order->id,
                'invoice_number' => $organizationLicense->invoice_number,
            ]);

            return redirect()->route('activation', ['order' => $order->id])
                ->with('invoice_pending', true)
                ->with('invoice_number', $organizationLicense->invoice_number);

        } catch (\Exception $e) {
            Log::error('Invoice license creation failed', [
                'order_id' => $order->id,
                'organization_id' => $organizationId,
                'error' => $e->getMessage(),
            ]);

            // Mark order as failed and redirect back with error
            $order->update(['status' => OrderStatus::Failed]);

            return redirect()->route('checkout')
                ->with('error', 'Failed to create invoice license. Please try again.');
        }
    }

    /**
     * Check if the current user/session can access the given order.
     */
    private function canAccessOrder(Order $order): bool
    {
        $user = auth()->user();

        // User orders: must be the order owner
        if ($order->payer_type === 'user') {
            return $user && $order->payer_id === $user->id;
        }

        // Organization orders: must be a member of the organization
        if ($order->payer_type === 'organization') {
            if (! $user) {
                return false;
            }

            $organization = \App\Models\Organization::find($order->payer_id);
            if (! $organization) {
                return false;
            }

            return $organization->users()->where('users.id', $user->id)->exists();
        }

        // Guest orders (payer_id is null): allow access via session
        // This allows guests to poll their own order after checkout
        if ($order->payer_id === null) {
            $sessionOrderId = Session::get('checkout_order_id');

            return $sessionOrderId === $order->id;
        }

        return false;
    }
}
