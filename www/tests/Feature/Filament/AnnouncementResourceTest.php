<?php

declare(strict_types=1);

use App\Filament\Resources\AnnouncementResource;
use App\Models\Announcement;
use App\Models\User;

beforeEach(function () {
    $this->admin = User::factory()->create(['is_admin' => true]);
    $this->actingAs($this->admin, 'admin');
});

describe('AnnouncementResource::Authorization', function () {
    it('denies non-admin access to list page', function () {
        $regularUser = User::factory()->create(['is_admin' => false]);
        $this->actingAs($regularUser, 'admin');

        $this->get(AnnouncementResource::getUrl('index'))
            ->assertForbidden();
    });

    it('denies non-admin access to create page', function () {
        $regularUser = User::factory()->create(['is_admin' => false]);
        $this->actingAs($regularUser, 'admin');

        $this->get(AnnouncementResource::getUrl('create'))
            ->assertForbidden();
    });

    it('denies non-admin access to edit page', function () {
        $regularUser = User::factory()->create(['is_admin' => false]);
        $announcement = Announcement::factory()->create();
        $this->actingAs($regularUser, 'admin');

        $this->get(AnnouncementResource::getUrl('edit', ['record' => $announcement]))
            ->assertForbidden();
    });

    it('denies non-admin access to view page', function () {
        $regularUser = User::factory()->create(['is_admin' => false]);
        $announcement = Announcement::factory()->create();
        $this->actingAs($regularUser, 'admin');

        $this->get(AnnouncementResource::getUrl('view', ['record' => $announcement]))
            ->assertForbidden();
    });

    it('allows admin access to create page', function () {
        $response = $this->get(AnnouncementResource::getUrl('create'));

        expect($response->status())->not->toBe(403);
        expect($response->status())->not->toBe(302);
    });

    it('allows admin access to edit page', function () {
        $announcement = Announcement::factory()->create();

        $response = $this->get(AnnouncementResource::getUrl('edit', ['record' => $announcement]));

        expect($response->status())->not->toBe(403);
        expect($response->status())->not->toBe(302);
    });

    it('allows admin access to view page', function () {
        $announcement = Announcement::factory()->create();

        $response = $this->get(AnnouncementResource::getUrl('view', ['record' => $announcement]));

        expect($response->status())->not->toBe(403);
        expect($response->status())->not->toBe(302);
    });
});

describe('AnnouncementResource::Routes', function () {
    it('has correct index route', function () {
        expect(AnnouncementResource::getUrl('index'))->toContain('/beheer/announcements');
    });

    it('has correct create route', function () {
        expect(AnnouncementResource::getUrl('create'))->toContain('/beheer/announcements/create');
    });

    it('has correct edit route', function () {
        $announcement = Announcement::factory()->create();
        expect(AnnouncementResource::getUrl('edit', ['record' => $announcement]))->toContain("/beheer/announcements/{$announcement->id}/edit");
    });

    it('has correct view route', function () {
        $announcement = Announcement::factory()->create();
        expect(AnnouncementResource::getUrl('view', ['record' => $announcement]))->toContain("/beheer/announcements/{$announcement->id}");
    });
});

describe('AnnouncementResource::Model', function () {
    it('uses Announcement model', function () {
        expect(AnnouncementResource::getModel())->toBe(Announcement::class);
    });

    it('has navigation icon', function () {
        expect(AnnouncementResource::getNavigationIcon())->toBe('heroicon-o-megaphone');
    });

    it('belongs to Content navigation group', function () {
        expect(AnnouncementResource::getNavigationGroup())->toBe('Content');
    });
});

describe('AnnouncementResource::Pages', function () {
    it('has list page', function () {
        $pages = AnnouncementResource::getPages();
        expect($pages)->toHaveKey('index');
    });

    it('has create page', function () {
        $pages = AnnouncementResource::getPages();
        expect($pages)->toHaveKey('create');
    });

    it('has edit page', function () {
        $pages = AnnouncementResource::getPages();
        expect($pages)->toHaveKey('edit');
    });

    it('has view page', function () {
        $pages = AnnouncementResource::getPages();
        expect($pages)->toHaveKey('view');
    });
});

describe('AnnouncementResource::UrgencyLevels', function () {
    it('can have info urgency', function () {
        $announcement = Announcement::factory()->create(['urgency' => 'info']);
        expect($announcement->urgency)->toBe('info');
    });

    it('can have warning urgency', function () {
        $announcement = Announcement::factory()->create(['urgency' => 'warning']);
        expect($announcement->urgency)->toBe('warning');
    });

    it('can have update urgency', function () {
        $announcement = Announcement::factory()->create(['urgency' => 'update']);
        expect($announcement->urgency)->toBe('update');
    });
});

describe('AnnouncementResource::Content', function () {
    it('can have English title', function () {
        $announcement = Announcement::factory()->create();
        $announcement->title_en = 'English Title';
        $announcement->save();

        expect($announcement->fresh()->title_en)->toBe('English Title');
    });

    it('can have Dutch title', function () {
        $announcement = Announcement::factory()->create();
        $announcement->title_nl = 'Nederlandse Titel';
        $announcement->save();

        expect($announcement->fresh()->title_nl)->toBe('Nederlandse Titel');
    });

    it('can have English body', function () {
        $announcement = Announcement::factory()->create();
        $announcement->body_en = '<p>English body content</p>';
        $announcement->save();

        expect($announcement->fresh()->body_en)->toBe('<p>English body content</p>');
    });

    it('can have Dutch body', function () {
        $announcement = Announcement::factory()->create();
        $announcement->body_nl = '<p>Nederlandse inhoud</p>';
        $announcement->save();

        expect($announcement->fresh()->body_nl)->toBe('<p>Nederlandse inhoud</p>');
    });
});

describe('AnnouncementResource::DateRange', function () {
    it('can have starts_at date', function () {
        $date = now()->startOfDay();
        $announcement = Announcement::factory()->create(['starts_at' => $date]);
        expect($announcement->starts_at->format('Y-m-d'))->toBe($date->format('Y-m-d'));
    });

    it('can have ends_at date', function () {
        $date = now()->addWeek()->startOfDay();
        $announcement = Announcement::factory()->create(['ends_at' => $date]);
        expect($announcement->ends_at->format('Y-m-d'))->toBe($date->format('Y-m-d'));
    });
});

describe('AnnouncementResource::Status', function () {
    it('can be active', function () {
        $announcement = Announcement::factory()->create(['active' => true]);
        expect($announcement->active)->toBeTrue();
    });

    it('can be inactive', function () {
        $announcement = Announcement::factory()->create(['active' => false]);
        expect($announcement->active)->toBeFalse();
    });

    it('is active when within date range and active flag set', function () {
        $announcement = Announcement::factory()->create([
            'active' => true,
            'starts_at' => now()->subDay(),
            'ends_at' => now()->addDay(),
        ]);

        $now = now();
        $isCurrentlyActive = $announcement->active &&
            $announcement->starts_at <= $now &&
            $announcement->ends_at >= $now;

        expect($isCurrentlyActive)->toBeTrue();
    });

    it('is not active when before start date', function () {
        $announcement = Announcement::factory()->create([
            'active' => true,
            'starts_at' => now()->addDay(),
            'ends_at' => now()->addWeek(),
        ]);

        $now = now();
        $isUpcoming = $announcement->starts_at > $now;

        expect($isUpcoming)->toBeTrue();
    });

    it('is expired when after end date', function () {
        $announcement = Announcement::factory()->create([
            'active' => true,
            'starts_at' => now()->subWeek(),
            'ends_at' => now()->subDay(),
        ]);

        $now = now();
        $isExpired = $announcement->ends_at < $now;

        expect($isExpired)->toBeTrue();
    });
});

describe('AnnouncementResource::CallToAction', function () {
    it('can have CTA label', function () {
        $announcement = Announcement::factory()->create(['cta_label_en' => 'Learn More']);
        expect($announcement->cta_label_en)->toBe('Learn More');
    });

    it('can have CTA URL', function () {
        $announcement = Announcement::factory()->create(['cta_url' => 'https://example.com/learn']);
        expect($announcement->cta_url)->toBe('https://example.com/learn');
    });

    it('has CTA when both label and URL are set', function () {
        $announcement = Announcement::factory()->create([
            'cta_label_en' => 'Learn More',
            'cta_url' => 'https://example.com/learn',
        ]);

        $hasCta = $announcement->hasCta();
        expect($hasCta)->toBeTrue();
    });

    it('does not have CTA when label is missing', function () {
        $announcement = Announcement::factory()->create([
            'cta_label_en' => null,
            'cta_url' => 'https://example.com/learn',
        ]);

        $hasCta = $announcement->hasCta();
        expect($hasCta)->toBeFalse();
    });
});

describe('AnnouncementResource::Views', function () {
    it('tracks total views', function () {
        $announcement = Announcement::factory()->create(['total_views' => 100]);
        expect($announcement->total_views)->toBe(100);
    });

    it('starts with zero views', function () {
        $announcement = Announcement::factory()->create(['total_views' => 0]);
        expect($announcement->total_views)->toBe(0);
    });
});
