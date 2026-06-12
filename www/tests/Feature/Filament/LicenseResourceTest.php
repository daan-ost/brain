<?php

declare(strict_types=1);

use App\Filament\Resources\LicenseResource;
use App\Models\License;
use App\Models\User;

beforeEach(function () {
    $this->admin = User::factory()->create(['is_admin' => true]);
    $this->actingAs($this->admin, 'admin');
});

describe('LicenseResource::Authorization', function () {
    it('denies non-admin access to list page', function () {
        $regularUser = User::factory()->create(['is_admin' => false]);
        $this->actingAs($regularUser, 'admin');

        $this->get(LicenseResource::getUrl('index'))
            ->assertForbidden();
    });

    it('denies non-admin access to create page', function () {
        $regularUser = User::factory()->create(['is_admin' => false]);
        $this->actingAs($regularUser, 'admin');

        $this->get(LicenseResource::getUrl('create'))
            ->assertForbidden();
    });

    it('denies non-admin access to edit page', function () {
        $regularUser = User::factory()->create(['is_admin' => false]);
        $license = License::factory()->create();
        $this->actingAs($regularUser, 'admin');

        $this->get(LicenseResource::getUrl('edit', ['record' => $license]))
            ->assertForbidden();
    });

    it('denies non-admin access to view page', function () {
        $regularUser = User::factory()->create(['is_admin' => false]);
        $license = License::factory()->create();
        $this->actingAs($regularUser, 'admin');

        $this->get(LicenseResource::getUrl('view', ['record' => $license]))
            ->assertForbidden();
    });

    it('allows admin access to create page', function () {
        $response = $this->get(LicenseResource::getUrl('create'));

        expect($response->status())->not->toBe(403);
        expect($response->status())->not->toBe(302);
    });

    it('allows admin access to edit page', function () {
        $license = License::factory()->create();

        $response = $this->get(LicenseResource::getUrl('edit', ['record' => $license]));

        expect($response->status())->not->toBe(403);
        expect($response->status())->not->toBe(302);
    });

    it('allows admin access to view page', function () {
        $license = License::factory()->create();

        $response = $this->get(LicenseResource::getUrl('view', ['record' => $license]));

        expect($response->status())->not->toBe(403);
        expect($response->status())->not->toBe(302);
    });
});

describe('LicenseResource::Routes', function () {
    it('has correct index route', function () {
        expect(LicenseResource::getUrl('index'))->toContain('/beheer/licenses');
    });

    it('has correct create route', function () {
        expect(LicenseResource::getUrl('create'))->toContain('/beheer/licenses/create');
    });

    it('has correct edit route', function () {
        $license = License::factory()->create();
        expect(LicenseResource::getUrl('edit', ['record' => $license]))->toContain("/beheer/licenses/{$license->id}/edit");
    });

    it('has correct view route', function () {
        $license = License::factory()->create();
        expect(LicenseResource::getUrl('view', ['record' => $license]))->toContain("/beheer/licenses/{$license->id}");
    });
});

describe('LicenseResource::Model', function () {
    it('uses License model', function () {
        expect(LicenseResource::getModel())->toBe(License::class);
    });

    it('has navigation icon', function () {
        expect(LicenseResource::getNavigationIcon())->toBe('heroicon-o-key');
    });

    it('belongs to Licensing navigation group', function () {
        expect(LicenseResource::getNavigationGroup())->toBe('Licensing');
    });
});

describe('LicenseResource::Pages', function () {
    it('has list page', function () {
        $pages = LicenseResource::getPages();
        expect($pages)->toHaveKey('index');
    });

    it('has create page', function () {
        $pages = LicenseResource::getPages();
        expect($pages)->toHaveKey('create');
    });

    it('has edit page', function () {
        $pages = LicenseResource::getPages();
        expect($pages)->toHaveKey('edit');
    });

    it('has view page', function () {
        $pages = LicenseResource::getPages();
        expect($pages)->toHaveKey('view');
    });
});

describe('LicenseResource::LicenseTiers', function () {
    it('can create free tier license', function () {
        $license = License::factory()->create(['tier' => 'free']);
        expect($license->tier)->toBe('free');
    });

    it('can create premium tier license', function () {
        $license = License::factory()->create(['tier' => 'premium']);
        expect($license->tier)->toBe('premium');
    });

    it('can create enterprise tier license', function () {
        $license = License::factory()->create(['tier' => 'enterprise']);
        expect($license->tier)->toBe('enterprise');
    });

    it('can create onetime tier license', function () {
        $license = License::factory()->create(['tier' => 'onetime']);
        expect($license->tier)->toBe('onetime');
    });

    it('can create test tier license', function () {
        $license = License::factory()->create(['tier' => 'test']);
        expect($license->tier)->toBe('test');
    });
});

describe('LicenseResource::BillingCycles', function () {
    it('can have monthly billing cycle', function () {
        $license = License::factory()->create(['billing_cycle' => 'monthly']);
        expect($license->billing_cycle)->toBe('monthly');
    });

    it('can have yearly billing cycle', function () {
        $license = License::factory()->create(['billing_cycle' => 'yearly']);
        expect($license->billing_cycle)->toBe('yearly');
    });

    it('can have one_time billing cycle', function () {
        $license = License::factory()->create(['billing_cycle' => 'one_time']);
        expect($license->billing_cycle)->toBe('one_time');
    });
});

describe('LicenseResource::CreditResetIntervals', function () {
    it('can have none credit reset', function () {
        $license = License::factory()->create(['credit_reset_interval' => 'none']);
        expect($license->credit_reset_interval)->toBe('none');
    });

    it('can have monthly credit reset', function () {
        $license = License::factory()->create(['credit_reset_interval' => 'monthly']);
        expect($license->credit_reset_interval)->toBe('monthly');
    });

    it('can have yearly credit reset', function () {
        $license = License::factory()->create(['credit_reset_interval' => 'yearly']);
        expect($license->credit_reset_interval)->toBe('yearly');
    });
});

describe('LicenseResource::LicenseStatus', function () {
    it('can be active', function () {
        $license = License::factory()->create(['active' => true]);
        expect($license->active)->toBeTrue();
    });

    it('can be inactive', function () {
        $license = License::factory()->create(['active' => false]);
        expect($license->active)->toBeFalse();
    });
});

describe('LicenseResource::Pricing', function () {
    it('stores amount correctly', function () {
        $license = License::factory()->create(['amount' => 99.99]);
        expect((float) $license->amount)->toBe(99.99);
    });

    it('supports EUR currency', function () {
        $license = License::factory()->create(['currency' => 'EUR']);
        expect($license->currency)->toBe('EUR');
    });

    it('supports USD currency', function () {
        $license = License::factory()->create(['currency' => 'USD']);
        expect($license->currency)->toBe('USD');
    });

    it('stores credits correctly', function () {
        $license = License::factory()->create(['credits' => 500]);
        expect($license->credits)->toBe(500);
    });
});

describe('LicenseResource::ValidityPeriod', function () {
    it('can have valid_from date', function () {
        $date = now()->startOfDay();
        $license = License::factory()->create(['valid_from' => $date]);
        expect($license->valid_from->format('Y-m-d'))->toBe($date->format('Y-m-d'));
    });

    it('can have valid_until date', function () {
        $date = now()->addYear()->startOfDay();
        $license = License::factory()->create(['valid_until' => $date]);
        expect($license->valid_until->format('Y-m-d'))->toBe($date->format('Y-m-d'));
    });

    it('can have null valid_from for unlimited start', function () {
        $license = License::factory()->create(['valid_from' => null]);
        expect($license->valid_from)->toBeNull();
    });

    it('can have null valid_until for unlimited end', function () {
        $license = License::factory()->create(['valid_until' => null]);
        expect($license->valid_until)->toBeNull();
    });
});

describe('LicenseResource::RelationManagers', function () {
    it('has user licenses relation manager', function () {
        $relations = LicenseResource::getRelations();
        $relationClasses = array_map(fn ($r) => is_string($r) ? $r : get_class($r), $relations);

        expect($relationClasses)->toContain(\App\Filament\Resources\LicenseResource\RelationManagers\UserLicensesRelationManager::class);
    });

    it('has organization licenses relation manager', function () {
        $relations = LicenseResource::getRelations();
        $relationClasses = array_map(fn ($r) => is_string($r) ? $r : get_class($r), $relations);

        expect($relationClasses)->toContain(\App\Filament\Resources\LicenseResource\RelationManagers\OrganizationLicensesRelationManager::class);
    });
});

describe('LicenseResource::JsonRestrictions', function () {
    it('can save license with valid JSON restrictions', function () {
        $restrictions = [
            'max_files' => 10,
            'max_total_size' => 10485760,
            'workflow_builder' => true,
        ];

        $license = License::factory()->create([
            'json_restrictions' => json_encode($restrictions),
        ]);

        $license->refresh();

        expect($license->json_restrictions)->toBeArray();
        expect($license->json_restrictions['max_files'])->toBe(10);
        expect($license->json_restrictions['workflow_builder'])->toBeTrue();
    });

    it('can save license with null json_restrictions', function () {
        $license = License::factory()->create([
            'json_restrictions' => null,
        ]);

        expect($license->json_restrictions)->toBeNull();
    });

    it('can save license with empty string json_restrictions', function () {
        $license = License::factory()->create([
            'json_restrictions' => '',
        ]);

        expect($license->json_restrictions)->toBeNull();
    });

    it('stores upload limits in json_restrictions', function () {
        $license = License::factory()->create([
            'json_restrictions' => json_encode([
                'max_files' => 5,
                'max_total_size' => 5242880,
                'max_pages' => 100,
                'max_file_size' => 2097152,
            ]),
        ]);

        $restrictions = $license->json_restrictions;
        expect($restrictions)->toHaveKey('max_files');
        expect($restrictions['max_files'])->toBe(5);
        expect($restrictions['max_total_size'])->toBe(5242880);
    });

    it('stores feature flags in json_restrictions', function () {
        $license = License::factory()->create([
            'json_restrictions' => json_encode([
                'workflow_builder' => true,
                'email_support' => false,
                'api_access' => true,
                'custom_branding' => false,
                'priority_support' => true,
                'advanced_analytics' => false,
            ]),
        ]);

        $restrictions = $license->json_restrictions;
        expect($restrictions['workflow_builder'])->toBeTrue();
        expect($restrictions['email_support'])->toBeFalse();
        expect($restrictions['api_access'])->toBeTrue();
    });

    it('stores content restrictions in json_restrictions', function () {
        $license = License::factory()->create([
            'json_restrictions' => json_encode([
                'allowed_file_types' => ['pdf', 'docx', 'xlsx'],
                'watermark_required' => true,
            ]),
        ]);

        $restrictions = $license->json_restrictions;
        expect($restrictions['allowed_file_types'])->toBeArray();
        expect($restrictions['allowed_file_types'])->toContain('pdf');
        expect($restrictions['watermark_required'])->toBeTrue();
    });

    it('can store custom restrictions beyond standard fields', function () {
        $license = License::factory()->create([
            'json_restrictions' => json_encode([
                'custom_field' => 'custom_value',
                'another_custom' => 123,
            ]),
        ]);

        $restrictions = $license->json_restrictions;
        expect($restrictions)->toHaveKey('custom_field');
        expect($restrictions['custom_field'])->toBe('custom_value');
        expect($restrictions['another_custom'])->toBe(123);
    });
});
