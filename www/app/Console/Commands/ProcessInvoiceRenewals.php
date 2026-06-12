<?php

namespace App\Console\Commands;

use App\Enums\OrganizationRole;
use App\Jobs\SendPostmarkTemplateEmail;
use App\Models\LicenseNotification;
use App\Models\Order;
use App\Models\OrganizationCreditLedger;
use App\Models\OrganizationLicense;
use App\Services\InvoiceGenerationService;
use App\Services\LicenseRenewalService;
use App\Services\LocaleService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class ProcessInvoiceRenewals extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'license:process-invoice-renewals
                            {--dry-run : Run without creating orders or sending emails}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Process invoice-based license renewals at renewal date - creates new license and sends invoice';

    private LicenseRenewalService $renewalService;

    private InvoiceGenerationService $invoiceService;

    public function __construct(
        LicenseRenewalService $renewalService,
        InvoiceGenerationService $invoiceService
    ) {
        parent::__construct();
        $this->renewalService = $renewalService;
        $this->invoiceService = $invoiceService;
    }

    /**
     * Execute the console command.
     *
     * Flow for invoice-based subscriptions:
     * 1. On renewal date: Generate invoice + create new license
     * 2. If organization is_trusted: New license is immediately active
     * 3. If organization NOT trusted: New license is pending until payment
     * 4. Old license expires naturally (ends_at)
     */
    public function handle(): int
    {
        $dryRun = $this->option('dry-run');

        if ($dryRun) {
            $this->info('Running in dry-run mode - no changes will be made');
        }

        $this->info('Processing invoice-based license renewals (at renewal date)...');

        $stats = [
            'renewals_processed' => 0,
            'trusted_activated' => 0,
            'pending_payment' => 0,
            'skipped_already_renewed' => 0,
            'skipped_not_due' => 0,
            'errors' => 0,
        ];

        // Process organization licenses with invoice billing
        $this->processOrganizationInvoiceRenewals($stats, $dryRun);

        // Output summary
        $this->newLine();
        $this->info('=== Invoice Renewals Complete ===');
        $this->table(
            ['Action', 'Count'],
            [
                ['Renewals processed', $stats['renewals_processed']],
                ['Trusted (activated)', $stats['trusted_activated']],
                ['Pending payment', $stats['pending_payment']],
                ['Skipped (already renewed)', $stats['skipped_already_renewed']],
                ['Skipped (not due)', $stats['skipped_not_due']],
                ['Errors', $stats['errors']],
            ]
        );

        Log::info('Invoice renewal processing completed', $stats);

        return Command::SUCCESS;
    }

    /**
     * Process organization invoice-based license renewals
     */
    private function processOrganizationInvoiceRenewals(array &$stats, bool $dryRun): void
    {
        $this->info('Processing organization invoice licenses...');

        // Get all active invoice-based organization licenses with recurring billing
        $licenses = OrganizationLicense::with(['license', 'organization'])
            ->where('status', 'active')
            ->where('billing_method', 'invoice')
            ->whereHas('license', function ($q) {
                $q->where('active', true)
                    ->whereIn('billing_cycle', ['monthly', 'yearly', '6month']);
            })
            ->get();

        $this->output->progressStart($licenses->count());

        foreach ($licenses as $license) {
            try {
                $this->processOrganizationLicense($license, $stats, $dryRun);
            } catch (\Exception $e) {
                $stats['errors']++;
                Log::error('Error processing invoice renewal', [
                    'license_id' => $license->id,
                    'organization_id' => $license->organization_id,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);
                $this->error("Error processing license {$license->id}: {$e->getMessage()}");
            }

            $this->output->progressAdvance();
        }

        $this->output->progressFinish();
    }

    /**
     * Process a single organization license for renewal
     */
    private function processOrganizationLicense(
        OrganizationLicense $license,
        array &$stats,
        bool $dryRun
    ): void {
        // Calculate the most recent renewal date (when current billing period started)
        $billingCycle = $license->license->billing_cycle;
        $previousRenewalDate = $this->renewalService->getPreviousRenewalDate(
            $license->starts_at,
            $billingCycle
        );

        if (! $previousRenewalDate) {
            $stats['skipped_not_due']++;

            return;
        }

        // Calculate how many days ago the renewal date was
        // Note: diffInDays returns positive when the target is after the source
        // So we calculate: now - previousRenewal (positive when previousRenewal is in the past)
        $daysSinceRenewal = $previousRenewalDate->startOfDay()->diffInDays(now()->startOfDay(), false);

        // Only process if renewal date is today (daysSinceRenewal = 0) or up to 7 days ago (catch-up)
        // daysSinceRenewal is positive when previousRenewalDate is in the past
        if ($daysSinceRenewal < 0) {
            // Renewal date is in the future - not due yet
            $stats['skipped_not_due']++;

            return;
        }

        if ($daysSinceRenewal > 7) {
            // More than 7 days past - too old, skip
            $stats['skipped_not_due']++;

            return;
        }

        // Check if we already processed this renewal (prevent duplicates)
        $notificationType = LicenseNotification::TYPE_INVOICE_RENEWAL_30_DAYS; // Reuse for "renewal processed"
        if (LicenseNotification::wasRecentlySent(null, $license->id, $notificationType, 25)) {
            $stats['skipped_already_renewed']++;

            return;
        }

        // Process the renewal
        $organization = $license->organization;
        $licenseModel = $license->license;

        $this->info("Processing renewal for {$organization->name} - {$licenseModel->name} (renewal date: {$previousRenewalDate->format('Y-m-d')})");

        if (! $dryRun) {
            DB::transaction(function () use ($license, $organization, $licenseModel, $previousRenewalDate, $notificationType, &$stats) {
                // 1. Create renewal order
                $order = $this->createRenewalOrder($license, $organization, $licenseModel, $previousRenewalDate);

                // 2. Create new license for next period
                $newLicense = $this->createRenewalLicense($license, $organization, $licenseModel, $previousRenewalDate);

                // 2b. Reset credits for trusted organizations (they get immediate activation)
                if ($organization->is_trusted) {
                    $this->resetOrganizationCredits($organization, $licenseModel);
                }

                // 3. Generate invoice PDF
                $this->invoiceService->generateInvoice($order);

                // 4. Send invoice email
                $this->sendInvoiceEmail($order, $organization, $licenseModel, $newLicense);

                // 5. Record notification sent (prevents duplicate processing)
                LicenseNotification::recordSent(null, $license->id, $notificationType);

                // 6. Update stats
                if ($organization->is_trusted) {
                    $stats['trusted_activated']++;
                } else {
                    $stats['pending_payment']++;
                }

                Log::info('Invoice renewal processed', [
                    'old_license_id' => $license->id,
                    'new_license_id' => $newLicense->id,
                    'organization_id' => $organization->id,
                    'order_id' => $order->id,
                    'invoice_number' => $order->invoice_number,
                    'is_trusted' => $organization->is_trusted,
                    'new_license_status' => $newLicense->status,
                ]);
            });
        } else {
            // Dry run - just count
            if ($organization->is_trusted) {
                $stats['trusted_activated']++;
            } else {
                $stats['pending_payment']++;
            }
        }

        $stats['renewals_processed']++;
    }

    /**
     * Create new license for the renewal period
     */
    private function createRenewalLicense(
        OrganizationLicense $oldLicense,
        $organization,
        $licenseModel,
        Carbon $renewalDate
    ): OrganizationLicense {
        // Calculate new period dates
        $billingCycle = $licenseModel->billing_cycle;
        $newStartsAt = $renewalDate;
        $newEndsAt = $this->renewalService->getNextRenewalDate($renewalDate, $billingCycle);

        // Determine status based on trust level
        // Trusted organizations get immediate activation
        // Non-trusted must wait for payment
        $status = $organization->is_trusted ? 'active' : 'pending';

        // Create new license
        $newLicense = OrganizationLicense::create([
            'organization_id' => $organization->id,
            'license_id' => $licenseModel->id,
            'status' => $status,
            'starts_at' => $newStartsAt,
            'ends_at' => $newEndsAt,
            'billing_method' => 'invoice',
            'payment_status' => 'unpaid',
            'source' => 'invoice_renewal',
            'is_current' => $organization->is_trusted, // Only trusted orgs get this as current immediately
        ]);

        // If trusted and activated, set old license as not current
        if ($organization->is_trusted) {
            $oldLicense->update(['is_current' => false]);
        }

        return $newLicense;
    }

    /**
     * Create renewal order for invoice
     */
    private function createRenewalOrder(
        OrganizationLicense $license,
        $organization,
        $licenseModel,
        Carbon $renewalDate
    ): Order {
        // Get billing snapshot from organization or previous order
        $billingSnapshot = $this->getBillingSnapshot($organization, $license);

        // Round gross to whole number; derive tax from gross - net so net + tax = gross exactly
        $netAmount = $licenseModel->amount;
        $vatRate = $billingSnapshot['vat_rate'] ?? config('invoice.default_vat_rate', 21);
        if ($vatRate > 0) {
            $grossAmount = (float) round($netAmount * (1 + $vatRate / 100));
            $vatAmount = round($grossAmount - $netAmount, 2);
        } else {
            $vatAmount = 0.0;
            $grossAmount = $netAmount;
        }

        // Create order
        $order = Order::create([
            'payer_type' => 'organization',
            'payer_id' => $organization->id,
            'license_id' => $licenseModel->id,
            'currency' => $licenseModel->currency ?? 'EUR',
            'net_amount' => $netAmount,
            'tax_amount' => $vatAmount,
            'gross_amount' => $grossAmount,
            'country' => $billingSnapshot['country'] ?? 'NL',
            'status' => 'pending',
            'billing_snapshot' => $billingSnapshot,
            'meta' => [
                'type' => 'invoice_renewal',
                'renewal_date' => $renewalDate->toDateString(),
                'original_license_id' => $license->id,
                'vat_rate' => $vatRate,
            ],
        ]);

        return $order;
    }

    /**
     * Get billing snapshot for invoice
     */
    private function getBillingSnapshot($organization, OrganizationLicense $license): array
    {
        // Try to get from previous order
        $previousOrder = Order::where('payer_type', 'organization')
            ->where('payer_id', $organization->id)
            ->whereNotNull('billing_snapshot')
            ->orderBy('created_at', 'desc')
            ->first();

        if ($previousOrder && ! empty($previousOrder->billing_snapshot)) {
            return $previousOrder->billing_snapshot;
        }

        // Fallback to organization data
        $admin = $organization->users()
            ->wherePivot('role', OrganizationRole::Owner)
            ->first();

        return [
            'company_name' => $organization->name,
            'full_name' => $admin?->name ?? $organization->name,
            'email' => $admin?->email ?? '',
            'address' => $organization->address ?? '',
            'city' => $organization->city ?? '',
            'postal_code' => $organization->postal_code ?? '',
            'country' => $organization->country ?? 'NL',
            'vat_number' => $organization->vat_number ?? '',
            'vat_rate' => $organization->vat_number ? 0 : config('invoice.default_vat_rate', 21),
        ];
    }

    /**
     * Send invoice email via Postmark
     */
    private function sendInvoiceEmail(
        Order $order,
        $organization,
        $licenseModel,
        OrganizationLicense $newLicense
    ): void {
        // Get admin users to send to
        $admins = $organization->users()
            ->wherePivot('role', OrganizationRole::Owner)
            ->get();

        if ($admins->isEmpty()) {
            Log::warning('No admins found for organization', [
                'organization_id' => $organization->id,
            ]);

            return;
        }

        // Load PDF if available
        $attachment = null;
        if ($order->invoice_file_path && Storage::exists($order->invoice_file_path)) {
            $pdfContent = Storage::get($order->invoice_file_path);
            $attachment = [
                'Name' => $order->invoice_number.'.pdf',
                'Content' => base64_encode($pdfContent),
                'ContentType' => 'application/pdf',
            ];
        }

        foreach ($admins as $admin) {
            $locale = $admin->locale ?? 'en';
            $templateAlias = "invoice-pending-payment__{$locale}";

            // Due date is 14 days from now
            $dueDate = now()->addDays(14);

            // Format amount and date using the admin's locale preferences.
            // In console commands, there is no auth user — pass $admin explicitly.
            $templateModel = [
                'recipient_name' => $admin->name,
                'organization_name' => $organization->name,
                'invoice_number' => $order->invoice_number,
                'license_name' => $licenseModel->name,
                'total_amount' => app(LocaleService::class)->formatCurrency($order->gross_amount, $admin, strtoupper($order->currency)),
                'due_date' => app(LocaleService::class)->formatDate($dueDate, $admin),
                'company_name' => config('invoice.company_name', config('app.name')),
            ];

            $attachments = $attachment ? [$attachment] : [];

            SendPostmarkTemplateEmail::dispatch(
                templateAlias: $templateAlias,
                templateModel: $templateModel,
                to: $admin->email,
                toName: $admin->name,
                tag: 'invoice-renewal',
                messageStream: 'outbound',
                attachments: $attachments
            );

            Log::info('Invoice renewal email dispatched', [
                'order_id' => $order->id,
                'recipient' => $admin->email,
                'is_trusted' => $organization->is_trusted,
            ]);
        }
    }

    /**
     * Reset organization credits to license amount
     */
    private function resetOrganizationCredits($organization, $licenseModel): void
    {
        $creditPool = $organization->creditPool;
        $previousBalance = $creditPool?->balance_credits ?? 0;
        $newBalance = $licenseModel->credits;

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
        ]);

        // Update last_credit_reset_at to prevent duplicate reset by hourly cronjob
        $currentLicense = $organization->organizationLicenses()
            ->where('status', 'active')
            ->where('is_current', true)
            ->first();

        if ($currentLicense) {
            $currentLicense->update(['last_credit_reset_at' => now()]);
        }

        Log::info('Organization credits reset for invoice renewal', [
            'organization_id' => $organization->id,
            'previous_balance' => $previousBalance,
            'new_balance' => $newBalance,
            'last_credit_reset_at_updated' => $currentLicense ? true : false,
        ]);
    }
}
