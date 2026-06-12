<?php

declare(strict_types=1);

use App\Enums\OrderStatus;
use App\Models\License;
use App\Models\Order;
use App\Models\Organization;
use App\Models\User;
use App\Models\UserLicense;

/*
|--------------------------------------------------------------------------
| Shared Test User Setup
|--------------------------------------------------------------------------
| Using a shared user factory setup to reduce repetition.
| RefreshDatabase is already applied globally in Pest.php.
*/

beforeEach(function () {
    $this->user = User::factory()->create([
        'email_verified_at' => now(),
    ]);
});

/*
|--------------------------------------------------------------------------
| Profile Plans Tests
|--------------------------------------------------------------------------
*/

describe('Profile Plans Page', function () {
    it('displays plans page for authenticated user', function () {
        $response = $this->actingAs($this->user)
            ->get('/profile/plans');

        $response->assertOk();
        $response->assertViewIs('profile.plans');
        $response->assertViewHas('user');
        $response->assertViewHas('activeLicenses');
        $response->assertViewHas('creditSummary');
        $response->assertViewHas('paymentSource');
        $response->assertViewHas('primaryLicenseData');
        $response->assertViewHas('canUpgrade');
    });

    it('requires authentication', function () {
        $response = $this->get('/profile/plans');

        $response->assertRedirect('/login');
    });

    it('shows user credits balance in payment source', function () {
        $this->user->update(['credits' => 150]);

        $response = $this->actingAs($this->user)
            ->get('/profile/plans');

        $response->assertOk();
        $response->assertViewHas('paymentSource', function ($paymentSource) {
            return $paymentSource['balance'] === 150;
        });
    });

    it('shows active licenses', function () {
        $license = License::factory()->create([
            'name' => 'Premium Plan',
            'credits' => 1000,
        ]);

        UserLicense::create([
            'user_id' => $this->user->id,
            'license_id' => $license->id,
            'status' => 'active',
            'is_current' => true,
            'starts_at' => now()->subDay(),
        ]);

        $response = $this->actingAs($this->user)
            ->get('/profile/plans');

        $response->assertOk();
        $response->assertViewHas('activeLicenses', function ($licenses) {
            return $licenses->count() >= 1;
        });
    });

    it('shows organization credits when user is member', function () {
        $organization = Organization::factory()->create();
        $this->user->organizations()->attach($organization, ['role' => \App\Enums\OrganizationRole::Editor->value]);

        $organization->creditPool()->create([
            'balance' => 500,
        ]);

        $response = $this->actingAs($this->user)
            ->get('/profile/plans');

        $response->assertOk();
        $response->assertViewHas('creditSummary');
    });

    it('displays primary license data correctly', function () {
        $response = $this->actingAs($this->user)
            ->get('/profile/plans');

        $response->assertOk();
        $primaryLicenseData = $response->viewData('primaryLicenseData');

        expect($primaryLicenseData)->toBeArray();
        expect($primaryLicenseData)->toHaveKey('exists');
    });

    it('redirects /profile/credits to /profile/plans', function () {
        $response = $this->actingAs($this->user)
            ->get('/profile/credits');

        $response->assertRedirect('/profile/plans');
    });
});

/*
|--------------------------------------------------------------------------
| Profile Password Page Tests
|--------------------------------------------------------------------------
*/

describe('Profile Password Page', function () {
    it('displays password page for authenticated user', function () {
        $response = $this->actingAs($this->user)
            ->get('/profile/password');

        $response->assertOk();
        $response->assertViewIs('profile.password');
        $response->assertViewHas('user');
    });

    it('requires authentication', function () {
        $response = $this->get('/profile/password');

        $response->assertRedirect('/login');
    });

    it('contains password update form elements', function () {
        $response = $this->actingAs($this->user)
            ->get('/profile/password');

        $response->assertOk();
        $response->assertSee('current_password', false);
    });
});

/*
|--------------------------------------------------------------------------
| Profile Webhooks Page Tests
|--------------------------------------------------------------------------
*/

describe('Profile Webhooks Page', function () {
    it('displays webhooks page for authenticated user', function () {
        $response = $this->actingAs($this->user)
            ->get('/profile/webhooks');

        $response->assertOk();
        $response->assertViewIs('profile.webhooks');
    });

    it('requires authentication', function () {
        $response = $this->get('/profile/webhooks');

        $response->assertRedirect('/login');
    });

    it('shows empty state when no webhooks configured', function () {
        $response = $this->actingAs($this->user)
            ->get('/profile/webhooks');

        $response->assertOk();
    });
});

/*
|--------------------------------------------------------------------------
| Profile Invoice Page Tests
|--------------------------------------------------------------------------
*/

describe('Profile Invoice Page', function () {
    it('displays invoices page for authenticated user', function () {
        $response = $this->actingAs($this->user)
            ->get('/profile/invoices');

        $response->assertOk();
        $response->assertViewIs('profile.invoice');
        $response->assertViewHas('user');
        $response->assertViewHas('orders');
    });

    it('requires authentication', function () {
        $response = $this->get('/profile/invoices');

        $response->assertRedirect('/login');
    });

    it('shows user invoices', function () {
        $license = License::factory()->create();

        Order::factory()->create([
            'payer_type' => 'user',
            'payer_id' => $this->user->id,
            'license_id' => $license->id,
            'invoice_number' => 'INV-2025-001',
            'invoice_date' => now(),
            'status' => OrderStatus::Paid,
        ]);

        $response = $this->actingAs($this->user)
            ->get('/profile/invoices');

        $response->assertOk();
        $response->assertSee('INV-2025-001');
    });

    it('shows organization invoices for admin', function () {
        $organization = Organization::factory()->create();
        $this->user->organizations()->attach($organization, ['role' => \App\Enums\OrganizationRole::Owner->value]);

        $license = License::factory()->create();

        Order::factory()->create([
            'payer_type' => 'organization',
            'payer_id' => $organization->id,
            'license_id' => $license->id,
            'invoice_number' => 'INV-ORG-2025-001',
            'invoice_date' => now(),
            'status' => OrderStatus::Paid,
        ]);

        $response = $this->actingAs($this->user)
            ->get('/profile/invoices');

        $response->assertOk();
        $response->assertSee('INV-ORG-2025-001');
    });

    it('does not show organization invoices for regular member', function () {
        $organization = Organization::factory()->create();
        $this->user->organizations()->attach($organization, ['role' => \App\Enums\OrganizationRole::Editor->value]);

        $license = License::factory()->create();

        Order::factory()->create([
            'payer_type' => 'organization',
            'payer_id' => $organization->id,
            'license_id' => $license->id,
            'invoice_number' => 'INV-ORG-HIDDEN-001',
            'invoice_date' => now(),
            'status' => OrderStatus::Paid,
        ]);

        $response = $this->actingAs($this->user)
            ->get('/profile/invoices');

        $response->assertOk();
        $response->assertDontSee('INV-ORG-HIDDEN-001');
    });

    it('shows empty state when no invoices', function () {
        $response = $this->actingAs($this->user)
            ->get('/profile/invoices');

        $response->assertOk();
        $orders = $response->viewData('orders');
        expect($orders)->toBeEmpty();
    });

    it('orders invoices by date descending', function () {
        $license = License::factory()->create();

        Order::factory()->create([
            'payer_type' => 'user',
            'payer_id' => $this->user->id,
            'license_id' => $license->id,
            'invoice_number' => 'INV-OLD',
            'invoice_date' => now()->subMonth(),
            'status' => OrderStatus::Paid,
        ]);

        Order::factory()->create([
            'payer_type' => 'user',
            'payer_id' => $this->user->id,
            'license_id' => $license->id,
            'invoice_number' => 'INV-NEW',
            'invoice_date' => now(),
            'status' => OrderStatus::Paid,
        ]);

        $response = $this->actingAs($this->user)
            ->get('/profile/invoices');

        $response->assertOk();
        $orders = $response->viewData('orders');

        expect($orders->first()->invoice_number)->toBe('INV-NEW');
        expect($orders->last()->invoice_number)->toBe('INV-OLD');
    });
});

/*
|--------------------------------------------------------------------------
| Profile Account Page Tests
|--------------------------------------------------------------------------
*/

describe('Profile Account Page', function () {
    it('displays account page for authenticated user', function () {
        $response = $this->actingAs($this->user)
            ->get('/profile/account');

        $response->assertOk();
        $response->assertViewIs('profile.account');
        $response->assertViewHas('user');
    });

    it('requires authentication', function () {
        $response = $this->get('/profile/account');

        $response->assertRedirect('/login');
    });
});

/*
|--------------------------------------------------------------------------
| Profile Edit Redirect Tests
|--------------------------------------------------------------------------
*/

describe('Profile Edit Redirect', function () {
    it('redirects /profile to /profile/account', function () {
        $response = $this->actingAs($this->user)
            ->get('/profile');

        $response->assertRedirect('/profile/account');
    });
});

/*
|--------------------------------------------------------------------------
| Profile API Tokens Page Tests
|--------------------------------------------------------------------------
*/

describe('Profile API Tokens Page', function () {
    it('displays api tokens page for authenticated user', function () {
        $response = $this->actingAs($this->user)
            ->get('/profile/api-tokens');

        $response->assertOk();
        $response->assertViewIs('profile.api-tokens');
    });

    it('requires authentication', function () {
        $response = $this->get('/profile/api-tokens');

        $response->assertRedirect('/login');
    });
});
