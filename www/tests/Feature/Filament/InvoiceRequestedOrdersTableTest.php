<?php

declare(strict_types=1);

use App\Filament\Widgets\InvoiceRequestedOrdersTable;
use App\Models\Order;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->admin = User::factory()->create(['is_admin' => true]);
    $this->actingAs($this->admin, 'admin');
});

describe('InvoiceRequestedOrdersTable Widget', function () {

    it('renders without errors', function () {
        Livewire::test(InvoiceRequestedOrdersTable::class)
            ->assertSuccessful();
    });

    it('displays gross_amount in euros without dividing by 100', function () {
        $user = User::factory()->create();
        Order::factory()->create([
            'payer_type'   => 'user',
            'payer_id'     => $user->id,
            'status'       => 'invoice_requested',
            'gross_amount' => 35.00,
            'net_amount'   => 28.93,
            'tax_amount'   => 6.07,
            'currency'     => 'eur',
        ]);

        Livewire::test(InvoiceRequestedOrdersTable::class)
            ->assertSee('EUR 35.00')   // correct
            ->assertDontSee('EUR 0.35'); // would appear if divided by 100
    });

    it('displays usd gross_amount correctly', function () {
        $user = User::factory()->create();
        Order::factory()->create([
            'payer_type'   => 'user',
            'payer_id'     => $user->id,
            'status'       => 'invoice_requested',
            'gross_amount' => 49.00,
            'net_amount'   => 49.00,
            'tax_amount'   => 0.00,
            'currency'     => 'usd',
        ]);

        Livewire::test(InvoiceRequestedOrdersTable::class)
            ->assertSee('USD 49.00')
            ->assertDontSee('USD 0.49');
    });

    it('only shows invoice_requested orders, not paid orders', function () {
        $user = User::factory()->create();

        Order::factory()->create([
            'payer_type'   => 'user',
            'payer_id'     => $user->id,
            'status'       => 'invoice_requested',
            'gross_amount' => 35.00,
            'currency'     => 'eur',
        ]);

        Order::factory()->create([
            'payer_type'   => 'user',
            'payer_id'     => $user->id,
            'status'       => 'paid',
            'gross_amount' => 99.00,
            'currency'     => 'eur',
        ]);

        Livewire::test(InvoiceRequestedOrdersTable::class)
            ->assertSee('EUR 35.00')
            ->assertDontSee('EUR 99.00');
    });

    it('shows an empty table when there are no invoice_requested orders', function () {
        Livewire::test(InvoiceRequestedOrdersTable::class)
            ->assertSuccessful()
            ->assertDontSee('EUR');
    });

});
