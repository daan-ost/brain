<?php

namespace App\Services;

use App\Enums\OrganizationRole;
use App\Jobs\SendInvoiceEmail;
use App\Models\Order;
use App\Models\Organization;
use App\Models\User;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class InvoiceGenerationService
{
    /**
     * Generate invoice for an order
     *
     * @param  Order  $order  The order to generate an invoice for
     * @param  bool  $sendEmail  Whether to send the invoice email notification
     */
    public function generateInvoice(Order $order, bool $sendEmail = true): array
    {
        Log::info('Starting invoice generation', [
            'order_id' => $order->id,
            'order_status' => $order->status,
        ]);

        // Check if invoice already exists in Order table
        if ($order->invoice_number && $order->invoice_file_path) {
            Log::info('Invoice already exists in Order table', [
                'invoice_number' => $order->invoice_number,
                'invoice_file_path' => $order->invoice_file_path,
            ]);

            return [
                'invoice_number' => $order->invoice_number,
                'invoice_file_path' => $order->invoice_file_path,
                'invoice_date' => $order->invoice_date,
                'already_exists' => true,
            ];
        }

        // Determine invoice number (might already exist or need to be generated)
        $invoiceNumber = null;

        // If invoice_number exists but file path is missing, reuse the number and generate the file
        if ($order->invoice_number) {
            Log::info('Invoice number exists but file_path missing, reusing number and generating PDF', [
                'invoice_number' => $order->invoice_number,
            ]);
            $invoiceNumber = $order->invoice_number;
        }
        // For invoice payments, check if invoice exists in OrganizationLicense
        elseif (isset($order->meta['invoice_license_id'])) {
            $orgLicense = \App\Models\OrganizationLicense::find($order->meta['invoice_license_id']);
            if ($orgLicense && $orgLicense->invoice_number) {
                Log::info('Invoice already exists in OrganizationLicense', [
                    'invoice_number' => $orgLicense->invoice_number,
                    'org_license_id' => $orgLicense->id,
                ]);

                // Use the existing invoice number from OrganizationLicense
                $invoiceNumber = $orgLicense->invoice_number;
            } else {
                // Generate unique invoice number
                $invoiceNumber = $this->generateInvoiceNumber();
            }
        } else {
            // Generate unique invoice number
            $invoiceNumber = $this->generateInvoiceNumber();
        }

        // Determine locale for invoice
        $locale = $this->determineLocale($order);

        // Generate PDF
        $pdfPath = $this->generatePdf($order, $invoiceNumber, $locale);

        // Update order with invoice data
        $order->update([
            'invoice_number' => $invoiceNumber,
            'invoice_file_path' => $pdfPath,
            'invoice_date' => now(),
        ]);

        Log::info('Invoice generated successfully', [
            'order_id' => $order->id,
            'invoice_number' => $invoiceNumber,
            'pdf_path' => $pdfPath,
        ]);

        // Dispatch email notification if requested
        if ($sendEmail) {
            Log::info('Dispatching invoice email notification', [
                'order_id' => $order->id,
                'invoice_number' => $invoiceNumber,
            ]);

            SendInvoiceEmail::dispatch($order);
        }

        return [
            'invoice_number' => $invoiceNumber,
            'invoice_file_path' => $pdfPath,
            'invoice_date' => $order->invoice_date,
            'already_exists' => false,
        ];
    }

    /**
     * Generate unique invoice number with format: {YEAR}-Q{QUARTER}-{SEQUENCE}
     */
    private function generateInvoiceNumber(): string
    {
        $now = now();
        $year = $now->year;
        $quarter = ceil($now->month / 3); // 1-4

        $prefix = "{$year}-Q{$quarter}-";

        // Find the last invoice number for this year and quarter
        $lastInvoice = Order::where('invoice_number', 'like', $prefix.'%')
            ->orderBy('invoice_number', 'desc')
            ->first();

        if ($lastInvoice) {
            // Extract sequence number from last invoice
            $lastSequence = (int) substr($lastInvoice->invoice_number, strlen($prefix));
            $newSequence = $lastSequence + 1;
        } else {
            // First invoice of this quarter - start at 10001
            $newSequence = 10001;
        }

        return $prefix.$newSequence;
    }

    /**
     * Determine the locale for the invoice
     */
    private function determineLocale(Order $order): string
    {
        // Try to get user's locale
        if ($order->payer_type === 'user') {
            $user = User::find($order->payer_id);
            if ($user && $user->locale) {
                return $user->locale;
            }
        }

        // For organizations, use first admin's locale (or default)
        if ($order->payer_type === 'organization') {
            $org = Organization::find($order->payer_id);
            if ($org) {
                $firstAdmin = $org->users()
                    ->wherePivot('role', OrganizationRole::Owner)
                    ->orderBy('organization_user.joined_at', 'asc')
                    ->first();

                if ($firstAdmin && $firstAdmin->locale) {
                    return $firstAdmin->locale;
                }
            }
        }

        // Fallback: Determine locale from billing country
        $billingCountry = $order->billing_snapshot['country'] ?? $order->country ?? null;
        if ($billingCountry) {
            // Dutch-speaking countries
            if (in_array($billingCountry, ['NL', 'BE'])) {
                return 'nl';
            }
        }

        // Fallback to app default
        return config('app.locale', 'en');
    }

    /**
     * Generate PDF file for invoice
     *
     * @throws \RuntimeException If PDF generation or storage fails
     */
    private function generatePdf(Order $order, string $invoiceNumber, string $locale): string
    {
        // Set locale temporarily for translation
        $originalLocale = app()->getLocale();

        try {
            app()->setLocale($locale);

            // Prepare data for PDF
            $data = $this->prepareInvoiceData($order, $invoiceNumber);

            // Generate PDF using DomPDF
            $pdf = Pdf::loadView('invoices.pdf', $data);
            $pdfContent = $pdf->output();

            if (empty($pdfContent)) {
                throw new \RuntimeException('PDF generation returned empty content');
            }

        } catch (\Exception $e) {
            Log::error('Failed to generate PDF content', [
                'order_id' => $order->id,
                'invoice_number' => $invoiceNumber,
                'error' => $e->getMessage(),
            ]);
            throw new \RuntimeException("PDF generation failed: {$e->getMessage()}", 0, $e);
        } finally {
            // Always restore original locale
            app()->setLocale($originalLocale);
        }

        // Create storage path
        $year = now()->year;
        $directory = "invoices/{$year}";
        $filename = "{$invoiceNumber}.pdf";
        $fullPath = "{$directory}/{$filename}";

        try {
            // Ensure directory exists
            if (! Storage::makeDirectory($directory)) {
                Log::warning('Storage::makeDirectory returned false', [
                    'directory' => $directory,
                ]);
            }

            // Save PDF to storage
            $stored = Storage::put($fullPath, $pdfContent);

            if (! $stored) {
                throw new \RuntimeException('Storage::put returned false');
            }

            // Verify file was created
            if (! Storage::exists($fullPath)) {
                throw new \RuntimeException('File does not exist after storage');
            }

        } catch (\Exception $e) {
            Log::error('Failed to store PDF file', [
                'order_id' => $order->id,
                'invoice_number' => $invoiceNumber,
                'path' => $fullPath,
                'error' => $e->getMessage(),
            ]);
            throw new \RuntimeException("PDF storage failed: {$e->getMessage()}", 0, $e);
        }

        return $fullPath;
    }

    /**
     * Prepare data for invoice PDF template
     */
    private function prepareInvoiceData(Order $order, string $invoiceNumber): array
    {
        // Company information from config
        $company = [
            'name' => config('invoice.company_name'),
            'legal_name' => config('invoice.company_legal_name'),
            'address' => config('invoice.company_address'),
            'postal_code' => config('invoice.company_postal_code'),
            'city' => config('invoice.company_city'),
            'country' => config('invoice.company_country'),
            'vat_id' => config('invoice.company_vat_id'),
            'coc' => config('invoice.company_coc'),
            'email' => config('invoice.company_email'),
            'phone' => config('invoice.company_phone'),
            'website' => config('invoice.company_website'),
            'iban' => config('invoice.company_iban'),
            'bic' => config('invoice.company_bic'),
            'bank_name' => config('invoice.company_bank_name'),
        ];

        // Customer information from billing snapshot
        $billingSnapshot = $order->billing_snapshot ?? [];
        $companyName = $billingSnapshot['company_name'] ?? null;

        // For organizations with company name, don't duplicate the name field
        $customerName = null;
        if (! $companyName || $order->payer_type !== 'organization') {
            $customerName = $this->getCustomerName($order, $billingSnapshot);
        }

        $customer = [
            'name' => $customerName,
            'company' => $companyName,
            'email' => $billingSnapshot['email'] ?? null,
            'address' => $billingSnapshot['street'] ?? null,
            'city' => $billingSnapshot['city'] ?? null,
            'state' => $billingSnapshot['state'] ?? null,
            'postal_code' => $billingSnapshot['postal_code'] ?? null,
            'country' => $this->getCountryName($order->country),
            'vat_id' => $billingSnapshot['vat_id'] ?? $order->vat_id,
        ];

        // Invoice line items
        $lineItems = $this->buildLineItems($order);

        // VAT information
        $vatInfo = $this->getVatInfo($order);

        // Calculate VAT rate
        $vatRate = 0;
        if ($order->net_amount > 0 && $order->tax_amount > 0) {
            $vatRate = ($order->tax_amount / $order->net_amount) * 100;
        }

        // Resolve the order's user for locale-aware formatting in the PDF template.
        // The PDF uses format_number($amount, 2, $invoice_user) for amounts.
        $invoiceUser = $this->resolveInvoiceUser($order);

        return [
            'invoice_number' => $invoiceNumber,
            'invoice_date' => now()->format('Y-m-d'),
            'due_days' => config('invoice.default_due_days', 14),
            'order' => $order,
            'company' => $company,
            'customer' => $customer,
            'line_items' => $lineItems,
            'net_amount' => $order->net_amount,
            'tax_amount' => $order->tax_amount,
            'gross_amount' => $order->gross_amount,
            'vat_rate' => $vatRate,
            'currency' => strtoupper($order->currency),
            'vat_info' => $vatInfo,
            'payment_status' => $order->isPaid() ? 'paid' : 'pending',
            'invoice_user' => $invoiceUser,
        ];
    }

    /**
     * Get customer name from order
     */
    private function getCustomerName(Order $order, array $billingSnapshot): string
    {
        // For organizations, use company name
        if ($order->payer_type === 'organization') {
            return $billingSnapshot['company_name'] ?? 'Organization';
        }

        // For users, use full_name or combine first and last name
        if (! empty($billingSnapshot['full_name'])) {
            return $billingSnapshot['full_name'];
        }

        $firstName = $billingSnapshot['first_name'] ?? '';
        $lastName = $billingSnapshot['last_name'] ?? '';

        return trim($firstName.' '.$lastName) ?: 'Customer';
    }

    /**
     * Build line items for invoice
     */
    private function buildLineItems(Order $order): array
    {
        // Get license details if available
        $license = $order->license;

        if (! $license) {
            // Fallback to basic order info
            return [[
                'description' => __('invoice.license_purchase'),
                'quantity' => 1,
                'unit_price' => $order->net_amount,
                'total' => $order->net_amount,
            ]];
        }

        // Build description from license name and credits
        $description = $license->name ?? __('licenses.'.$license->slug, [], app()->getLocale());

        // Add credits info to description if available
        if ($license->credits) {
            $description .= ' - '.number_format($license->credits).' '.__('invoice.credits');
        }

        // For one-time licenses, add validity period
        if ($license->tier === 'onetime' && $license->period) {
            $months = intval($license->period / 30);
            if ($months >= 1) {
                $description .= ' ('.$months.' '.($months === 1 ? __('invoice.month') : __('invoice.months')).')';
            }
        }

        $quantity = 1;
        $unitPrice = $order->net_amount;

        return [[
            'description' => $description,
            'quantity' => $quantity,
            'unit_price' => $unitPrice,
            'total' => $order->net_amount,
        ]];
    }

    /**
     * Get VAT information and notes
     */
    private function getVatInfo(Order $order): array
    {
        $isReverseCharge = false;
        $note = null;

        // Check if this is a reverse charge scenario (EU business with VAT ID)
        if ($order->tax_amount == 0 && ! empty($order->vat_id)) {
            $isReverseCharge = true;
            $note = 'reverse_charge'; // Translation key
        }

        return [
            'is_reverse_charge' => $isReverseCharge,
            'note' => $note,
        ];
    }

    /**
     * Convert country code to full country name
     */
    private function getCountryName(?string $countryCode): ?string
    {
        if (! $countryCode) {
            return null;
        }

        $countries = [
            'NL' => 'The Netherlands',
            'BE' => 'Belgium',
            'DE' => 'Germany',
            'FR' => 'France',
            'GB' => 'United Kingdom',
            'US' => 'United States',
            'CA' => 'Canada',
            'AU' => 'Australia',
            'AT' => 'Austria',
            'CH' => 'Switzerland',
            'ES' => 'Spain',
            'IT' => 'Italy',
            'PT' => 'Portugal',
            'IE' => 'Ireland',
            'DK' => 'Denmark',
            'SE' => 'Sweden',
            'NO' => 'Norway',
            'FI' => 'Finland',
            'PL' => 'Poland',
            'CZ' => 'Czech Republic',
            'LU' => 'Luxembourg',
        ];

        return $countries[strtoupper($countryCode)] ?? $countryCode;
    }

    /**
     * Resolve the user associated with an order for locale-aware formatting.
     * Delegates to LocaleService::resolveUserForOrder() (single source of truth).
     */
    private function resolveInvoiceUser(Order $order): ?User
    {
        return LocaleService::resolveUserForOrder($order);
    }
}
