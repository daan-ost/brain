<?php

declare(strict_types=1);

use App\Filament\Resources\LicenseResource\Pages\CreateLicense;
use App\Filament\Resources\LicenseResource\Pages\ViewLicense;
use App\Filament\Resources\OrderResource\Pages\CreateOrder;
use App\Filament\Resources\OrganizationResource\Pages\CreateOrganization;
use App\Filament\Resources\PostmarkTemplateResource\Pages\CreatePostmarkTemplate;
use App\Filament\Resources\PostmarkTemplateResource\Pages\EditPostmarkTemplate;
use App\Filament\Resources\PostmarkTemplateResource\Pages\ViewPostmarkTemplate;
use App\Filament\Resources\UserResource\Pages\CreateUser;
use App\Filament\Resources\UserResource\Pages\ViewUser;
use App\Models\AnalyticsEvent;
use App\Models\CreditLedger;
use App\Models\License;
use App\Models\MessageThread;
use App\Models\Order;
use App\Models\Organization;
use App\Models\PostmarkTemplate;
use App\Models\User;
use Livewire\Livewire;

/*
|--------------------------------------------------------------------------
| All Resources Rendering Tests
|--------------------------------------------------------------------------
| Comprehensive rendering tests for ALL Filament resources.
| Tests View, Create, and Edit pages to catch runtime errors.
|
| NOTE: List pages require PHP intl extension and are tested via
| HTTP requests for authorization only (see individual resource tests).
*/

beforeEach(function () {
    $this->admin = User::factory()->create(['is_admin' => true]);
    $this->actingAs($this->admin, 'admin');
});

/*
|--------------------------------------------------------------------------
| User Resource - Additional Pages
|--------------------------------------------------------------------------
*/

describe('UserResource::AdditionalPages', function () {
    it('renders create page without errors', function () {
        Livewire::test(CreateUser::class)
            ->assertSuccessful();
    });

    it('renders view page without errors', function () {
        $user = User::factory()->create();

        Livewire::test(ViewUser::class, ['record' => $user->id])
            ->assertSuccessful();
    });
});

/*
|--------------------------------------------------------------------------
| Organization Resource - Additional Pages
|--------------------------------------------------------------------------
*/

describe('OrganizationResource::AdditionalPages', function () {
    it('renders create page without errors', function () {
        Livewire::test(CreateOrganization::class)
            ->assertSuccessful();
    });

    // Note: View page requires intl for number formatting, tested via HTTP
    it('view page is accessible (authorization check)', function () {
        $organization = Organization::factory()->create();

        $response = $this->get("/beheer/organizations/{$organization->id}");

        expect($response->status())->not->toBe(403);
        expect($response->status())->not->toBe(302);
    });
});

/*
|--------------------------------------------------------------------------
| Order Resource - Additional Pages
|--------------------------------------------------------------------------
*/

describe('OrderResource::AdditionalPages', function () {
    it('renders create page without errors', function () {
        Livewire::test(CreateOrder::class)
            ->assertSuccessful();
    });

    // Note: View page requires intl for currency formatting, tested via HTTP
    it('view page is accessible (authorization check)', function () {
        $user = User::factory()->create();
        $license = License::factory()->create();

        $order = Order::factory()->create([
            'payer_type' => 'user',
            'payer_id' => $user->id,
            'license_id' => $license->id,
        ]);

        $response = $this->get("/beheer/orders/{$order->id}");

        expect($response->status())->not->toBe(403);
        expect($response->status())->not->toBe(302);
    });
});

/*
|--------------------------------------------------------------------------
| License Resource - Additional Pages
|--------------------------------------------------------------------------
*/

describe('LicenseResource::AdditionalPages', function () {
    it('renders create page without errors', function () {
        Livewire::test(CreateLicense::class)
            ->assertSuccessful();
    });

    it('renders view page without errors', function () {
        $license = License::factory()->create();

        Livewire::test(ViewLicense::class, ['record' => $license->id])
            ->assertSuccessful();
    });
});

/*
|--------------------------------------------------------------------------
| MessageThread Resource
|--------------------------------------------------------------------------
*/

describe('MessageThreadResource::Rendering', function () {
    it('view page is accessible (authorization check)', function () {
        $user = User::factory()->create();

        $thread = MessageThread::create([
            'user_id' => $user->id,
            'subject' => 'Test Subject',
            'category' => 'general',
            'status' => 'open',
        ]);

        $response = $this->get("/beheer/message-threads/{$thread->id}");

        expect($response->status())->not->toBe(403);
        expect($response->status())->not->toBe(302);
    });
});

/*
|--------------------------------------------------------------------------
| PostmarkTemplate Resource
|--------------------------------------------------------------------------
*/

describe('PostmarkTemplateResource::Rendering', function () {
    it('renders create page without errors', function () {
        Livewire::test(CreatePostmarkTemplate::class)
            ->assertSuccessful();
    });

    it('renders edit page without errors', function () {
        $template = PostmarkTemplate::create([
            'name' => 'Test Template',
            'alias' => 'test-template',
            'subject' => 'Test Subject',
            'html_body' => '<p>Test</p>',
            'text_body' => 'Test',
            'active' => true,
        ]);

        Livewire::test(EditPostmarkTemplate::class, ['record' => $template->id])
            ->assertSuccessful();
    });

    it('renders view page without errors', function () {
        $template = PostmarkTemplate::create([
            'name' => 'View Template',
            'alias' => 'view-template',
            'subject' => 'View Subject',
            'html_body' => '<p>View</p>',
            'text_body' => 'View',
            'active' => true,
        ]);

        Livewire::test(ViewPostmarkTemplate::class, ['record' => $template->id])
            ->assertSuccessful();
    });
});

/*
|--------------------------------------------------------------------------
| CreditLedger Resource
|--------------------------------------------------------------------------
*/

describe('CreditLedgerResource::Rendering', function () {
    it('view page is accessible (authorization check)', function () {
        $user = User::factory()->create(['credits' => 100]);

        $ledger = CreditLedger::create([
            'user_id' => $user->id,
            'delta' => 50,
            'reason' => 'bonus',
            'balance_after' => 150,
        ]);

        $response = $this->get("/beheer/credit-ledgers/{$ledger->id}");

        expect($response->status())->not->toBe(403);
        expect($response->status())->not->toBe(302);
    });
});

/*
|--------------------------------------------------------------------------
| AnalyticsEvent Resource
|--------------------------------------------------------------------------
*/

describe('AnalyticsEventResource::Rendering', function () {
    it('view page is accessible (authorization check)', function () {
        $event = AnalyticsEvent::create([
            'event' => 'page_view',
            'meta' => ['page' => '/home'],
        ]);

        $response = $this->get("/beheer/analytics-events/{$event->id}");

        expect($response->status())->not->toBe(403);
        expect($response->status())->not->toBe(302);
    });
});
