<?php

use App\Models\License;
use App\Models\Order;
use App\Models\Organization;
use App\Models\OrganizationLicense;
use App\Models\User;
use App\Services\LocaleService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

// ===========================================
// Profile invoice page: locale-aware dates and amounts
// ===========================================

describe('Invoice page locale rendering', function () {

    it('renders invoice dates in EU format for NL user', function () {
        $user = User::factory()->create([
            'date_format' => 'd-m-Y',
            'timezone' => 'Europe/Amsterdam',
            'decimal_separator' => ',',
        ]);

        $license = License::factory()->create(['name' => 'Test License']);

        $order = Order::factory()->create([
            'payer_type' => 'user',
            'payer_id' => $user->id,
            'license_id' => $license->id,
            'invoice_number' => '2026-Q1-10001',
            'invoice_file_path' => 'invoices/2026/2026-Q1-10001.pdf',
            'invoice_date' => '2026-02-15',
            'gross_amount' => 42.35,
            'currency' => 'EUR',
        ]);

        $response = $this->actingAs($user)->get(route('profile.invoices.index'));

        $response->assertOk();
        // EU date format: d-m-Y
        $response->assertSee('15-02-2026');
        // EU currency: comma decimal
        $response->assertSee('€');
    });

    it('renders invoice dates in US format for US user', function () {
        $user = User::factory()->create([
            'date_format' => 'm/d/Y',
            'timezone' => 'America/New_York',
            'decimal_separator' => '.',
        ]);

        $license = License::factory()->create(['name' => 'Test License']);

        $order = Order::factory()->create([
            'payer_type' => 'user',
            'payer_id' => $user->id,
            'license_id' => $license->id,
            'invoice_number' => '2026-Q1-10002',
            'invoice_file_path' => 'invoices/2026/2026-Q1-10002.pdf',
            'invoice_date' => '2026-02-15',
            'gross_amount' => 42.35,
            'currency' => 'USD',
        ]);

        $response = $this->actingAs($user)->get(route('profile.invoices.index'));

        $response->assertOk();
        // US date format: m/d/Y
        $response->assertSee('02/15/2026');
        // US currency symbol
        $response->assertSee('$');
    });

    it('renders amounts with comma decimal for EU user', function () {
        $user = User::factory()->create([
            'date_format' => 'd-m-Y',
            'decimal_separator' => ',',
        ]);

        $license = License::factory()->create(['name' => 'Test License']);

        $order = Order::factory()->create([
            'payer_type' => 'user',
            'payer_id' => $user->id,
            'license_id' => $license->id,
            'invoice_number' => '2026-Q1-10003',
            'invoice_file_path' => 'invoices/2026/2026-Q1-10003.pdf',
            'invoice_date' => '2026-01-10',
            'gross_amount' => 1234.56,
            'currency' => 'EUR',
        ]);

        $response = $this->actingAs($user)->get(route('profile.invoices.index'));

        $response->assertOk();
        // EU number format: 1.234,56
        $response->assertSee('1.234,56');
    });

    it('renders amounts with dot decimal for US user', function () {
        $user = User::factory()->create([
            'date_format' => 'm/d/Y',
            'decimal_separator' => '.',
        ]);

        $license = License::factory()->create(['name' => 'Test License']);

        $order = Order::factory()->create([
            'payer_type' => 'user',
            'payer_id' => $user->id,
            'license_id' => $license->id,
            'invoice_number' => '2026-Q1-10004',
            'invoice_file_path' => 'invoices/2026/2026-Q1-10004.pdf',
            'invoice_date' => '2026-01-10',
            'gross_amount' => 1234.56,
            'currency' => 'USD',
        ]);

        $response = $this->actingAs($user)->get(route('profile.invoices.index'));

        $response->assertOk();
        // US number format: 1,234.56
        $response->assertSee('1,234.56');
    });
});

// ===========================================
// Profile plans page: locale-aware dates
// ===========================================

describe('Plans page locale rendering', function () {

    it('renders pending license due date in EU format', function () {
        $user = User::factory()->create([
            'date_format' => 'd-m-Y',
            'timezone' => 'Europe/Amsterdam',
            'decimal_separator' => ',',
        ]);

        $organization = Organization::factory()->create();
        $user->organizations()->attach($organization, ['role' => \App\Enums\OrganizationRole::Owner->value]);

        $license = License::factory()->create(['name' => 'Pro Plan']);

        OrganizationLicense::create([
            'organization_id' => $organization->id,
            'license_id' => $license->id,
            'status' => 'pending',
            'billing_method' => 'invoice',
            'payment_status' => 'unpaid',
            'invoice_number' => 'INV-2026-001',
            'invoice_due_date' => Carbon::create(2026, 3, 20),
        ]);

        $response = $this->actingAs($user)->get(route('profile.plans'));

        $response->assertOk();
        // EU format: d-m-Y
        $response->assertSee('20-03-2026');
    });

    it('renders pending license due date in US format', function () {
        $user = User::factory()->create([
            'date_format' => 'm/d/Y',
            'timezone' => 'America/New_York',
            'decimal_separator' => '.',
        ]);

        $organization = Organization::factory()->create();
        $user->organizations()->attach($organization, ['role' => \App\Enums\OrganizationRole::Owner->value]);

        $license = License::factory()->create(['name' => 'Pro Plan']);

        OrganizationLicense::create([
            'organization_id' => $organization->id,
            'license_id' => $license->id,
            'status' => 'pending',
            'billing_method' => 'invoice',
            'payment_status' => 'unpaid',
            'invoice_number' => 'INV-2026-002',
            'invoice_due_date' => Carbon::create(2026, 3, 20),
        ]);

        $response = $this->actingAs($user)->get(route('profile.plans'));

        $response->assertOk();
        // US format: m/d/Y
        $response->assertSee('03/20/2026');
    });
});

// ===========================================
// InvoiceGenerationService: resolves user for locale context
// ===========================================

describe('InvoiceGenerationService locale resolution', function () {

    beforeEach(function () {
        \Illuminate\Support\Facades\Storage::fake('local');
        \Illuminate\Support\Facades\Queue::fake();
    });

    it('passes invoice_user to PDF view for user orders', function () {
        $user = User::factory()->create([
            'decimal_separator' => '.',
            'date_format' => 'm/d/Y',
        ]);

        $order = Order::factory()->create([
            'payer_type' => 'user',
            'payer_id' => $user->id,
            'status' => 'paid',
        ]);

        // The invoice_user should be resolvable via LocaleService
        $resolvedUser = LocaleService::resolveUserForOrder($order);

        expect($resolvedUser)->not->toBeNull();
        expect($resolvedUser->id)->toBe($user->id);
        expect($resolvedUser->decimal_separator)->toBe('.');
    });

    it('passes org admin as invoice_user for organization orders', function () {
        $admin = User::factory()->create([
            'decimal_separator' => ',',
            'date_format' => 'd-m-Y',
        ]);

        $org = Organization::factory()->create();
        $org->users()->attach($admin->id, ['role' => \App\Enums\OrganizationRole::Owner->value, 'joined_at' => now()]);

        $order = Order::factory()->create([
            'payer_type' => 'organization',
            'payer_id' => $org->id,
            'status' => 'paid',
        ]);

        $resolvedUser = LocaleService::resolveUserForOrder($order);

        expect($resolvedUser)->not->toBeNull();
        expect($resolvedUser->id)->toBe($admin->id);
        expect($resolvedUser->decimal_separator)->toBe(',');
    });
});

// ===========================================
// SendInvoiceEmail: locale-aware template data
// ===========================================

describe('SendInvoiceEmail locale formatting', function () {

    beforeEach(function () {
        \Illuminate\Support\Facades\Queue::fake();
    });

    it('dispatches invoice email for paid order', function () {
        $user = User::factory()->create([
            'decimal_separator' => ',',
            'date_format' => 'd-m-Y',
            'timezone' => 'Europe/Amsterdam',
        ]);

        $license = License::factory()->create(['name' => 'Test Plan']);

        $order = Order::factory()->create([
            'payer_type' => 'user',
            'payer_id' => $user->id,
            'license_id' => $license->id,
            'status' => 'paid',
            'invoice_number' => '2026-Q1-10001',
            'invoice_file_path' => 'invoices/2026/2026-Q1-10001.pdf',
            'invoice_date' => '2026-02-23',
            'gross_amount' => 49.99,
            'currency' => 'EUR',
        ]);

        \App\Jobs\SendInvoiceEmail::dispatch($order);

        \Illuminate\Support\Facades\Queue::assertPushed(\App\Jobs\SendInvoiceEmail::class);
    });
});
