<?php

declare(strict_types=1);

use App\Enums\OrderStatus;
use App\Models\CreditLedger;
use App\Models\License;
use App\Models\Order;
use App\Models\Organization;
use App\Models\User;
use App\Models\UserLicense;

/*
|--------------------------------------------------------------------------
| Profile Page Rendering Tests
|--------------------------------------------------------------------------
| These tests verify that profile pages actually RENDER without errors
| when accessed with real data. They catch runtime errors, missing view
| data, and type mismatches that simple route tests may miss.
|
| Focus on testing pages with:
| - Enums (OrderStatus)
| - JSON fields
| - Complex relationships
| - Nullable data scenarios
*/

beforeEach(function () {
    $this->user = User::factory()->create([
        'email_verified_at' => now(),
        'credits' => 100,
    ]);
});

/*
|--------------------------------------------------------------------------
| Profile Plans Page Rendering
|--------------------------------------------------------------------------
*/

describe('Profile::Plans::Rendering', function () {
    it('renders plans page without errors when user has no licenses', function () {
        $response = $this->actingAs($this->user)
            ->get('/profile/plans');

        $response->assertOk();
        $response->assertViewIs('profile.plans');
    });

    it('renders plans page with active user license', function () {
        $license = License::factory()->create(['tier' => 'premium']);

        UserLicense::factory()->create([
            'user_id' => $this->user->id,
            'license_id' => $license->id,
            'status' => 'active',
            'is_current' => true,
        ]);

        $response = $this->actingAs($this->user)
            ->get('/profile/plans');

        $response->assertOk();
    });

    it('renders plans page with credit ledger entries', function () {
        CreditLedger::create([
            'user_id' => $this->user->id,
            'delta' => 50,
            'reason' => 'purchase',
            'balance_after' => 150,
        ]);

        CreditLedger::create([
            'user_id' => $this->user->id,
            'delta' => -10,
            'reason' => 'usage',
            'balance_after' => 140,
        ]);

        $response = $this->actingAs($this->user)
            ->get('/profile/plans');

        $response->assertOk();
    });

    it('renders plans page with organization membership', function () {
        $organization = Organization::factory()->create();
        $this->user->organizations()->attach($organization, [
            'role' => \App\Enums\OrganizationRole::Editor->value,
            'joined_at' => now(),
        ]);

        $organization->creditPool()->create(['balance' => 500]);

        $response = $this->actingAs($this->user)
            ->get('/profile/plans');

        $response->assertOk();
    });

    it('renders plans page with primaryLicenseData', function () {
        $license = License::factory()->create([
            'tier' => 'premium',
            'billing_cycle' => 'monthly',
            'credit_reset_interval' => 'monthly',
            'credits' => 1000,
        ]);

        UserLicense::factory()->create([
            'user_id' => $this->user->id,
            'license_id' => $license->id,
            'status' => 'active',
            'is_current' => true,
            'starts_at' => now()->subMonth(),
        ]);

        $response = $this->actingAs($this->user)
            ->get('/profile/plans');

        $response->assertOk();
        $response->assertViewHas('primaryLicenseData', function ($data) {
            return $data['exists'] === true && $data['tier'] === 'premium';
        });
    });
});

/*
|--------------------------------------------------------------------------
| Profile Invoice Page Rendering
|--------------------------------------------------------------------------
*/

describe('Profile::Invoice::Rendering', function () {
    it('renders invoices page without errors when user has no orders', function () {
        $response = $this->actingAs($this->user)
            ->get('/profile/invoices');

        $response->assertOk();
        $response->assertViewIs('profile.invoice');
    });

    it('renders invoices page with orders using Enum status', function () {
        $license = License::factory()->create();

        // Create orders with various Enum statuses
        Order::factory()->create([
            'payer_type' => 'user',
            'payer_id' => $this->user->id,
            'license_id' => $license->id,
            'status' => OrderStatus::Paid,
            'invoice_number' => 'INV-001',
            'invoice_date' => now(),
        ]);

        Order::factory()->create([
            'payer_type' => 'user',
            'payer_id' => $this->user->id,
            'license_id' => $license->id,
            'status' => OrderStatus::Pending,
        ]);

        Order::factory()->create([
            'payer_type' => 'user',
            'payer_id' => $this->user->id,
            'license_id' => $license->id,
            'status' => OrderStatus::Failed,
        ]);

        $response = $this->actingAs($this->user)
            ->get('/profile/invoices');

        $response->assertOk();
        $response->assertSee('INV-001');
    });

    it('renders invoices page with common OrderStatus enum values', function () {
        $license = License::factory()->create();

        // Test common statuses that are definitely in the database enum
        foreach ([OrderStatus::Initiated, OrderStatus::Pending, OrderStatus::Paid, OrderStatus::Failed, OrderStatus::Canceled] as $status) {
            Order::factory()->create([
                'payer_type' => 'user',
                'payer_id' => $this->user->id,
                'license_id' => $license->id,
                'status' => $status,
            ]);
        }

        $response = $this->actingAs($this->user)
            ->get('/profile/invoices');

        $response->assertOk();
    });

    it('renders invoices page with organization orders for admin', function () {
        $organization = Organization::factory()->create();
        $this->user->organizations()->attach($organization, [
            'role' => \App\Enums\OrganizationRole::Owner->value,
            'joined_at' => now(),
        ]);

        $license = License::factory()->create();

        Order::factory()->create([
            'payer_type' => 'organization',
            'payer_id' => $organization->id,
            'license_id' => $license->id,
            'status' => OrderStatus::Paid,
            'invoice_number' => 'INV-ORG-001',
            'invoice_date' => now(),
        ]);

        $response = $this->actingAs($this->user)
            ->get('/profile/invoices');

        $response->assertOk();
        $response->assertSee('INV-ORG-001');
    });
});


/*
|--------------------------------------------------------------------------
| Profile Account Page Rendering
|--------------------------------------------------------------------------
*/

describe('Profile::Account::Rendering', function () {
    it('renders account page without errors', function () {
        $response = $this->actingAs($this->user)
            ->get('/profile/account');

        $response->assertOk();
        $response->assertViewIs('profile.account');
    });

    it('renders account page with all user fields populated', function () {
        $this->user->update([
            'name' => 'Test User',
            'country' => 'NL',
            'preferred_language' => 'nl',
        ]);

        $response = $this->actingAs($this->user)
            ->get('/profile/account');

        $response->assertOk();
        $response->assertSee('Test User');
    });
});

/*
|--------------------------------------------------------------------------
| Profile API Tokens Page Rendering
|--------------------------------------------------------------------------
*/

describe('Profile::ApiTokens::Rendering', function () {
    it('renders api tokens page without errors', function () {
        $response = $this->actingAs($this->user)
            ->get('/profile/api-tokens');

        $response->assertOk();
        $response->assertViewIs('profile.api-tokens');
    });
});

/*
|--------------------------------------------------------------------------
| Profile Webhooks Page Rendering
|--------------------------------------------------------------------------
*/

describe('Profile::Webhooks::Rendering', function () {
    it('renders webhooks page without errors', function () {
        $response = $this->actingAs($this->user)
            ->get('/profile/webhooks');

        $response->assertOk();
        $response->assertViewIs('profile.webhooks');
    });
});
