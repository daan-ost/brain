<?php

namespace App\Services;

use App\Jobs\SendPostmarkTemplateEmail;
use App\Models\Order;
use App\Models\Organization;
use App\Models\OrganizationCreditLedger;
use App\Models\OrganizationLicense;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class OrganizationLicenseRenewalService
{
    /**
     * Process renewal for an organization invoice license
     *
     * For trusted orgs: license becomes active, credits reset immediately
     * For non-trusted orgs: license becomes pending, credits preserved until payment
     */
    public function processRenewal(OrganizationLicense $currentLicense): ?OrganizationLicense
    {
        $organization = $currentLicense->organization;
        $isTrusted = $organization->is_trusted;

        Log::info('Processing organization invoice renewal', [
            'organization_id' => $organization->id,
            'organization_name' => $organization->name,
            'current_license_id' => $currentLicense->id,
            'is_trusted' => $isTrusted,
        ]);

        return DB::transaction(function () use ($currentLicense, $organization, $isTrusted) {
            // 1. Expire the current license
            $currentLicense->update([
                'status' => 'expired',
                'is_current' => false,
            ]);

            // 2. Create new license
            $newLicense = OrganizationLicense::create([
                'organization_id' => $organization->id,
                'license_id' => $currentLicense->license_id,
                'status' => $isTrusted ? 'active' : 'pending',
                'billing_method' => 'invoice',
                'payment_status' => 'unpaid',
                'starts_at' => now(),
                'ends_at' => null, // Premium recurring has no end date
                'is_current' => true,
                'invoice_number' => OrganizationLicense::generateInvoiceNumber(),
                'invoice_due_date' => now()->addDays((int) config('app.invoice_default_due_days', 14)),
                'source' => 'renewal',
                'external_ref' => null, // Will be set when Order is created
                'last_credit_reset_at' => $isTrusted ? now() : null, // Prevent duplicate reset by cronjob
            ]);

            // 3. Reset credits only for trusted organizations
            if ($isTrusted) {
                $this->resetOrganizationCredits($organization, $currentLicense->license);
            }

            Log::info('Organization renewal license created', [
                'organization_id' => $organization->id,
                'new_license_id' => $newLicense->id,
                'status' => $newLicense->status,
                'invoice_number' => $newLicense->invoice_number,
            ]);

            return $newLicense;
        });
    }

    /**
     * Activate a pending license after payment confirmation
     * This is called when admin marks invoice as paid
     */
    public function activatePendingLicense(OrganizationLicense $license): bool
    {
        if ($license->status !== 'pending') {
            Log::warning('Attempted to activate non-pending license', [
                'license_id' => $license->id,
                'current_status' => $license->status,
            ]);

            return false;
        }

        return DB::transaction(function () use ($license) {
            $organization = $license->organization;

            // 1. Activate the license
            $license->update([
                'status' => 'active',
                'payment_status' => 'paid',
                'paid_at' => now(),
                'is_current' => true,
                'last_credit_reset_at' => now(), // Prevent duplicate reset by cronjob
            ]);

            // 2. Now reset credits (was deferred until payment)
            $this->resetOrganizationCredits($organization, $license->license);

            // 3. Send activation email
            $this->sendActivationEmail($license);

            Log::info('Pending license activated after payment', [
                'organization_id' => $organization->id,
                'license_id' => $license->id,
            ]);

            return true;
        });
    }

    /**
     * Reset organization credits to license amount
     */
    private function resetOrganizationCredits(Organization $organization, $license): void
    {
        $creditPool = $organization->creditPool;
        $previousBalance = $creditPool ? $creditPool->balance_credits : 0;
        $newBalance = $license->credits;

        // Update or create credit pool
        $organization->creditPool()->updateOrCreate(
            ['organization_id' => $organization->id],
            ['balance_credits' => $newBalance, 'updated_at' => now()]
        );

        // Create ledger entry
        OrganizationCreditLedger::create([
            'organization_id' => $organization->id,
            'delta' => $newBalance - $previousBalance,
            'reason' => 'reset_renewal',
            'balance_after' => $newBalance,
            'meta' => [
                'license_tier' => 'premium',
                'previous_balance' => $previousBalance,
                'reset_to' => $newBalance,
                'source' => 'invoice_renewal',
            ],
            'created_at' => now(),
        ]);

        Log::info('Organization credits reset for renewal', [
            'organization_id' => $organization->id,
            'previous_balance' => $previousBalance,
            'new_balance' => $newBalance,
        ]);
    }

    /**
     * Send activation email to all admins and billing email
     */
    public function sendActivationEmail(OrganizationLicense $license): void
    {
        $organization = $license->organization;
        $admins = $organization->admins;
        $emailsSent = [];

        // 1. Get billing email from previous paid order (if available)
        $previousOrder = Order::where('payer_type', 'organization')
            ->where('payer_id', $organization->id)
            ->where('status', 'paid')
            ->orderBy('paid_at', 'desc')
            ->first();

        $billingEmail = $previousOrder?->billing_snapshot['email'] ?? null;

        // Send to billing email first
        if ($billingEmail && filter_var($billingEmail, FILTER_VALIDATE_EMAIL)) {
            $billingName = $previousOrder->billing_snapshot['company_name']
                ?? $previousOrder->billing_snapshot['full_name']
                ?? $organization->name;

            $this->dispatchActivationEmail($billingEmail, $billingName, $license, 'en', null);
            $emailsSent[] = strtolower($billingEmail);

            Log::info('Activation email sent to billing email', [
                'license_id' => $license->id,
                'email' => $billingEmail,
            ]);
        }

        // 2. Send to all admins (except if already sent)
        foreach ($admins as $admin) {
            if (! in_array(strtolower($admin->email), $emailsSent)) {
                $locale = $admin->preferred_language ?? 'en';
                $this->dispatchActivationEmail($admin->email, $admin->name, $license, $locale, $admin);
                $emailsSent[] = strtolower($admin->email);

                Log::info('Activation email sent to admin', [
                    'license_id' => $license->id,
                    'admin_id' => $admin->id,
                    'email' => $admin->email,
                ]);
            }
        }
    }

    /**
     * Dispatch activation email to a single recipient.
     *
     * Uses locale-aware date formatting via LocaleService.
     * When $user is null (e.g. billing email recipient), falls back to system defaults.
     */
    private function dispatchActivationEmail(
        string $email,
        string $name,
        OrganizationLicense $license,
        string $locale = 'en',
        ?\App\Models\User $user = null
    ): void {
        $localeService = app(LocaleService::class);

        SendPostmarkTemplateEmail::dispatch(
            templateAlias: "license-activated__{$locale}",
            templateModel: [
                'recipient_name' => $name,
                'organization_name' => $license->organization->name,
                'license_name' => $license->license->name,
                'credits' => number_format($license->license->credits),
                'activation_date' => $localeService->formatDate(now(), $user),
                'company_name' => config('invoice.company_name', config('app.name')),
            ],
            to: $email,
            toName: $name,
            tag: 'license-activated',
            messageStream: 'outbound'
        );
    }

    /**
     * Send invoice email for renewal (pending payment for non-trusted)
     */
    public function sendInvoicePendingPaymentEmail(OrganizationLicense $license, string $invoicePath): void
    {
        $organization = $license->organization;
        $admins = $organization->admins;
        $emailsSent = [];

        // Load PDF from storage
        $pdfContent = \Storage::get($invoicePath);
        if (! $pdfContent) {
            Log::error('Invoice PDF not found for pending payment email', [
                'license_id' => $license->id,
                'invoice_path' => $invoicePath,
            ]);

            return;
        }

        $attachment = [
            'Name' => $license->invoice_number.'.pdf',
            'Content' => base64_encode($pdfContent),
            'ContentType' => 'application/pdf',
        ];

        // 1. Get billing email from previous orders
        $previousOrder = Order::where('payer_type', 'organization')
            ->where('payer_id', $organization->id)
            ->where('status', 'paid')
            ->orderBy('paid_at', 'desc')
            ->first();

        $billingEmail = $previousOrder?->billing_snapshot['email'] ?? null;

        if ($billingEmail && filter_var($billingEmail, FILTER_VALIDATE_EMAIL)) {
            $billingName = $previousOrder->billing_snapshot['company_name']
                ?? $previousOrder->billing_snapshot['full_name']
                ?? $organization->name;

            $this->dispatchInvoicePendingEmail($billingEmail, $billingName, $license, $attachment, 'en', null);
            $emailsSent[] = strtolower($billingEmail);
        }

        // 2. Send to all admins
        foreach ($admins as $admin) {
            if (! in_array(strtolower($admin->email), $emailsSent)) {
                $locale = $admin->preferred_language ?? 'en';
                $this->dispatchInvoicePendingEmail($admin->email, $admin->name, $license, $attachment, $locale, $admin);
                $emailsSent[] = strtolower($admin->email);
            }
        }

        Log::info('Invoice pending payment emails sent', [
            'license_id' => $license->id,
            'recipient_count' => count($emailsSent),
        ]);
    }

    /**
     * Dispatch invoice pending payment email
     */
    /**
     * Dispatch invoice pending payment email to a single recipient.
     *
     * Uses locale-aware formatting for amount and date via LocaleService.
     * Currency is derived from the license definition (not hardcoded).
     */
    private function dispatchInvoicePendingEmail(
        string $email,
        string $name,
        OrganizationLicense $license,
        array $attachment,
        string $locale = 'en',
        ?\App\Models\User $user = null
    ): void {
        $localeService = app(LocaleService::class);
        // Use currency from the license definition, fall back to purchase currency or EUR
        $currency = $license->license->currency ?? $license->currency_at_purchase ?? 'EUR';

        SendPostmarkTemplateEmail::dispatch(
            templateAlias: "invoice-pending-payment__{$locale}",
            templateModel: [
                'recipient_name' => $name,
                'organization_name' => $license->organization->name,
                'invoice_number' => $license->invoice_number,
                'license_name' => $license->license->name,
                'total_amount' => $localeService->formatCurrency($license->license->amount, $user, $currency),
                'due_date' => $localeService->formatDate($license->invoice_due_date, $user),
                'company_name' => config('invoice.company_name', config('app.name')),
            ],
            to: $email,
            toName: $name,
            tag: 'invoice-pending-payment',
            messageStream: 'outbound',
            attachments: [$attachment]
        );
    }
}
