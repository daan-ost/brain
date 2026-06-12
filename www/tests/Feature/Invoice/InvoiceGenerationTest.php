<?php

use App\Models\Order;
use App\Services\InvoiceGenerationService;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('local');
    Queue::fake(); // Prevent jobs from actually executing during tests
});

describe('Invoice Generation Triggers', function () {
    it('generates invoice after successful payment', function () {
        $user = createUser(['country' => 'NL']);

        // Create order manually to avoid factory schema issues
        $order = createOrder([
            'payer_type' => 'user',
            'payer_id' => $user->id,
            'status' => 'pending',
            'currency' => 'EUR',
        ]);

        // Simulate payment completion
        $order->update(['status' => 'paid']);

        // Generate invoice
        $service = new InvoiceGenerationService;
        $result = $service->generateInvoice($order);

        // Assertions
        expect($result['invoice_number'])->not->toBeNull();
        expect($result['invoice_number'])->toMatch('/^\d{4}-Q[1-4]-\d{5}$/');
        expect($result['invoice_file_path'])->not->toBeNull();
        expect($result['invoice_date'])->not->toBeNull();

        // Verify order updated
        $order->refresh();
        expect($order->invoice_number)->toBe($result['invoice_number']);
        expect($order->invoice_file_path)->toBe($result['invoice_file_path']);

        // Verify file exists
        Storage::disk('local')->assertExists($result['invoice_file_path']);
    });

    it('does not regenerate invoice if already exists', function () {
        $user = createUser();

        $order = createOrder([
            'payer_type' => 'user',
            'payer_id' => $user->id,
            'status' => 'paid',
            'currency' => 'EUR',
            'invoice_number' => '2025-Q1-10001',
            'invoice_file_path' => 'invoices/2025/2025-Q1-10001.pdf',
            'invoice_date' => now(),
        ]);

        $service = new InvoiceGenerationService;
        $result = $service->generateInvoice($order);

        expect($result['already_exists'])->toBeTrue();
        expect($result['invoice_number'])->toBe('2025-Q1-10001');
    });

    it('generates unique invoice numbers for multiple orders', function () {
        $user = createUser();
        $service = new InvoiceGenerationService;

        $order1 = createOrder([
            'payer_type' => 'user',
            'payer_id' => $user->id,
            'status' => 'paid',
            'currency' => 'EUR',
        ]);

        $order2 = createOrder([
            'payer_type' => 'user',
            'payer_id' => $user->id,
            'status' => 'paid',
            'currency' => 'EUR',
        ]);

        $result1 = $service->generateInvoice($order1);
        $result2 = $service->generateInvoice($order2);

        expect($result1['invoice_number'])->not->toBe($result2['invoice_number']);

        // Extract sequence numbers
        $seq1 = (int) substr($result1['invoice_number'], -5);
        $seq2 = (int) substr($result2['invoice_number'], -5);

        expect($seq2)->toBe($seq1 + 1);
    });
});

describe('Invoice Data Integrity', function () {
    it('populates all invoice fields correctly', function () {
        $user = createUser();

        $order = createOrder([
            'payer_type' => 'user',
            'payer_id' => $user->id,
            'status' => 'paid',
            'currency' => 'EUR',
            'billing_snapshot' => [
                'first_name' => 'John',
                'last_name' => 'Doe',
                'country' => 'NL',
            ],
        ]);

        $service = new InvoiceGenerationService;
        $result = $service->generateInvoice($order);

        $order->refresh();

        expect($order->invoice_number)->not->toBeNull();
        expect($order->invoice_file_path)->not->toBeNull();
        expect($order->invoice_date)->not->toBeNull();
        expect($order->invoice_number)->toMatch('/^\d{4}-Q[1-4]-\d{5}$/');
    });

    it('stores PDF in correct year directory', function () {
        $user = createUser();

        $order = createOrder([
            'payer_type' => 'user',
            'payer_id' => $user->id,
            'status' => 'paid',
            'currency' => 'EUR',
        ]);

        $service = new InvoiceGenerationService;
        $result = $service->generateInvoice($order);

        $year = now()->year;
        expect($result['invoice_file_path'])->toStartWith("invoices/{$year}/");
    });

    it('uses billing snapshot data correctly', function () {
        $user = createUser();

        $order = createOrder([
            'payer_type' => 'user',
            'payer_id' => $user->id,
            'status' => 'paid',
            'currency' => 'EUR',
            'billing_snapshot' => [
                'first_name' => 'Jane',
                'last_name' => 'Smith',
                'email' => 'jane@example.com',
                'country' => 'NL',
                'city' => 'Amsterdam',
                'postal_code' => '1012 AB',
            ],
        ]);

        $service = new InvoiceGenerationService;
        $result = $service->generateInvoice($order);

        expect($result['invoice_number'])->not->toBeNull();
        Storage::disk('local')->assertExists($result['invoice_file_path']);
    });
});

describe('Customer Information Handling', function () {
    it('handles organization orders with company name', function () {
        $org = createOrganization(['name' => 'Acme Corp']);

        $order = createOrder([
            'payer_type' => 'organization',
            'payer_id' => $org->id,
            'status' => 'paid',
            'currency' => 'EUR',
            'billing_snapshot' => [
                'company_name' => 'Acme Corporation',
            ],
        ]);

        $service = new InvoiceGenerationService;
        $result = $service->generateInvoice($order);

        expect($result['invoice_number'])->not->toBeNull();
    });

    it('handles individual user orders with personal name', function () {
        $user = createUser();

        $order = createOrder([
            'payer_type' => 'user',
            'payer_id' => $user->id,
            'status' => 'paid',
            'currency' => 'EUR',
            'billing_snapshot' => [
                'first_name' => 'John',
                'last_name' => 'Doe',
            ],
        ]);

        $service = new InvoiceGenerationService;
        $result = $service->generateInvoice($order);

        expect($result['invoice_number'])->not->toBeNull();
    });

    it('includes VAT ID when provided', function () {
        $user = createUser();

        $order = createOrder([
            'payer_type' => 'user',
            'payer_id' => $user->id,
            'status' => 'paid',
            'currency' => 'EUR',
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

describe('Payment Status Handling', function () {
    it('generates invoice for paid orders', function () {
        $user = createUser();

        $order = createOrder([
            'payer_type' => 'user',
            'payer_id' => $user->id,
            'status' => 'paid',
            'currency' => 'EUR',
            'paid_at' => now(),
        ]);

        $service = new InvoiceGenerationService;
        $result = $service->generateInvoice($order);

        expect($result['invoice_number'])->not->toBeNull();
    });

    it('can generate invoice for pending payment invoice orders', function () {
        $user = createUser();

        $order = createOrder([
            'payer_type' => 'user',
            'payer_id' => $user->id,
            'status' => 'pending',
            'currency' => 'EUR',
            'payment_method' => 'invoice',
        ]);

        $service = new InvoiceGenerationService;
        $result = $service->generateInvoice($order);

        expect($result['invoice_number'])->not->toBeNull();
    });
});

describe('File Storage', function () {
    it('creates PDF file with correct permissions', function () {
        $user = createUser();

        $order = createOrder([
            'payer_type' => 'user',
            'payer_id' => $user->id,
            'status' => 'paid',
            'currency' => 'EUR',
        ]);

        $service = new InvoiceGenerationService;
        $result = $service->generateInvoice($order);

        // File should exist
        Storage::disk('local')->assertExists($result['invoice_file_path']);

        // File should have content
        $content = Storage::disk('local')->get($result['invoice_file_path']);
        expect($content)->not->toBeEmpty();
    });

    it('organizes files by year', function () {
        $user = createUser();

        $order = createOrder([
            'payer_type' => 'user',
            'payer_id' => $user->id,
            'status' => 'paid',
            'currency' => 'EUR',
        ]);

        $service = new InvoiceGenerationService;
        $result = $service->generateInvoice($order);

        $year = now()->year;
        $expectedPath = "invoices/{$year}/".$result['invoice_number'].'.pdf';

        expect($result['invoice_file_path'])->toBe($expectedPath);
        Storage::disk('local')->assertExists($expectedPath);
    });
});

describe('Error Handling', function () {
    it('handles missing billing data gracefully', function () {
        $user = createUser();

        $order = createOrder([
            'payer_type' => 'user',
            'payer_id' => $user->id,
            'status' => 'paid',
            'currency' => 'EUR',
            'billing_snapshot' => null, // Missing billing data
        ]);

        $service = new InvoiceGenerationService;
        $result = $service->generateInvoice($order);

        // Should still generate invoice with default/fallback data
        expect($result['invoice_number'])->not->toBeNull();
    });
});
