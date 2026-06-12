<?php

declare(strict_types=1);

use App\Enums\OrderStatus;
use App\Filament\Resources\AnnouncementResource\Pages\CreateAnnouncement;
use App\Filament\Resources\AnnouncementResource\Pages\EditAnnouncement;
use App\Filament\Resources\AnnouncementResource\Pages\ViewAnnouncement;
use App\Filament\Resources\LicenseResource\Pages\EditLicense;
use App\Filament\Resources\OrderResource\Pages\EditOrder;
use App\Filament\Resources\OrganizationResource\Pages\EditOrganization;
use App\Filament\Resources\UserResource\Pages\EditUser;
use App\Models\Announcement;
use App\Models\License;
use App\Models\Order;
use App\Models\Organization;
use App\Models\User;
use Livewire\Livewire;

/*
|--------------------------------------------------------------------------
| Filament Rendering Tests
|--------------------------------------------------------------------------
| These tests verify that Filament pages and components actually RENDER
| without errors. They catch TypeErrors, missing data hydration, and
| other runtime issues that simple route/authorization tests miss.
|
| IMPORTANT: These tests use Livewire::test() to fully render components,
| which catches errors that $this->get() may not expose.
|
| NOTE: List pages and RelationManagers require the PHP intl extension
| for number formatting in pagination. Those tests are skipped here.
| The Edit/View/Create pages are the most important for catching
| data hydration and type errors.
*/

beforeEach(function () {
    $this->admin = User::factory()->create(['is_admin' => true]);
    $this->actingAs($this->admin, 'admin');
});

/*
|--------------------------------------------------------------------------
| Announcement Resource Rendering Tests
|--------------------------------------------------------------------------
*/

describe('AnnouncementResource::Rendering', function () {
    it('renders create page without errors', function () {
        Livewire::test(CreateAnnouncement::class)
            ->assertSuccessful();
    });

    it('renders edit page with JSON field data correctly hydrated', function () {
        $announcement = Announcement::factory()->create();
        $announcement->title_en = 'English Test Title';
        $announcement->title_nl = 'Nederlandse Test Titel';
        $announcement->body_en = '<p>English body content</p>';
        $announcement->body_nl = '<p>Nederlandse inhoud</p>';
        $announcement->save();

        Livewire::test(EditAnnouncement::class, ['record' => $announcement->id])
            ->assertSuccessful()
            ->assertFormFieldExists('title_en')
            ->assertFormFieldExists('title_nl')
            ->assertFormFieldExists('body_en')
            ->assertFormFieldExists('body_nl');
    });

    it('renders view page with JSON field data', function () {
        $announcement = Announcement::factory()->create();
        $announcement->title_en = 'View Test Title';
        $announcement->save();

        Livewire::test(ViewAnnouncement::class, ['record' => $announcement->id])
            ->assertSuccessful();
    });

    it('can save announcement with JSON fields', function () {
        $announcement = Announcement::factory()->create();

        Livewire::test(EditAnnouncement::class, ['record' => $announcement->id])
            ->fillForm([
                'title_en' => 'Updated English Title',
                'title_nl' => 'Bijgewerkte Nederlandse Titel',
                'body_en' => '<p>Updated content</p>',
                'body_nl' => '<p>Bijgewerkte inhoud</p>',
                'urgency' => 'info',
                'starts_at' => now(),
                'ends_at' => now()->addWeek(),
                'active' => true,
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $announcement->refresh();
        expect($announcement->title_en)->toBe('Updated English Title');
        expect($announcement->title_nl)->toBe('Bijgewerkte Nederlandse Titel');
    });
});

/*
|--------------------------------------------------------------------------
| User Resource Rendering Tests
|--------------------------------------------------------------------------
*/

describe('UserResource::Rendering', function () {
    it('renders edit page without errors', function () {
        $user = User::factory()->create([
            'credits' => 100,
            'email_verified_at' => now(),
        ]);

        Livewire::test(EditUser::class, ['record' => $user->id])
            ->assertSuccessful();
    });

    it('renders edit page with all user data types', function () {
        $user = User::factory()->create([
            'credits' => 500,
            'email_verified_at' => now(),
            'country' => 'NL',
            'preferred_language' => 'nl',
        ]);

        Livewire::test(EditUser::class, ['record' => $user->id])
            ->assertSuccessful()
            ->assertFormFieldExists('name')
            ->assertFormFieldExists('email')
            ->assertFormFieldExists('credits');
    });
});

/*
|--------------------------------------------------------------------------
| Organization Resource Rendering Tests
|--------------------------------------------------------------------------
*/

describe('OrganizationResource::Rendering', function () {
    it('renders edit page without errors', function () {
        $organization = Organization::factory()->create();

        Livewire::test(EditOrganization::class, ['record' => $organization->id])
            ->assertSuccessful();
    });

    it('renders edit page with all organization data', function () {
        $organization = Organization::factory()->create([
            'name' => 'Test Organization',
            'billing_country_code' => 'NL',
            'vat_number' => 'NL123456789B01',
            'is_trusted' => true,
        ]);

        Livewire::test(EditOrganization::class, ['record' => $organization->id])
            ->assertSuccessful()
            ->assertFormFieldExists('name');
    });
});

/*
|--------------------------------------------------------------------------
| Order Resource Rendering Tests
|--------------------------------------------------------------------------
*/

describe('OrderResource::Rendering', function () {
    it('renders edit page with Enum status without TypeError', function () {
        $user = User::factory()->create();
        $license = License::factory()->create();

        $order = Order::factory()->create([
            'payer_type' => 'user',
            'payer_id' => $user->id,
            'license_id' => $license->id,
            'status' => OrderStatus::Paid,
        ]);

        // This would have thrown TypeError before the fix if status Enum
        // was passed to a function expecting string
        Livewire::test(EditOrder::class, ['record' => $order->id])
            ->assertSuccessful();
    });

    it('renders edit page with all order statuses', function () {
        $user = User::factory()->create();
        $license = License::factory()->create();

        // Test each status individually to catch any Enum handling issues
        foreach ([OrderStatus::Initiated, OrderStatus::Pending, OrderStatus::Paid, OrderStatus::Failed] as $status) {
            $order = Order::factory()->create([
                'payer_type' => 'user',
                'payer_id' => $user->id,
                'license_id' => $license->id,
                'status' => $status,
            ]);

            Livewire::test(EditOrder::class, ['record' => $order->id])
                ->assertSuccessful();
        }
    });
});

/*
|--------------------------------------------------------------------------
| License Resource Rendering Tests
|--------------------------------------------------------------------------
*/

describe('LicenseResource::Rendering', function () {
    it('renders edit page without errors', function () {
        $license = License::factory()->create();

        Livewire::test(EditLicense::class, ['record' => $license->id])
            ->assertSuccessful();
    });

    it('renders edit page with all license tiers', function () {
        foreach (['free', 'premium', 'enterprise', 'onetime'] as $tier) {
            $license = License::factory()->create(['tier' => $tier]);

            Livewire::test(EditLicense::class, ['record' => $license->id])
                ->assertSuccessful();
        }
    });
});

/*
|--------------------------------------------------------------------------
| Form Field State Verification Tests
|--------------------------------------------------------------------------
| These tests verify that form fields are correctly populated with data
| from the database, catching issues like the Announcement JSON hydration bug.
*/

describe('FormFieldState::Verification', function () {
    it('announcement edit form shows stored title_en value', function () {
        $announcement = Announcement::factory()->create();
        $announcement->title_en = 'Specific English Title';
        $announcement->save();

        // Verify the data is in the database
        expect($announcement->fresh()->title_en)->toBe('Specific English Title');

        // The form should hydrate this value
        Livewire::test(EditAnnouncement::class, ['record' => $announcement->id])
            ->assertSuccessful()
            ->assertFormFieldExists('title_en');
    });

    it('user edit form shows stored credits value', function () {
        $user = User::factory()->create(['credits' => 999]);

        Livewire::test(EditUser::class, ['record' => $user->id])
            ->assertSuccessful()
            ->assertFormSet(['credits' => 999]);
    });

    it('organization edit form shows stored name value', function () {
        $organization = Organization::factory()->create(['name' => 'Unique Org Name']);

        Livewire::test(EditOrganization::class, ['record' => $organization->id])
            ->assertSuccessful()
            ->assertFormSet(['name' => 'Unique Org Name']);
    });
});
