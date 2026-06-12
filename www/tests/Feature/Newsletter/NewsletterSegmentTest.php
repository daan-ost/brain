<?php

namespace Tests\Feature\Newsletter;

use App\Models\Newsletter;
use App\Models\User;
use App\Models\UserLicense;
use App\Services\NewsletterSegmentService;
use App\Services\NewsletterService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NewsletterSegmentTest extends TestCase
{
    use RefreshDatabase;

    private NewsletterSegmentService $segments;

    protected function setUp(): void
    {
        parent::setUp();
        $this->segments = app(NewsletterSegmentService::class);
    }

    public function test_available_segments_lists_seven_keys(): void
    {
        $keys = array_keys($this->segments->availableSegments());

        $this->assertContains('all', $keys);
        $this->assertContains('paying', $keys);
        $this->assertContains('free', $keys);
        $this->assertContains('nl', $keys);
        $this->assertContains('en', $keys);
        $this->assertContains('recent_signup_90', $keys);
        $this->assertContains('recent_login_180', $keys);
        $this->assertCount(7, $keys);
    }

    public function test_segment_all_returns_all_newsletter_subscribed_users(): void
    {
        User::factory()->count(3)->create();
        User::factory()->create(['newsletter_subscribed' => false]);
        User::factory()->unverified()->create();

        $this->assertSame(3, $this->segments->count('all'));
    }

    public function test_segment_nl_filters_by_dutch_language(): void
    {
        User::factory()->count(2)->create(['preferred_language' => 'nl']);
        User::factory()->create(['preferred_language' => 'en']);

        $this->assertSame(2, $this->segments->count('nl'));
    }

    public function test_segment_en_filters_by_english_language(): void
    {
        User::factory()->create(['preferred_language' => 'nl']);
        User::factory()->count(2)->create(['preferred_language' => 'en']);

        $this->assertSame(2, $this->segments->count('en'));
    }

    public function test_segment_paying_filters_users_with_active_user_license(): void
    {
        $payer = User::factory()->create();
        UserLicense::factory()->active()->create(['user_id' => $payer->id]);

        User::factory()->create(); // no license

        $this->assertSame(1, $this->segments->count('paying'));
    }

    public function test_segment_free_excludes_users_with_active_license(): void
    {
        $payer = User::factory()->create();
        UserLicense::factory()->active()->create(['user_id' => $payer->id]);

        User::factory()->count(2)->create();

        $this->assertSame(2, $this->segments->count('free'));
    }

    public function test_segment_recent_signup_90(): void
    {
        $recent = User::factory()->create();
        $recent->forceFill(['created_at' => now()->subDays(30)])->saveQuietly();

        $old = User::factory()->create();
        $old->forceFill(['created_at' => now()->subDays(120)])->saveQuietly();

        $this->assertSame(1, $this->segments->count('recent_signup_90'));
    }

    public function test_segment_recent_login_180_excludes_never_logged_in(): void
    {
        User::factory()->create(['last_login_at' => now()->subDays(30)]);
        User::factory()->create(['last_login_at' => now()->subDays(200)]);
        User::factory()->create(['last_login_at' => null]);

        $this->assertSame(1, $this->segments->count('recent_login_180'));
    }

    public function test_invalid_segment_returns_zero_count(): void
    {
        User::factory()->count(5)->create();

        $this->assertSame(0, $this->segments->count('bogus'));
        $this->assertFalse($this->segments->isValid('bogus'));
    }

    // ========================================
    // Integration: startSending with segment
    // ========================================

    private function newsletter(): Newsletter
    {
        // Creator is unsubscribed so it doesn't pollute segment counts.
        $creator = User::factory()->create(['newsletter_subscribed' => false]);

        return Newsletter::factory()->create(['created_by' => $creator->id]);
    }

    public function test_start_sending_with_segment_records_segment_key(): void
    {
        User::factory()->count(2)->create(['preferred_language' => 'nl']);
        User::factory()->create(['preferred_language' => 'en']);

        $newsletter = $this->newsletter();

        app(NewsletterService::class)->startSending($newsletter, null, 'nl');

        $newsletter->refresh();
        $this->assertSame('nl', $newsletter->segment_key);
        $this->assertSame(2, $newsletter->total_recipients);
    }

    public function test_start_sending_with_empty_segment_throws_and_keeps_draft(): void
    {
        $newsletter = $this->newsletter();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Geen ontvangers in dit segment.');

        try {
            app(NewsletterService::class)->startSending($newsletter, null, 'all');
        } finally {
            $this->assertSame(Newsletter::STATUS_DRAFT, $newsletter->refresh()->status);
        }
    }

    public function test_start_sending_with_invalid_segment_throws(): void
    {
        User::factory()->create();
        $newsletter = $this->newsletter();

        $this->expectException(\InvalidArgumentException::class);

        app(NewsletterService::class)->startSending($newsletter, null, 'bogus');
    }

    public function test_start_sending_defaults_to_all_when_not_specified(): void
    {
        User::factory()->count(2)->create();
        $newsletter = $this->newsletter();

        app(NewsletterService::class)->startSending($newsletter, null);

        $this->assertSame('all', $newsletter->refresh()->segment_key);
    }
}
