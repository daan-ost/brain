<?php

declare(strict_types=1);

use App\Filament\Resources\OrderResource\Pages\ListOrders;
use App\Jobs\SendInvoiceEmail;
use App\Models\Order;
use App\Models\User;
use App\Services\InvoiceGenerationService;
use App\Services\MolliePaymentService;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

beforeEach(function () {
    $this->admin = User::factory()->create(['is_admin' => true]);
    $this->actingAs($this->admin, 'admin');
});

describe('OrderResource::refund', function () {
    it('refund action is visible for paid orders with mollie payment id', function () {
        $order = Order::factory()->create([
            'status' => 'paid',
            'mollie_payment_id' => 'tr_testpayment',
            'gross_amount' => 49.00,
        ]);

        Livewire::test(ListOrders::class)
            ->assertTableActionVisible('refund', $order);
    });

    it('refund action is hidden for orders without mollie payment id', function () {
        $order = Order::factory()->create([
            'status' => 'paid',
            'mollie_payment_id' => null,
            'gross_amount' => 49.00,
        ]);

        Livewire::test(ListOrders::class)
            ->assertTableActionHidden('refund', $order);
    });

    it('refund action is hidden for non-paid orders', function () {
        $order = Order::factory()->create([
            'status' => 'canceled',
            'mollie_payment_id' => 'tr_testpayment',
            'gross_amount' => 49.00,
        ]);

        Livewire::test(ListOrders::class)
            ->assertTableActionHidden('refund', $order);
    });

    it('full refund changes order status to refunded', function () {
        $order = Order::factory()->create([
            'status' => 'paid',
            'mollie_payment_id' => 'tr_testpayment',
            'gross_amount' => 49.00,
            'currency' => 'EUR',
            'meta' => [],
        ]);

        $this->mock(MolliePaymentService::class, function ($mock) {
            $mock->shouldReceive('createRefundForAdmin')
                ->once()
                ->andReturn([
                    'success' => true,
                    'data' => ['id' => 're_refund123'],
                ]);
        });

        Livewire::test(ListOrders::class)
            ->callTableAction('refund', $order, data: [
                'amount' => 49.00,
                'reason' => 'Customer requested full refund',
            ])
            ->assertNotified('Refund created');

        $order->refresh();
        expect($order->status->value)->toBe('refunded');
        expect($order->meta['refund_id'])->toBe('re_refund123');
        expect((float) $order->meta['refund_amount'])->toBe(49.00);
        expect($order->meta['refund_reason'])->toBe('Customer requested full refund');
        expect($order->meta['refunded_by'])->toBe($this->admin->name);
        expect($order->meta)->toHaveKey('refunded_at');
    });

    it('partial refund keeps order status as paid', function () {
        $order = Order::factory()->create([
            'status' => 'paid',
            'mollie_payment_id' => 'tr_testpayment',
            'gross_amount' => 100.00,
            'currency' => 'EUR',
            'meta' => ['existing_key' => 'existing_value'],
        ]);

        $this->mock(MolliePaymentService::class, function ($mock) {
            $mock->shouldReceive('createRefundForAdmin')
                ->once()
                ->andReturn([
                    'success' => true,
                    'data' => ['id' => 're_partial456'],
                ]);
        });

        Livewire::test(ListOrders::class)
            ->callTableAction('refund', $order, data: [
                'amount' => 25.00,
                'reason' => 'Partial refund for quality issue',
            ])
            ->assertNotified('Refund created');

        $order->refresh();
        expect($order->status->value)->toBe('paid');
        expect($order->meta['refund_id'])->toBe('re_partial456');
        expect((float) $order->meta['refund_amount'])->toBe(25.00);
        // Existing meta should be preserved
        expect($order->meta['existing_key'])->toBe('existing_value');
    });

    it('shows error notification when mollie refund fails', function () {
        $order = Order::factory()->create([
            'status' => 'paid',
            'mollie_payment_id' => 'tr_testpayment',
            'gross_amount' => 49.00,
            'currency' => 'EUR',
        ]);

        $this->mock(MolliePaymentService::class, function ($mock) {
            $mock->shouldReceive('createRefundForAdmin')
                ->once()
                ->andReturn([
                    'success' => false,
                    'error' => 'Payment has already been refunded',
                ]);
        });

        Livewire::test(ListOrders::class)
            ->callTableAction('refund', $order, data: [
                'amount' => 49.00,
                'reason' => 'Customer request',
            ])
            ->assertNotified('Refund failed');

        $order->refresh();
        expect($order->status->value)->toBe('paid');
    });
});

describe('OrderResource::generateInvoice', function () {
    it('generate invoice action is visible for paid orders without invoice', function () {
        $order = Order::factory()->create([
            'status' => 'paid',
            'invoice_number' => null,
        ]);

        Livewire::test(ListOrders::class)
            ->assertTableActionVisible('generateInvoice', $order);
    });

    it('generate invoice action is hidden for orders that already have an invoice', function () {
        $order = Order::factory()->create([
            'status' => 'paid',
            'invoice_number' => 'INV-2026-001',
        ]);

        Livewire::test(ListOrders::class)
            ->assertTableActionHidden('generateInvoice', $order);
    });

    it('generates invoice successfully', function () {
        $order = Order::factory()->create([
            'status' => 'paid',
            'invoice_number' => null,
        ]);

        $this->mock(InvoiceGenerationService::class, function ($mock) {
            $mock->shouldReceive('generateInvoice')
                ->once()
                ->andReturn([
                    'invoice_number' => 'INV-2026-001',
                    'invoice_file_path' => 'invoices/INV-2026-001.pdf',
                    'invoice_date' => now()->toDateTimeString(),
                ]);
        });

        Livewire::test(ListOrders::class)
            ->callTableAction('generateInvoice', $order)
            ->assertNotified('Invoice generated');
    });

    it('shows error when invoice generation fails', function () {
        $order = Order::factory()->create([
            'status' => 'paid',
            'invoice_number' => null,
        ]);

        $this->mock(InvoiceGenerationService::class, function ($mock) {
            $mock->shouldReceive('generateInvoice')
                ->once()
                ->andReturn([
                    'error' => 'Template not found',
                ]);
        });

        Livewire::test(ListOrders::class)
            ->callTableAction('generateInvoice', $order)
            ->assertNotified('Failed to generate invoice');
    });
});

describe('OrderResource::resendInvoice', function () {
    it('resend action is visible when invoice number and file path exist', function () {
        $order = Order::factory()->create([
            'status' => 'paid',
            'invoice_number' => 'INV-2026-001',
            'invoice_file_path' => 'invoices/INV-2026-001.pdf',
        ]);

        Livewire::test(ListOrders::class)
            ->assertTableActionVisible('resendInvoice', $order);
    });

    it('resend action is hidden when invoice file path is missing', function () {
        $order = Order::factory()->create([
            'status' => 'paid',
            'invoice_number' => 'INV-2026-001',
            'invoice_file_path' => null,
        ]);

        Livewire::test(ListOrders::class)
            ->assertTableActionHidden('resendInvoice', $order);
    });

    it('dispatches SendInvoiceEmail job to queue', function () {
        Queue::fake();

        $order = Order::factory()->create([
            'status' => 'paid',
            'invoice_number' => 'INV-2026-001',
            'invoice_file_path' => 'invoices/INV-2026-001.pdf',
        ]);

        Livewire::test(ListOrders::class)
            ->callTableAction('resendInvoice', $order)
            ->assertNotified('Invoice email queued');

        Queue::assertPushed(SendInvoiceEmail::class);
    });
});
