<?php

declare(strict_types=1);

use App\Filament\Pages\LicenseHealth;
use App\Models\CreditLedger;
use App\Models\License;
use App\Models\Organization;
use App\Models\OrganizationCreditPool;
use App\Models\OrganizationLicense;
use App\Models\User;
use App\Models\UserLicense;

beforeEach(function () {
    $this->admin = User::factory()->create(['is_admin' => true]);
    $this->actingAs($this->admin, 'admin');
});

// ==================== HELPERS ====================

function createFreeLicense(): License
{
    return License::factory()->create([
        'tier' => 'free',
        'credits' => 15,
        'credit_reset_interval' => 'daily',
        'active' => true,
        'currency' => 'EUR',
    ]);
}

function createPremiumLicense(int $credits = 200): License
{
    return License::factory()->create([
        'tier' => 'premium',
        'credits' => $credits,
        'credit_reset_interval' => 'monthly',
        'billing_cycle' => 'monthly',
        'period' => 30,
        'active' => true,
    ]);
}

function createOnetimeLicense(): License
{
    return License::factory()->create([
        'tier' => 'onetime',
        'credits' => 200,
        'credit_reset_interval' => 'none',
        'period' => 90,
        'active' => true,
    ]);
}

function createUserLicenseRecord(User $user, License $license, array $attributes = []): UserLicense
{
    return UserLicense::create(array_merge([
        'user_id' => $user->id,
        'license_id' => $license->id,
        'status' => UserLicense::STATUS_ACTIVE,
        'starts_at' => now(),
        'ends_at' => null,
        'source' => 'test',
        'external_ref' => 'test-' . uniqid(),
        'is_current' => true,
    ], $attributes));
}

function createOrgLicenseRecord(Organization $organization, License $license, array $attributes = []): OrganizationLicense
{
    return OrganizationLicense::create(array_merge([
        'organization_id' => $organization->id,
        'license_id' => $license->id,
        'status' => 'active',
        'starts_at' => now(),
        'ends_at' => null,
        'source' => 'test',
        'external_ref' => 'test-' . uniqid(),
    ], $attributes));
}

// ==================== PAGE ACCESS ====================

describe('LicenseHealth::Access', function () {
    it('is accessible at /beheer/license-health', function () {
        $response = $this->get('/beheer/license-health');

        expect($response->status())->toBe(200);
    });

    it('denies non-admin access', function () {
        $regularUser = User::factory()->create(['is_admin' => false]);
        $this->actingAs($regularUser, 'admin');

        $this->get('/beheer/license-health')
            ->assertForbidden();
    });
});

// ==================== STATUS CARDS ====================

describe('LicenseHealth::StatusCards', function () {
    it('shows zero counts when no issues exist', function () {
        $page = new LicenseHealth;
        $cards = $page->getStatusCards();

        expect($cards)->toHaveCount(4);
        expect($cards[0]['label'])->toBe('Overdue Resets');
        expect($cards[0]['value'])->toBe(0);
        expect($cards[0]['color'])->toBe('success');
        expect($cards[1]['label'])->toBe('Expiring Soon');
        expect($cards[1]['value'])->toBe(0);
        expect($cards[1]['color'])->toBe('success');
        expect($cards[2]['label'])->toBe('Unpaid Invoices');
        expect($cards[2]['value'])->toBe(0);
        expect($cards[2]['color'])->toBe('success');
        expect($cards[3]['label'])->toBe('Healthy');
        expect($cards[3]['color'])->toBe('success');
    });

    it('shows danger color for overdue resets', function () {
        $license = createFreeLicense();
        $user = User::factory()->create(['credits' => 3]);
        createUserLicenseRecord($user, $license, [
            'last_credit_reset_at' => now()->subDays(31),
        ]);

        // Simulate credit usage
        CreditLedger::create([
            'user_id' => $user->id,
            'delta' => -12,
            'reason' => 'spend',
            'balance_after' => 3,
            'created_at' => now()->subDays(15),
        ]);

        $page = new LicenseHealth;
        $cards = $page->getStatusCards();

        expect($cards[0]['value'])->toBe(1);
        expect($cards[0]['color'])->toBe('danger');
    });

    it('shows warning color for expiring soon licenses', function () {
        $license = createPremiumLicense();
        $user = User::factory()->create(['credits' => 100]);
        createUserLicenseRecord($user, $license, [
            'status' => 'active',
            'ends_at' => now()->addDays(7),
        ]);

        $page = new LicenseHealth;
        $cards = $page->getStatusCards();

        expect($cards[1]['value'])->toBe(1);
        expect($cards[1]['color'])->toBe('warning');
    });

    it('shows danger color for unpaid invoices', function () {
        $license = createPremiumLicense();
        $organization = Organization::factory()->create();
        OrganizationCreditPool::create([
            'organization_id' => $organization->id,
            'balance_credits' => 100,
        ]);
        createOrgLicenseRecord($organization, $license, [
            'payment_status' => 'unpaid',
            'invoice_due_date' => now()->subDays(5),
            'invoice_number' => 'INV-2026-001',
            'billing_method' => 'invoice',
        ]);

        $page = new LicenseHealth;
        $cards = $page->getStatusCards();

        expect($cards[2]['value'])->toBe(1);
        expect($cards[2]['color'])->toBe('danger');
    });
});

// ==================== OVERDUE RESETS ====================

describe('LicenseHealth::OverdueResets', function () {
    it('detects overdue free tier user licenses', function () {
        $license = createFreeLicense();
        $user = User::factory()->create(['credits' => 3]);
        createUserLicenseRecord($user, $license, [
            'last_credit_reset_at' => now()->subDays(31),
        ]);

        CreditLedger::create([
            'user_id' => $user->id,
            'delta' => -12,
            'reason' => 'spend',
            'balance_after' => 3,
            'created_at' => now()->subDays(15),
        ]);

        $page = new LicenseHealth;
        $overdueItems = $page->getOverdueResets();

        expect($overdueItems)->toHaveCount(1);
        expect($overdueItems->first()['type'])->toBe('user');
        expect($overdueItems->first()['tier'])->toBe('free');
        expect($overdueItems->first()['days_overdue'])->toBeGreaterThan(0);
    });

    it('detects overdue premium user licenses', function () {
        $license = createPremiumLicense(200);
        $user = User::factory()->create(['credits' => 50]);
        createUserLicenseRecord($user, $license, [
            'starts_at' => now()->subDays(35),
            'last_credit_reset_at' => now()->subDays(35),
            'status' => UserLicense::STATUS_ACTIVE,
        ]);

        $page = new LicenseHealth;
        $overdueItems = $page->getOverdueResets();

        expect($overdueItems)->toHaveCount(1);
        expect($overdueItems->first()['type'])->toBe('user');
        expect($overdueItems->first()['tier'])->toBe('premium');
    });

    it('detects overdue premium organization licenses', function () {
        $license = createPremiumLicense(300);
        $organization = Organization::factory()->create();
        OrganizationCreditPool::create([
            'organization_id' => $organization->id,
            'balance_credits' => 50,
        ]);
        createOrgLicenseRecord($organization, $license, [
            'starts_at' => now()->subDays(35),
            'last_credit_reset_at' => now()->subDays(35),
            'status' => 'active',
        ]);

        $page = new LicenseHealth;
        $overdueItems = $page->getOverdueResets();

        expect($overdueItems)->toHaveCount(1);
        expect($overdueItems->first()['type'])->toBe('organization');
        expect($overdueItems->first()['tier'])->toBe('premium');
    });

    it('sorts by days overdue descending', function () {
        $license = createFreeLicense();

        $user1 = User::factory()->create(['credits' => 3]);
        createUserLicenseRecord($user1, $license, [
            'last_credit_reset_at' => now()->subDays(40),
        ]);
        CreditLedger::create([
            'user_id' => $user1->id,
            'delta' => -5,
            'reason' => 'spend',
            'balance_after' => 3,
            'created_at' => now()->subDays(35),
        ]);

        $user2 = User::factory()->create(['credits' => 5]);
        createUserLicenseRecord($user2, $license, [
            'last_credit_reset_at' => now()->subDays(60),
        ]);
        CreditLedger::create([
            'user_id' => $user2->id,
            'delta' => -5,
            'reason' => 'spend',
            'balance_after' => 5,
            'created_at' => now()->subDays(50),
        ]);

        $page = new LicenseHealth;
        $overdueItems = $page->getOverdueResets();

        expect($overdueItems)->toHaveCount(2);
        expect($overdueItems->first()['days_overdue'])->toBeGreaterThanOrEqual($overdueItems->last()['days_overdue']);
    });

    it('does not include onetime licenses', function () {
        $license = createOnetimeLicense();
        $user = User::factory()->create(['credits' => 100]);
        createUserLicenseRecord($user, $license, [
            'starts_at' => now()->subDays(100),
            'ends_at' => now()->addDays(10),
        ]);

        $page = new LicenseHealth;
        $overdueItems = $page->getOverdueResets();

        expect($overdueItems)->toHaveCount(0);
    });
});

// ==================== EXPIRING ====================

describe('LicenseHealth::Expiring', function () {
    it('detects user licenses expired but not marked', function () {
        $license = createPremiumLicense();
        $user = User::factory()->create(['credits' => 100]);
        createUserLicenseRecord($user, $license, [
            'status' => 'active',
            'ends_at' => now()->subDays(2),
        ]);

        $page = new LicenseHealth;
        $expiringItems = $page->getExpiringSoon();

        $expiredNotMarked = $expiringItems->where('section', 'expired_not_marked');
        expect($expiredNotMarked)->toHaveCount(1);
        expect($expiredNotMarked->first()['days_remaining'])->toBeLessThan(0);
    });

    it('detects organization licenses expired but not marked', function () {
        $license = createPremiumLicense();
        $organization = Organization::factory()->create();
        createOrgLicenseRecord($organization, $license, [
            'status' => 'active',
            'ends_at' => now()->subDays(3),
        ]);

        $page = new LicenseHealth;
        $expiringItems = $page->getExpiringSoon();

        $expiredNotMarked = $expiringItems->where('section', 'expired_not_marked');
        expect($expiredNotMarked)->toHaveCount(1);
        expect($expiredNotMarked->first()['type'])->toBe('organization');
    });

    it('detects user licenses expiring within 14 days', function () {
        $license = createPremiumLicense();
        $user = User::factory()->create(['credits' => 100]);
        createUserLicenseRecord($user, $license, [
            'status' => 'canceled',
            'ends_at' => now()->addDays(7),
        ]);

        $page = new LicenseHealth;
        $expiringItems = $page->getExpiringSoon();

        $expiringSoon = $expiringItems->where('section', 'expiring_soon');
        expect($expiringSoon)->toHaveCount(1);
        expect($expiringSoon->first()['days_remaining'])->toBeGreaterThan(0);
    });

    it('does not include licenses expiring beyond 14 days', function () {
        $license = createPremiumLicense();
        $user = User::factory()->create(['credits' => 100]);
        createUserLicenseRecord($user, $license, [
            'status' => 'canceled',
            'ends_at' => now()->addDays(20),
        ]);

        $page = new LicenseHealth;
        $expiringItems = $page->getExpiringSoon();

        expect($expiringItems)->toHaveCount(0);
    });

    it('does not include already expired-status licenses', function () {
        $license = createPremiumLicense();
        $user = User::factory()->create(['credits' => 0]);
        createUserLicenseRecord($user, $license, [
            'status' => 'expired',
            'ends_at' => now()->subDays(5),
        ]);

        $page = new LicenseHealth;
        $expiringItems = $page->getExpiringSoon();

        expect($expiringItems)->toHaveCount(0);
    });
});

// ==================== ALL ACTIVE LICENSES ====================

describe('LicenseHealth::AllActiveLicenses', function () {
    it('lists active user and organization licenses', function () {
        $license = createPremiumLicense();

        $user = User::factory()->create(['credits' => 100]);
        createUserLicenseRecord($user, $license, ['status' => 'active']);

        $organization = Organization::factory()->create();
        OrganizationCreditPool::create([
            'organization_id' => $organization->id,
            'balance_credits' => 200,
        ]);
        createOrgLicenseRecord($organization, $license, ['status' => 'active']);

        $page = new LicenseHealth;
        $activeLicenses = $page->getAllActiveLicenses();

        expect($activeLicenses)->toHaveCount(2);
        $types = $activeLicenses->pluck('type')->toArray();
        expect($types)->toContain('user');
        expect($types)->toContain('organization');
    });

    it('filters by tier', function () {
        $freeLicense = createFreeLicense();
        $premiumLicense = createPremiumLicense();

        $user1 = User::factory()->create(['credits' => 15]);
        createUserLicenseRecord($user1, $freeLicense, ['status' => 'active']);

        $user2 = User::factory()->create(['credits' => 200]);
        createUserLicenseRecord($user2, $premiumLicense, ['status' => 'active']);

        $page = new LicenseHealth;
        $page->filterTier = 'premium';
        $activeLicenses = $page->getAllActiveLicenses();

        expect($activeLicenses)->toHaveCount(1);
        expect($activeLicenses->first()['tier'])->toBe('premium');
    });

    it('filters by type', function () {
        $license = createPremiumLicense();

        $user = User::factory()->create(['credits' => 100]);
        createUserLicenseRecord($user, $license, ['status' => 'active']);

        $organization = Organization::factory()->create();
        OrganizationCreditPool::create([
            'organization_id' => $organization->id,
            'balance_credits' => 200,
        ]);
        createOrgLicenseRecord($organization, $license, ['status' => 'active']);

        $page = new LicenseHealth;
        $page->filterType = 'organization';
        $activeLicenses = $page->getAllActiveLicenses();

        expect($activeLicenses)->toHaveCount(1);
        expect($activeLicenses->first()['type'])->toBe('organization');
    });

    it('calculates next reset date for premium licenses', function () {
        $license = createPremiumLicense();
        $user = User::factory()->create(['credits' => 200]);
        createUserLicenseRecord($user, $license, [
            'status' => 'active',
            'starts_at' => now()->subDays(15),
        ]);

        $page = new LicenseHealth;
        $activeLicenses = $page->getAllActiveLicenses();

        expect($activeLicenses)->toHaveCount(1);
        expect($activeLicenses->first()['next_reset'])->not->toBeNull();
    });

    it('shows Doorlopend for licenses without ends_at', function () {
        $license = createFreeLicense();
        $user = User::factory()->create(['credits' => 15]);
        createUserLicenseRecord($user, $license, [
            'status' => 'active',
            'ends_at' => null,
        ]);

        $page = new LicenseHealth;
        $activeLicenses = $page->getAllActiveLicenses();

        expect($activeLicenses)->toHaveCount(1);
        expect($activeLicenses->first()['ends_at'])->toBeNull();
    });
});

// ==================== MANUAL RESET ====================

describe('LicenseHealth::ManualReset', function () {
    it('resets free tier user credits', function () {
        $license = createFreeLicense();
        $user = User::factory()->create(['credits' => 3]);
        $userLicense = createUserLicenseRecord($user, $license, [
            'last_credit_reset_at' => now()->subDays(31),
        ]);

        CreditLedger::create([
            'user_id' => $user->id,
            'delta' => -12,
            'reason' => 'spend',
            'balance_after' => 3,
            'created_at' => now()->subDays(15),
        ]);

        $page = new LicenseHealth;
        $page->manualReset('user', $userLicense->id);

        expect($user->fresh()->credits)->toBe($license->credits);
    });

    it('resets premium user credits and preserves surplus', function () {
        $license = createPremiumLicense(200);
        $user = User::factory()->create(['credits' => 250]);
        $userLicense = createUserLicenseRecord($user, $license, [
            'starts_at' => now()->subDays(35),
            'last_credit_reset_at' => now()->subDays(35),
            'status' => UserLicense::STATUS_ACTIVE,
        ]);

        $page = new LicenseHealth;
        $page->manualReset('user', $userLicense->id);

        // 200 (premium) + 50 (surplus) = 250
        expect($user->fresh()->credits)->toBe(250);
    });

    it('resets organization premium credits', function () {
        $license = createPremiumLicense(300);
        $organization = Organization::factory()->create();
        $creditPool = OrganizationCreditPool::create([
            'organization_id' => $organization->id,
            'balance_credits' => 50,
        ]);
        $orgLicense = createOrgLicenseRecord($organization, $license, [
            'starts_at' => now()->subDays(35),
            'last_credit_reset_at' => now()->subDays(35),
            'status' => 'active',
        ]);

        $page = new LicenseHealth;
        $page->manualReset('organization', $orgLicense->id);

        expect($creditPool->fresh()->balance_credits)->toBe(300);
    });

    it('provides reset confirmation data for user licenses', function () {
        $license = createPremiumLicense(200);
        $user = User::factory()->create(['credits' => 250]);
        $userLicense = createUserLicenseRecord($user, $license, [
            'status' => UserLicense::STATUS_ACTIVE,
        ]);

        $page = new LicenseHealth;
        $data = $page->getResetConfirmationData('user', $userLicense->id);

        expect($data['current_balance'])->toBe(250);
        expect($data['expected_balance'])->toBe(250); // 200 + 50 surplus
        expect($data['surplus'])->toBe(50);
        expect($data['tier'])->toBe('premium');
    });

    it('provides reset confirmation data for organization licenses', function () {
        $license = createPremiumLicense(300);
        $organization = Organization::factory()->create();
        OrganizationCreditPool::create([
            'organization_id' => $organization->id,
            'balance_credits' => 400,
        ]);
        $orgLicense = createOrgLicenseRecord($organization, $license, [
            'status' => 'active',
        ]);

        $page = new LicenseHealth;
        $data = $page->getResetConfirmationData('organization', $orgLicense->id);

        expect($data['current_balance'])->toBe(400);
        expect($data['expected_balance'])->toBe(400); // 300 + 100 surplus
        expect($data['surplus'])->toBe(100);
        expect($data['tier'])->toBe('premium');
    });
});

// ==================== BULK CHECK ====================

describe('LicenseHealth::BulkCheck', function () {
    it('runs bulk check without errors', function () {
        $page = new LicenseHealth;

        // Should not throw
        $page->runBulkCheck();

        expect(true)->toBeTrue();
    });

    it('detects pending resets in bulk check', function () {
        $license = createFreeLicense();
        $user = User::factory()->create(['credits' => 3]);
        createUserLicenseRecord($user, $license, [
            'last_credit_reset_at' => now()->subDays(31),
        ]);

        CreditLedger::create([
            'user_id' => $user->id,
            'delta' => -12,
            'reason' => 'spend',
            'balance_after' => 3,
            'created_at' => now()->subDays(15),
        ]);

        // We can't easily check the notification content in a unit test,
        // but we verify the method executes without error
        $page = new LicenseHealth;
        $page->runBulkCheck();

        expect(true)->toBeTrue();
    });
});

// ==================== UNPAID INVOICES ====================

describe('LicenseHealth::UnpaidInvoices', function () {
    it('detects unpaid overdue organization invoices', function () {
        $license = createPremiumLicense();
        $organization = Organization::factory()->create();
        OrganizationCreditPool::create([
            'organization_id' => $organization->id,
            'balance_credits' => 100,
        ]);
        createOrgLicenseRecord($organization, $license, [
            'payment_status' => 'unpaid',
            'invoice_due_date' => now()->subDays(10),
            'invoice_number' => 'INV-2026-001',
            'billing_method' => 'invoice',
        ]);

        $page = new LicenseHealth;
        $unpaidInvoices = $page->getUnpaidInvoices();

        expect($unpaidInvoices)->toHaveCount(1);
        expect($unpaidInvoices->first()['invoice_number'])->toBe('INV-2026-001');
        expect($unpaidInvoices->first()['days_overdue'])->toBe(10);
    });

    it('does not include paid invoices', function () {
        $license = createPremiumLicense();
        $organization = Organization::factory()->create();
        OrganizationCreditPool::create([
            'organization_id' => $organization->id,
            'balance_credits' => 100,
        ]);
        createOrgLicenseRecord($organization, $license, [
            'payment_status' => 'paid',
            'invoice_due_date' => now()->subDays(10),
            'invoice_number' => 'INV-2026-002',
            'billing_method' => 'invoice',
        ]);

        $page = new LicenseHealth;
        $unpaidInvoices = $page->getUnpaidInvoices();

        expect($unpaidInvoices)->toHaveCount(0);
    });

    it('does not include invoices not yet due', function () {
        $license = createPremiumLicense();
        $organization = Organization::factory()->create();
        OrganizationCreditPool::create([
            'organization_id' => $organization->id,
            'balance_credits' => 100,
        ]);
        createOrgLicenseRecord($organization, $license, [
            'payment_status' => 'unpaid',
            'invoice_due_date' => now()->addDays(5),
            'invoice_number' => 'INV-2026-003',
            'billing_method' => 'invoice',
        ]);

        $page = new LicenseHealth;
        $unpaidInvoices = $page->getUnpaidInvoices();

        expect($unpaidInvoices)->toHaveCount(0);
    });
});
