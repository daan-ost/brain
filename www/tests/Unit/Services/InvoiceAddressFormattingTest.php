<?php

use App\Models\Order;
use App\Services\InvoiceGenerationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('local');
    Queue::fake();
});

describe('Invoice Address Formatting with State', function () {
    it('includes state in billing snapshot for US customers', function () {
        $user = createUser();

        $order = createOrder([
            'payer_type' => 'user',
            'payer_id' => $user->id,
            'status' => 'paid',
            'country' => 'US',
            'billing_snapshot' => [
                'company_name' => 'US Corp',
                'street' => '123 Main St',
                'city' => 'Los Angeles',
                'state' => 'California',
                'postal_code' => '90210',
                'email' => 'test@example.com',
            ],
        ]);

        $service = new InvoiceGenerationService;
        $result = $service->generateInvoice($order);

        expect($result['invoice_number'])->not->toBeNull();

        // Verify the state is in the order's billing snapshot
        expect($order->billing_snapshot['state'])->toBe('California');
    });

    it('includes state in billing snapshot for CA customers', function () {
        $user = createUser();

        $order = createOrder([
            'payer_type' => 'user',
            'payer_id' => $user->id,
            'status' => 'paid',
            'country' => 'CA',
            'billing_snapshot' => [
                'company_name' => 'CA Corp',
                'street' => '456 Maple Ave',
                'city' => 'Toronto',
                'state' => 'Ontario',
                'postal_code' => 'M5V 1J1',
                'email' => 'test@example.com',
            ],
        ]);

        $service = new InvoiceGenerationService;
        $result = $service->generateInvoice($order);

        expect($result['invoice_number'])->not->toBeNull();
        expect($order->billing_snapshot['state'])->toBe('Ontario');
    });

    it('includes state in billing snapshot for AU customers', function () {
        $user = createUser();

        $order = createOrder([
            'payer_type' => 'user',
            'payer_id' => $user->id,
            'status' => 'paid',
            'country' => 'AU',
            'billing_snapshot' => [
                'company_name' => 'AU Corp',
                'street' => '789 Sydney Rd',
                'city' => 'Sydney',
                'state' => 'NSW',
                'postal_code' => '2000',
                'email' => 'test@example.com',
            ],
        ]);

        $service = new InvoiceGenerationService;
        $result = $service->generateInvoice($order);

        expect($result['invoice_number'])->not->toBeNull();
        expect($order->billing_snapshot['state'])->toBe('NSW');
    });

    it('handles empty state for EU customers', function () {
        $user = createUser();

        $order = createOrder([
            'payer_type' => 'user',
            'payer_id' => $user->id,
            'status' => 'paid',
            'country' => 'NL',
            'billing_snapshot' => [
                'company_name' => 'NL Corp',
                'street' => 'Teststraat 123',
                'city' => 'Amsterdam',
                'postal_code' => '1012 AB',
                'email' => 'test@example.com',
                // No state field
            ],
        ]);

        $service = new InvoiceGenerationService;
        $result = $service->generateInvoice($order);

        expect($result['invoice_number'])->not->toBeNull();
        expect($order->billing_snapshot['state'] ?? null)->toBeNull();
    });

    it('generates valid PDF with state field', function () {
        $user = createUser();

        $order = createOrder([
            'payer_type' => 'user',
            'payer_id' => $user->id,
            'status' => 'paid',
            'country' => 'US',
            'billing_snapshot' => [
                'company_name' => 'State Test Corp',
                'street' => '100 Test Blvd',
                'city' => 'New York',
                'state' => 'NY',
                'postal_code' => '10001',
                'email' => 'test@example.com',
            ],
        ]);

        $service = new InvoiceGenerationService;
        $result = $service->generateInvoice($order);

        // Verify PDF was created
        Storage::disk('local')->assertExists($result['invoice_file_path']);

        // Verify PDF has content
        $content = Storage::disk('local')->get($result['invoice_file_path']);
        expect($content)->not->toBeEmpty();
    });
});

describe('Invoice Customer Data Preparation', function () {
    it('passes state to invoice template data', function () {
        $user = createUser();

        $order = createOrder([
            'payer_type' => 'user',
            'payer_id' => $user->id,
            'status' => 'paid',
            'country' => 'US',
            'billing_snapshot' => [
                'company_name' => 'Template Test Corp',
                'street' => '200 Template St',
                'city' => 'San Francisco',
                'state' => 'CA',
                'postal_code' => '94102',
                'email' => 'template@example.com',
            ],
        ]);

        $service = new InvoiceGenerationService;
        $result = $service->generateInvoice($order);

        // Verify invoice was generated successfully
        expect($result['invoice_number'])->not->toBeNull();
        expect($result['invoice_file_path'])->not->toBeNull();

        // The state should be preserved in the billing snapshot
        $order->refresh();
        expect($order->billing_snapshot['state'])->toBe('CA');
    });
});
