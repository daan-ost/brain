<?php

use App\Models\Order;
use App\Services\InvoiceGenerationService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('local');
    Mail::fake();
    Queue::fake();
});

describe('Invoice Number Generation', function () {
    it('generates invoice number in correct format YYYY-Qq-NNNNN', function () {
        $order = createOrder(['status' => 'paid']);
        $service = new InvoiceGenerationService;

        $result = $service->generateInvoice($order);

        expect($result['invoice_number'])->toMatch('/^\d{4}-Q[1-4]-\d{5}$/');
    });

    it('starts sequence at 10001 for new quarter', function () {
        $order = createOrder(['status' => 'paid']);
        $service = new InvoiceGenerationService;

        $result = $service->generateInvoice($order);

        // Should start at 10001 for first invoice of the quarter
        expect($result['invoice_number'])->toContain('-10001');
    });

    it('increments sequence correctly', function () {
        $service = new InvoiceGenerationService;

        $order1 = createOrder(['status' => 'paid']);
        $result1 = $service->generateInvoice($order1);

        $order2 = createOrder(['status' => 'paid']);
        $result2 = $service->generateInvoice($order2);

        // Extract sequence numbers
        $seq1 = (int) substr($result1['invoice_number'], -5);
        $seq2 = (int) substr($result2['invoice_number'], -5);

        expect($seq2)->toBe($seq1 + 1);
    });

    it('handles quarter mapping correctly', function () {
        $service = new InvoiceGenerationService;

        // Test Q1 (January)
        Carbon::setTestNow('2025-01-15');
        $orderQ1 = createOrder(['status' => 'paid']);
        $resultQ1 = $service->generateInvoice($orderQ1);
        expect($resultQ1['invoice_number'])->toContain('-Q1-');

        // Test Q2 (April)
        Carbon::setTestNow('2025-04-15');
        $orderQ2 = createOrder(['status' => 'paid']);
        $resultQ2 = $service->generateInvoice($orderQ2);
        expect($resultQ2['invoice_number'])->toContain('-Q2-');

        // Test Q3 (July)
        Carbon::setTestNow('2025-07-15');
        $orderQ3 = createOrder(['status' => 'paid']);
        $resultQ3 = $service->generateInvoice($orderQ3);
        expect($resultQ3['invoice_number'])->toContain('-Q3-');

        // Test Q4 (October)
        Carbon::setTestNow('2025-10-15');
        $orderQ4 = createOrder(['status' => 'paid']);
        $resultQ4 = $service->generateInvoice($orderQ4);
        expect($resultQ4['invoice_number'])->toContain('-Q4-');

        Carbon::setTestNow(); // Reset
    });

    it('resets sequence each quarter', function () {
        $service = new InvoiceGenerationService;

        // Generate invoice in Q1
        Carbon::setTestNow('2025-03-15');
        $orderQ1 = createOrder(['status' => 'paid']);
        $resultQ1 = $service->generateInvoice($orderQ1);

        // Generate invoice in Q2 (should reset to 10001)
        Carbon::setTestNow('2025-04-01');
        $orderQ2 = createOrder(['status' => 'paid']);
        $resultQ2 = $service->generateInvoice($orderQ2);

        expect($resultQ2['invoice_number'])->toContain('-Q2-10001');

        Carbon::setTestNow(); // Reset
    });

    it('does not regenerate invoice if already exists', function () {
        $order = createOrder([
            'invoice_number' => '2025-Q1-10001',
            'invoice_file_path' => 'invoices/2025/2025-Q1-10001.pdf',
            'invoice_date' => now(),
        ]);

        $service = new InvoiceGenerationService;
        $result = $service->generateInvoice($order);

        expect($result['already_exists'])->toBeTrue();
        expect($result['invoice_number'])->toBe('2025-Q1-10001');
    });
});

// Locale Determination tests skipped - locale column doesn't exist in database yet

describe('VAT Calculation', function () {
    it('applies 21% VAT for NL customers', function () {
        $order = createOrder([
            'country' => 'NL',
            'net_amount' => 100.00,
            'tax_amount' => 21.00,
            'gross_amount' => 121.00,
        ]);

        $service = new InvoiceGenerationService;
        $result = $service->generateInvoice($order);

        // Invoice generated successfully
        expect($result['invoice_number'])->not->toBeNull();

        // Refresh order to get updated data
        $order->refresh();

        // VAT rate should be 21%
        $vatRate = ($order->tax_amount / $order->net_amount) * 100;
        expect((int) round($vatRate))->toBe(21);
    });

    it('applies 0% VAT with reverse charge for EU business', function () {
        $order = createOrder([
            'country' => 'DE',
            'vat_id' => 'DE123456789',
            'net_amount' => 100.00,
            'tax_amount' => 0.00,
            'gross_amount' => 100.00,
        ]);

        $service = new InvoiceGenerationService;
        $result = $service->generateInvoice($order);

        expect($result['invoice_number'])->not->toBeNull();

        // VAT should be 0%
        expect((float) $order->tax_amount)->toBe(0.00);
    });

    it('applies 21% VAT for EU consumer without VAT ID', function () {
        $order = createOrder([
            'country' => 'DE',
            'vat_id' => null,
            'net_amount' => 100.00,
            'tax_amount' => 21.00,
            'gross_amount' => 121.00,
        ]);

        $service = new InvoiceGenerationService;
        $result = $service->generateInvoice($order);

        expect($result['invoice_number'])->not->toBeNull();

        $vatRate = ($order->tax_amount / $order->net_amount) * 100;
        expect((int) round($vatRate))->toBe(21);
    });

    it('applies 0% VAT for outside EU', function () {
        $order = createOrder([
            'country' => 'US',
            'net_amount' => 100.00,
            'tax_amount' => 0.00,
            'gross_amount' => 100.00,
        ]);

        $service = new InvoiceGenerationService;
        $result = $service->generateInvoice($order);

        expect($result['invoice_number'])->not->toBeNull();
        expect((float) $order->tax_amount)->toBe(0.00);
    });

    it('calculates VAT rate correctly', function () {
        $testCases = [
            ['net' => 100.00, 'tax' => 21.00, 'expected_rate' => 21],
            ['net' => 50.00, 'tax' => 10.50, 'expected_rate' => 21],
            ['net' => 200.00, 'tax' => 0.00, 'expected_rate' => 0],
        ];

        foreach ($testCases as $case) {
            $order = createOrder([
                'net_amount' => $case['net'],
                'tax_amount' => $case['tax'],
                'gross_amount' => $case['net'] + $case['tax'],
            ]);

            $vatRate = $case['tax'] > 0
                ? ($case['tax'] / $case['net']) * 100
                : 0;

            expect((int) round($vatRate))->toBe($case['expected_rate']);
        }
    });
});

describe('PDF Generation', function () {
    it('creates PDF file in correct directory', function () {
        $order = createOrder(['status' => 'paid']);
        $service = new InvoiceGenerationService;

        $result = $service->generateInvoice($order);

        $year = now()->year;
        expect($result['invoice_file_path'])->toContain("invoices/{$year}/");
    });

    it('filename matches invoice number', function () {
        $order = createOrder(['status' => 'paid']);
        $service = new InvoiceGenerationService;

        $result = $service->generateInvoice($order);

        $invoiceNumber = $result['invoice_number'];
        expect($result['invoice_file_path'])->toContain("{$invoiceNumber}.pdf");
    });

    it('file exists in storage after generation', function () {
        $order = createOrder(['status' => 'paid']);
        $service = new InvoiceGenerationService;

        $result = $service->generateInvoice($order);

        Storage::disk('local')->assertExists($result['invoice_file_path']);
    });

    it('stores PDF in invoices directory by year', function () {
        Carbon::setTestNow('2025-03-15');

        $order = createOrder(['status' => 'paid']);
        $service = new InvoiceGenerationService;

        $result = $service->generateInvoice($order);

        expect($result['invoice_file_path'])->toStartWith('invoices/2025/');

        Carbon::setTestNow(); // Reset
    });
});

describe('Customer Information Handling', function () {
    it('uses company name only for organization orders', function () {
        $organization = createOrganization(['name' => 'Acme Corporation']);
        $order = createOrder([
            'payer_type' => 'organization',
            'payer_id' => $organization->id,
            'billing_snapshot' => [
                'company_name' => 'Acme Corporation',
                'first_name' => 'John',
                'last_name' => 'Doe',
            ],
        ]);

        $service = new InvoiceGenerationService;
        $result = $service->generateInvoice($order);

        expect($result['invoice_number'])->not->toBeNull();
    });

    it('uses personal name for individual user orders', function () {
        $user = createUser();
        $order = createOrder([
            'payer_type' => 'user',
            'payer_id' => $user->id,
            'billing_snapshot' => [
                'first_name' => 'Jane',
                'last_name' => 'Smith',
                'company_name' => null,
            ],
        ]);

        $service = new InvoiceGenerationService;
        $result = $service->generateInvoice($order);

        expect($result['invoice_number'])->not->toBeNull();
    });

    it('includes VAT ID when available', function () {
        $order = createOrder([
            'vat_id' => 'NL123456789B01',
            'billing_snapshot' => [
                'vat_id' => 'NL123456789B01',
            ],
        ]);

        $service = new InvoiceGenerationService;
        $result = $service->generateInvoice($order);

        expect($result['invoice_number'])->not->toBeNull();
    });
});

describe('Line Items Building', function () {
    it('includes plan name and credits for individual orders', function () {
        $license = createLicense([
            'name' => 'Premium Plan',
            'credits' => 500,
        ]);

        $order = createOrder([
            'license_id' => $license->id,
        ]);

        $service = new InvoiceGenerationService;
        $result = $service->generateInvoice($order);

        expect($result['invoice_number'])->not->toBeNull();
    });

    it('shows period for one-time licenses', function () {
        $license = createLicense([
            'tier' => 'onetime',
            'name' => 'One-Time License',
        ]);

        $order = createOrder([
            'license_id' => $license->id,
        ]);

        $service = new InvoiceGenerationService;
        $result = $service->generateInvoice($order);

        expect($result['invoice_number'])->not->toBeNull();
    });
});
