<?php

declare(strict_types=1);

use App\Filament\Resources\OrderResource;
use App\Models\Order;
use App\Models\Organization;
use App\Models\User;

beforeEach(function () {
    $this->admin = User::factory()->create(['is_admin' => true]);
    $this->actingAs($this->admin, 'admin');
});

describe('OrderResource::Authorization', function () {
    it('denies non-admin access to list page', function () {
        $regularUser = User::factory()->create(['is_admin' => false]);
        $this->actingAs($regularUser, 'admin');

        $this->get(OrderResource::getUrl('index'))
            ->assertForbidden();
    });

    it('denies non-admin access to create page', function () {
        $regularUser = User::factory()->create(['is_admin' => false]);
        $this->actingAs($regularUser, 'admin');

        $this->get(OrderResource::getUrl('create'))
            ->assertForbidden();
    });

    it('denies non-admin access to edit page', function () {
        $regularUser = User::factory()->create(['is_admin' => false]);
        $user = User::factory()->create();
        $order = Order::factory()->create([
            'payer_type' => 'user',
            'payer_id' => $user->id,
        ]);
        $this->actingAs($regularUser, 'admin');

        $this->get(OrderResource::getUrl('edit', ['record' => $order]))
            ->assertForbidden();
    });

    it('denies non-admin access to view page', function () {
        $regularUser = User::factory()->create(['is_admin' => false]);
        $user = User::factory()->create();
        $order = Order::factory()->create([
            'payer_type' => 'user',
            'payer_id' => $user->id,
        ]);
        $this->actingAs($regularUser, 'admin');

        $this->get(OrderResource::getUrl('view', ['record' => $order]))
            ->assertForbidden();
    });

    it('allows admin access to create page', function () {
        $response = $this->get(OrderResource::getUrl('create'));

        expect($response->status())->not->toBe(403);
        expect($response->status())->not->toBe(302);
    });

    it('allows admin access to edit page', function () {
        $user = User::factory()->create();
        $order = Order::factory()->create([
            'payer_type' => 'user',
            'payer_id' => $user->id,
        ]);

        $response = $this->get(OrderResource::getUrl('edit', ['record' => $order]));

        expect($response->status())->not->toBe(403);
        expect($response->status())->not->toBe(302);
    });

    it('allows admin access to view page', function () {
        $user = User::factory()->create();
        $order = Order::factory()->create([
            'payer_type' => 'user',
            'payer_id' => $user->id,
        ]);

        $response = $this->get(OrderResource::getUrl('view', ['record' => $order]));

        expect($response->status())->not->toBe(403);
        expect($response->status())->not->toBe(302);
    });
});

describe('OrderResource::Routes', function () {
    it('has correct index route', function () {
        expect(OrderResource::getUrl('index'))->toContain('/beheer/orders');
    });

    it('has correct create route', function () {
        expect(OrderResource::getUrl('create'))->toContain('/beheer/orders/create');
    });

    it('has correct edit route', function () {
        $user = User::factory()->create();
        $order = Order::factory()->create([
            'payer_type' => 'user',
            'payer_id' => $user->id,
        ]);
        expect(OrderResource::getUrl('edit', ['record' => $order]))->toContain("/beheer/orders/{$order->id}/edit");
    });

    it('has correct view route', function () {
        $user = User::factory()->create();
        $order = Order::factory()->create([
            'payer_type' => 'user',
            'payer_id' => $user->id,
        ]);
        expect(OrderResource::getUrl('view', ['record' => $order]))->toContain("/beheer/orders/{$order->id}");
    });
});

describe('OrderResource::Model', function () {
    it('uses Order model', function () {
        expect(OrderResource::getModel())->toBe(Order::class);
    });

    it('has navigation icon', function () {
        expect(OrderResource::getNavigationIcon())->toBe('heroicon-o-shopping-cart');
    });

    it('belongs to Orders & Payments navigation group', function () {
        expect(OrderResource::getNavigationGroup())->toBe('Orders & Payments');
    });
});

describe('OrderResource::Pages', function () {
    it('has list page', function () {
        $pages = OrderResource::getPages();
        expect($pages)->toHaveKey('index');
    });

    it('has create page', function () {
        $pages = OrderResource::getPages();
        expect($pages)->toHaveKey('create');
    });

    it('has edit page', function () {
        $pages = OrderResource::getPages();
        expect($pages)->toHaveKey('edit');
    });

    it('has view page', function () {
        $pages = OrderResource::getPages();
        expect($pages)->toHaveKey('view');
    });
});

describe('OrderResource::OrderTypes', function () {
    it('can create user payer order', function () {
        $user = User::factory()->create();
        $order = Order::factory()->create([
            'payer_type' => 'user',
            'payer_id' => $user->id,
            'type' => 'subscription',
        ]);

        expect($order->payer_type)->toBe('user');
        expect($order->payer_id)->toBe($user->id);
        expect($order->type)->toBe('subscription');
    });

    it('can create organization payer order', function () {
        $organization = Organization::factory()->create();
        $order = Order::factory()->create([
            'payer_type' => 'organization',
            'payer_id' => $organization->id,
            'type' => 'subscription',
        ]);

        expect($order->payer_type)->toBe('organization');
        expect($order->payer_id)->toBe($organization->id);
    });

    it('can create onetime order type', function () {
        $user = User::factory()->create();
        $order = Order::factory()->create([
            'payer_type' => 'user',
            'payer_id' => $user->id,
            'type' => 'onetime',
        ]);

        expect($order->type)->toBe('onetime');
    });

    it('can create subscription order type', function () {
        $user = User::factory()->create();
        $order = Order::factory()->create([
            'payer_type' => 'user',
            'payer_id' => $user->id,
            'type' => 'subscription',
        ]);

        expect($order->type)->toBe('subscription');
    });
});

describe('OrderResource::OrderStatuses', function () {
    it('can have paid status', function () {
        $user = User::factory()->create();
        $order = Order::factory()->create([
            'payer_type' => 'user',
            'payer_id' => $user->id,
            'status' => 'paid',
        ]);

        expect($order->status->value ?? $order->status)->toBe('paid');
    });

    it('can have initiated status', function () {
        $user = User::factory()->create();
        $order = Order::factory()->create([
            'payer_type' => 'user',
            'payer_id' => $user->id,
            'status' => 'initiated',
        ]);

        expect($order->status->value ?? $order->status)->toBe('initiated');
    });

    it('can have failed status', function () {
        $user = User::factory()->create();
        $order = Order::factory()->create([
            'payer_type' => 'user',
            'payer_id' => $user->id,
            'status' => 'failed',
        ]);

        expect($order->status->value ?? $order->status)->toBe('failed');
    });

    it('can have canceled status', function () {
        $user = User::factory()->create();
        $order = Order::factory()->create([
            'payer_type' => 'user',
            'payer_id' => $user->id,
            'status' => 'canceled',
        ]);

        expect($order->status->value ?? $order->status)->toBe('canceled');
    });

    it('can have invoice_requested status', function () {
        $user = User::factory()->create();
        $order = Order::factory()->create([
            'payer_type' => 'user',
            'payer_id' => $user->id,
            'status' => 'invoice_requested',
        ]);

        expect($order->status->value ?? $order->status)->toBe('invoice_requested');
    });
});

describe('OrderResource::OrderAmounts', function () {
    it('stores correct amounts', function () {
        $user = User::factory()->create();
        $order = Order::factory()->create([
            'payer_type' => 'user',
            'payer_id' => $user->id,
            'net_amount' => 100.00,
            'tax_amount' => 21.00,
            'gross_amount' => 121.00,
            'currency' => 'EUR',
        ]);

        expect((float) $order->net_amount)->toBe(100.00);
        expect((float) $order->tax_amount)->toBe(21.00);
        expect((float) $order->gross_amount)->toBe(121.00);
        expect($order->currency)->toBe('EUR');
    });

    it('supports EUR currency', function () {
        $user = User::factory()->create();
        $order = Order::factory()->create([
            'payer_type' => 'user',
            'payer_id' => $user->id,
            'currency' => 'EUR',
        ]);

        expect($order->currency)->toBe('EUR');
    });

    it('supports USD currency', function () {
        $user = User::factory()->create();
        $order = Order::factory()->create([
            'payer_type' => 'user',
            'payer_id' => $user->id,
            'currency' => 'USD',
        ]);

        expect($order->currency)->toBe('USD');
    });
});
