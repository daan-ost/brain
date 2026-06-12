<?php

namespace App\Jobs;

use App\Enums\OrganizationRole;
use App\Models\Order;
use App\Models\User;
use App\Services\LocaleService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class SendInvoiceEmail implements ShouldQueue
{
    use Queueable;

    /**
     * The number of times the job may be attempted.
     */
    public int $tries = 3;

    /**
     * The number of seconds to wait before retrying the job.
     */
    public int $backoff = 60;

    public function __construct(
        private Order $order
    ) {
        $this->onQueue('default');
    }

    public function handle(): void
    {
        Log::info('SendInvoiceEmail job started', [
            'order_id' => $this->order->id,
            'invoice_number' => $this->order->invoice_number,
        ]);

        // Validate that invoice exists
        if (! $this->order->invoice_number || ! $this->order->invoice_file_path) {
            Log::error('Cannot send invoice email - invoice not generated', [
                'order_id' => $this->order->id,
            ]);

            return;
        }

        // Get recipients based on order type
        $recipients = $this->getRecipients();

        if (empty($recipients)) {
            Log::warning('No recipients found for invoice email', [
                'order_id' => $this->order->id,
                'payer_type' => $this->order->payer_type,
            ]);

            return;
        }

        // Load PDF from storage
        $pdfContent = Storage::get($this->order->invoice_file_path);
        if (! $pdfContent) {
            Log::error('Invoice PDF file not found in storage', [
                'order_id' => $this->order->id,
                'file_path' => $this->order->invoice_file_path,
            ]);

            return;
        }

        // Prepare PDF attachment for Postmark
        $attachment = [
            'Name' => $this->order->invoice_number.'.pdf',
            'Content' => base64_encode($pdfContent),
            'ContentType' => 'application/pdf',
        ];

        foreach ($recipients as $i => $recipient) {
            $this->sendToRecipient($recipient, $attachment, $i === array_key_first($recipients));
        }

        Log::info('Invoice emails dispatched', [
            'order_id' => $this->order->id,
            'recipient_count' => count($recipients),
        ]);
    }

    /**
     * Get recipients for the invoice email
     */
    private function getRecipients(): array
    {
        $recipients = [];
        $emailsSent = []; // Track emails to avoid duplicates

        // Always include billing email from billing_snapshot if available
        $billingSnapshot = $this->order->billing_snapshot ?? [];
        $billingEmail = $billingSnapshot['email'] ?? null;

        if ($billingEmail && filter_var($billingEmail, FILTER_VALIDATE_EMAIL)) {
            $recipients[] = [
                'email' => $billingEmail,
                'name' => $billingSnapshot['company_name'] ?? $billingSnapshot['full_name'] ?? 'Customer',
                'locale' => null, // Will use fallback
            ];
            $emailsSent[] = strtolower($billingEmail);
        }

        // For organization orders, also send to admins (if not already in billing email)
        if ($this->order->payer_type === 'organization' && $this->order->payer_id) {
            $organization = \App\Models\Organization::find($this->order->payer_id);

            if ($organization) {
                $admins = $organization->users()
                    ->wherePivot('role', OrganizationRole::Owner)
                    ->get();

                foreach ($admins as $admin) {
                    if (! in_array(strtolower($admin->email), $emailsSent)) {
                        $recipients[] = [
                            'email' => $admin->email,
                            'name' => $admin->name,
                            'locale' => $admin->preferred_language ?? config('app.locale', 'en'),
                        ];
                        $emailsSent[] = strtolower($admin->email);
                    }
                }
            }
        }

        // For user orders, also send to the payer (if not already billing email)
        if ($this->order->payer_type === 'user' && $this->order->payer_id) {
            $user = User::find($this->order->payer_id);

            if ($user && ! in_array(strtolower($user->email), $emailsSent)) {
                $recipients[] = [
                    'email' => $user->email,
                    'name' => $user->name,
                    'locale' => $user->preferred_language ?? config('app.locale', 'en'),
                ];
                $emailsSent[] = strtolower($user->email);
            }
        }

        return $recipients;
    }

    /**
     * Send invoice email to a single recipient
     */
    private function sendToRecipient(array $recipient, array $attachment, bool $primary = false): void
    {
        // Determine locale for this recipient
        $locale = $recipient['locale'] ?? config('app.locale', 'en');
        $templateAlias = "invoice-notification__{$locale}";

        // Prepare recipient name
        $recipientName = $this->getRecipientName($recipient);

        // Format invoice data for email template
        $templateModel = $this->prepareTemplateModel($recipientName, $locale);

        $bcc = ($primary && config('services.trustpilot.afs_email'))
            ? config('services.trustpilot.afs_email')
            : null;

        // Dispatch email via SendPostmarkTemplateEmail
        SendPostmarkTemplateEmail::dispatch(
            templateAlias: $templateAlias,
            templateModel: $templateModel,
            to: $recipient['email'],
            toName: $recipientName,
            tag: 'invoice-notification',
            messageStream: 'outbound',
            attachments: [$attachment],
            bcc: $bcc
        );

        Log::info('Invoice email dispatched to recipient', [
            'order_id' => $this->order->id,
            'recipient_email' => $recipient['email'],
            'locale' => $locale,
        ]);
    }

    /**
     * Get recipient name from user data
     */
    private function getRecipientName(array $recipient): string
    {
        // For organizations, check billing snapshot for company name
        if ($this->order->payer_type === 'organization') {
            $billingSnapshot = $this->order->billing_snapshot ?? [];
            if (! empty($billingSnapshot['company_name'])) {
                return $billingSnapshot['company_name'];
            }
        }

        // Use user's name
        return $recipient['name'] ?? 'Customer';
    }

    /**
     * Prepare template model data for email.
     *
     * Locale-aware formatting: resolves the order's user via LocaleService
     * to format dates and amounts according to their preferences. This works
     * correctly in queue context (no auth user) because the user is resolved
     * from the order's payer, not from auth().
     */
    private function prepareTemplateModel(string $recipientName, string $locale): array
    {
        $license = $this->order->license;
        $planName = $license
            ? ($license->name ?? __('licenses.'.$license->slug, [], $locale))
            : __('invoice.license_purchase', [], $locale);

        // Resolve user from order payer for locale-aware formatting (not auth user)
        $invoiceUser = $this->resolveOrderUser();
        $localeService = app(LocaleService::class);

        // Format date and amount using the payer's locale preferences
        $invoiceDate = $localeService->formatDate(
            $this->order->invoice_date ? \Carbon\Carbon::parse($this->order->invoice_date) : now(),
            $invoiceUser
        );

        $totalAmount = $localeService->formatCurrency($this->order->gross_amount, $invoiceUser, strtoupper($this->order->currency));

        // Payment status
        $paymentStatus = $this->order->isPaid()
            ? ($locale === 'nl' ? 'Betaald' : 'Paid')
            : ($locale === 'nl' ? 'In Behandeling' : 'Pending');

        // Download URL
        $downloadUrl = route('profile.invoices.download', $this->order->id);

        return [
            'recipient_name' => $recipientName,
            'invoice_number' => $this->order->invoice_number,
            'invoice_date' => $invoiceDate,
            'plan_name' => $planName,
            'total_amount' => $totalAmount,
            'payment_status' => $paymentStatus,
            'download_url' => $downloadUrl,
            'support_email' => config('invoice.company_email', config('mail.from.address')),
            'company_name' => config('invoice.company_name', config('app.name')),
        ];
    }

    /**
     * Resolve the user associated with the order for locale-aware formatting.
     * Delegates to LocaleService::resolveUserForOrder() (single source of truth).
     */
    private function resolveOrderUser(): ?User
    {
        return LocaleService::resolveUserForOrder($this->order);
    }

    /**
     * Handle a job failure.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error('SendInvoiceEmail job failed permanently', [
            'order_id' => $this->order->id,
            'invoice_number' => $this->order->invoice_number,
            'error' => $exception->getMessage(),
        ]);
    }
}
