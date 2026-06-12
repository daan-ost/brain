<?php

declare(strict_types=1);

use App\Filament\Pages\ManualLicenseGrant;
use App\Models\CreditLedger;
use App\Models\License;
use App\Models\Organization;
use App\Models\OrganizationCreditLedger;
use App\Models\OrganizationCreditPool;
use App\Models\OrganizationLicense;
use App\Models\User;
use App\Models\UserLicense;
use Livewire\Livewire;

beforeEach(function () {
    $this->admin = User::factory()->create(['is_admin' => true]);
    $this->actingAs($this->admin, 'admin');
});

describe('ManualLicenseGrant::Rendering', function () {
    it('renders the page for admin users', function () {
        $this->get(ManualLicenseGrant::getUrl())
            ->assertSuccessful();
    });

    it('denies access to non-admin users', function () {
        $regularUser = User::factory()->create(['is_admin' => false]);
        $this->actingAs($regularUser, 'admin');

        $this->get(ManualLicenseGrant::getUrl())
            ->assertForbidden();
    });

    it('renders form with expected fields', function () {
        Livewire::test(ManualLicenseGrant::class)
            ->assertFormFieldExists('grantType')
            ->assertFormFieldExists('userId')
            ->assertFormFieldExists('licenseId')
            ->assertFormFieldExists('status')
            ->assertFormFieldExists('startsAt')
            ->assertFormFieldExists('endsAt')
            ->assertFormFieldExists('initialCredits')
            ->assertFormFieldExists('source')
            ->assertFormFieldExists('externalRef')
            ->assertFormFieldExists('notes');
    });
});

describe('ManualLicenseGrant::UserLicense', function () {
    it('grants a license to a user', function () {
        $user = User::factory()->create();
        $license = License::factory()->create();

        Livewire::test(ManualLicenseGrant::class)
            ->set('grantType', 'user')
            ->set('userId', $user->id)
            ->set('licenseId', $license->id)
            ->set('status', 'active')
            ->set('startsAt', now()->format('Y-m-d'))
            ->set('source', 'admin_grant')
            ->call('grantLicense')
            ->assertHasNoErrors()
            ->assertNotified('License granted successfully');

        $userLicense = UserLicense::where('user_id', $user->id)
            ->where('license_id', $license->id)
            ->first();

        expect($userLicense)->not->toBeNull();
        expect($userLicense->is_current)->toBeTrue();
        expect($userLicense->status)->toBe('active');
        expect($userLicense->source)->toBe('admin_grant');
    });

    it('deactivates existing current licenses when granting new one', function () {
        $user = User::factory()->create();
        $oldLicense = License::factory()->create();
        $newLicense = License::factory()->create();

        $existingUserLicense = UserLicense::factory()->create([
            'user_id' => $user->id,
            'license_id' => $oldLicense->id,
            'is_current' => true,
            'status' => 'active',
        ]);

        Livewire::test(ManualLicenseGrant::class)
            ->set('grantType', 'user')
            ->set('userId', $user->id)
            ->set('licenseId', $newLicense->id)
            ->set('status', 'active')
            ->set('startsAt', now()->format('Y-m-d'))
            ->set('source', 'admin_grant')
            ->call('grantLicense')
            ->assertNotified('License granted successfully');

        $existingUserLicense->refresh();
        expect($existingUserLicense->is_current)->toBeFalse();

        $newUserLicense = UserLicense::where('user_id', $user->id)
            ->where('license_id', $newLicense->id)
            ->first();
        expect($newUserLicense->is_current)->toBeTrue();
    });

    it('grants initial credits and creates ledger entry', function () {
        $user = User::factory()->create(['credits' => 50]);
        $license = License::factory()->create(['credits' => 200]);

        Livewire::test(ManualLicenseGrant::class)
            ->set('grantType', 'user')
            ->set('userId', $user->id)
            ->set('licenseId', $license->id)
            ->set('status', 'active')
            ->set('startsAt', now()->format('Y-m-d'))
            ->set('source', 'admin_grant')
            ->set('initialCredits', 200)
            ->set('notes', 'Test grant with credits')
            ->call('grantLicense')
            ->assertNotified('License granted successfully');

        $user->refresh();
        expect($user->credits)->toBe(250);

        $ledger = CreditLedger::where('user_id', $user->id)->latest('id')->first();
        expect($ledger)->not->toBeNull();
        expect($ledger->delta)->toBe(200);
        expect($ledger->reason)->toBe('bonus');
        expect($ledger->balance_after)->toBe(250);
    });

    it('creates ledger entry with correct meta data', function () {
        $user = User::factory()->create(['credits' => 0]);
        $license = License::factory()->create();

        Livewire::test(ManualLicenseGrant::class)
            ->set('grantType', 'user')
            ->set('userId', $user->id)
            ->set('licenseId', $license->id)
            ->set('status', 'active')
            ->set('startsAt', now()->format('Y-m-d'))
            ->set('source', 'admin_grant')
            ->set('initialCredits', 100)
            ->set('notes', 'Partner deal credits')
            ->call('grantLicense');

        $ledger = CreditLedger::where('user_id', $user->id)->latest('id')->first();
        $userLicense = UserLicense::where('user_id', $user->id)->first();

        expect($ledger->meta['source'])->toBe('manual_license_grant');
        expect($ledger->meta['license_id'])->toBe($license->id);
        expect($ledger->meta['license_assignment_id'])->toBe($userLicense->id);
        expect($ledger->meta['admin_id'])->toBe($this->admin->id);
        expect($ledger->meta['notes'])->toBe('Partner deal credits');
    });

    it('does not create ledger entry when no credits specified', function () {
        $user = User::factory()->create(['credits' => 50]);
        $license = License::factory()->create();

        Livewire::test(ManualLicenseGrant::class)
            ->set('grantType', 'user')
            ->set('userId', $user->id)
            ->set('licenseId', $license->id)
            ->set('status', 'active')
            ->set('startsAt', now()->format('Y-m-d'))
            ->set('source', 'admin_grant')
            ->set('initialCredits', null)
            ->call('grantLicense');

        $user->refresh();
        expect($user->credits)->toBe(50);
        expect(CreditLedger::where('user_id', $user->id)->count())->toBe(0);
    });

    it('computes correct balance_after for user with existing credits', function () {
        $user = User::factory()->create(['credits' => 300]);
        $license = License::factory()->create();

        Livewire::test(ManualLicenseGrant::class)
            ->set('grantType', 'user')
            ->set('userId', $user->id)
            ->set('licenseId', $license->id)
            ->set('status', 'active')
            ->set('startsAt', now()->format('Y-m-d'))
            ->set('source', 'admin_grant')
            ->set('initialCredits', 150)
            ->call('grantLicense');

        $ledger = CreditLedger::where('user_id', $user->id)->latest('id')->first();
        expect($ledger->balance_after)->toBe(450);
    });
});

describe('ManualLicenseGrant::OrganizationLicense', function () {
    it('grants a license to an organization', function () {
        $organization = Organization::factory()->create();
        $license = License::factory()->create();

        Livewire::test(ManualLicenseGrant::class)
            ->set('grantType', 'organization')
            ->set('organizationId', $organization->id)
            ->set('licenseId', $license->id)
            ->set('status', 'active')
            ->set('startsAt', now()->format('Y-m-d'))
            ->set('source', 'admin_grant')
            ->call('grantLicense')
            ->assertNotified('License granted successfully');

        $orgLicense = OrganizationLicense::where('organization_id', $organization->id)
            ->where('license_id', $license->id)
            ->first();

        expect($orgLicense)->not->toBeNull();
        expect($orgLicense->is_current)->toBeTrue();
        expect($orgLicense->billing_method)->toBe('manual');
        expect($orgLicense->payment_status)->toBe('paid');
    });

    it('deactivates existing current org licenses when granting new one', function () {
        $organization = Organization::factory()->create();
        $oldLicense = License::factory()->create();
        $newLicense = License::factory()->create();

        $existingOrgLicense = OrganizationLicense::factory()->create([
            'organization_id' => $organization->id,
            'license_id' => $oldLicense->id,
            'is_current' => true,
            'status' => 'active',
        ]);

        Livewire::test(ManualLicenseGrant::class)
            ->set('grantType', 'organization')
            ->set('organizationId', $organization->id)
            ->set('licenseId', $newLicense->id)
            ->set('status', 'active')
            ->set('startsAt', now()->format('Y-m-d'))
            ->set('source', 'admin_grant')
            ->call('grantLicense')
            ->assertNotified('License granted successfully');

        $existingOrgLicense->refresh();
        expect($existingOrgLicense->is_current)->toBeFalse();

        $newOrgLicense = OrganizationLicense::where('organization_id', $organization->id)
            ->where('license_id', $newLicense->id)
            ->first();
        expect($newOrgLicense->is_current)->toBeTrue();
    });

    it('grants credits to organization credit pool', function () {
        $organization = Organization::factory()->withCreditPool(100)->create();
        $license = License::factory()->create();

        Livewire::test(ManualLicenseGrant::class)
            ->set('grantType', 'organization')
            ->set('organizationId', $organization->id)
            ->set('licenseId', $license->id)
            ->set('status', 'active')
            ->set('startsAt', now()->format('Y-m-d'))
            ->set('source', 'admin_grant')
            ->set('initialCredits', 500)
            ->call('grantLicense')
            ->assertNotified('License granted successfully');

        $organization->refresh();
        expect($organization->creditPool->balance_credits)->toBe(600);
    });

    it('creates credit pool if none exists when granting org credits', function () {
        $organization = Organization::factory()->create();
        $license = License::factory()->create();

        // Verify no pool exists
        expect($organization->creditPool)->toBeNull();

        Livewire::test(ManualLicenseGrant::class)
            ->set('grantType', 'organization')
            ->set('organizationId', $organization->id)
            ->set('licenseId', $license->id)
            ->set('status', 'active')
            ->set('startsAt', now()->format('Y-m-d'))
            ->set('source', 'admin_grant')
            ->set('initialCredits', 250)
            ->call('grantLicense')
            ->assertNotified('License granted successfully');

        $organization->refresh();
        expect($organization->creditPool)->not->toBeNull();
        expect($organization->creditPool->balance_credits)->toBe(250);
    });

    it('creates OrganizationCreditLedger entry for org grants', function () {
        $organization = Organization::factory()->withCreditPool(0)->create();
        $license = License::factory()->create();

        Livewire::test(ManualLicenseGrant::class)
            ->set('grantType', 'organization')
            ->set('organizationId', $organization->id)
            ->set('licenseId', $license->id)
            ->set('status', 'active')
            ->set('startsAt', now()->format('Y-m-d'))
            ->set('source', 'admin_grant')
            ->set('initialCredits', 300)
            ->set('notes', 'Org partner deal')
            ->call('grantLicense');

        $ledger = OrganizationCreditLedger::where('organization_id', $organization->id)
            ->latest('id')
            ->first();

        expect($ledger)->not->toBeNull();
        expect($ledger->delta)->toBe(300);
        expect($ledger->reason)->toBe('bonus');
        expect($ledger->balance_after)->toBe(300);
        expect($ledger->user_id)->toBe($this->admin->id);
        expect($ledger->meta['source'])->toBe('manual_license_grant');
        expect($ledger->meta['admin_id'])->toBe($this->admin->id);
        expect($ledger->meta['notes'])->toBe('Org partner deal');
    });
});

describe('ManualLicenseGrant::ErrorHandling', function () {
    it('shows error notification for non-existent user', function () {
        $license = License::factory()->create();

        Livewire::test(ManualLicenseGrant::class)
            ->set('grantType', 'user')
            ->set('userId', 99999)
            ->set('licenseId', $license->id)
            ->set('status', 'active')
            ->set('startsAt', now()->format('Y-m-d'))
            ->set('source', 'admin_grant')
            ->call('grantLicense')
            ->assertNotified('Failed to grant license');
    });

    it('shows error notification for non-existent license', function () {
        $user = User::factory()->create();

        Livewire::test(ManualLicenseGrant::class)
            ->set('grantType', 'user')
            ->set('userId', $user->id)
            ->set('licenseId', 99999)
            ->set('status', 'active')
            ->set('startsAt', now()->format('Y-m-d'))
            ->set('source', 'admin_grant')
            ->call('grantLicense')
            ->assertNotified('Failed to grant license');
    });

    it('resets form fields after successful grant', function () {
        $user = User::factory()->create();
        $license = License::factory()->create();

        $component = Livewire::test(ManualLicenseGrant::class)
            ->set('grantType', 'user')
            ->set('userId', $user->id)
            ->set('licenseId', $license->id)
            ->set('status', 'active')
            ->set('startsAt', now()->format('Y-m-d'))
            ->set('source', 'promotional')
            ->set('externalRef', 'TICKET-123')
            ->set('notes', 'Test notes')
            ->set('initialCredits', 100)
            ->call('grantLicense')
            ->assertNotified('License granted successfully');

        expect($component->get('userId'))->toBeNull();
        expect($component->get('licenseId'))->toBeNull();
        expect($component->get('externalRef'))->toBeNull();
        expect($component->get('notes'))->toBeNull();
        expect($component->get('initialCredits'))->toBeNull();
        expect($component->get('status'))->toBe('active');
        expect($component->get('source'))->toBe('admin_grant');
    });
});
