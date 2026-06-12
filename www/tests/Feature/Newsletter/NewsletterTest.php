<?php

namespace Tests\Feature\Newsletter;

use App\Models\Newsletter;
use App\Models\NewsletterRecipient;
use App\Models\User;
use App\Services\NewsletterService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NewsletterTest extends TestCase
{
    use RefreshDatabase;

    // ========================================
    // User Model Newsletter Tests
    // ========================================

    public function test_new_user_has_newsletter_subscribed_by_default(): void
    {
        $user = User::factory()->create();

        $this->assertTrue($user->newsletter_subscribed);
    }

    public function test_new_user_gets_unique_unsubscribe_token(): void
    {
        $user = User::factory()->create();

        $this->assertNotNull($user->newsletter_unsubscribe_token);
        $this->assertEquals(64, strlen($user->newsletter_unsubscribe_token));
    }

    public function test_two_users_get_different_unsubscribe_tokens(): void
    {
        $user1 = User::factory()->create();
        $user2 = User::factory()->create();

        $this->assertNotEquals($user1->newsletter_unsubscribe_token, $user2->newsletter_unsubscribe_token);
    }

    public function test_user_can_receive_newsletter_when_subscribed_and_verified(): void
    {
        $user = User::factory()->create([
            'newsletter_subscribed' => true,
            'email_verified_at' => now(),
            'email_bounced_at' => null,
        ]);

        $this->assertTrue($user->canReceiveNewsletter());
    }

    public function test_user_cannot_receive_newsletter_when_unsubscribed(): void
    {
        $user = User::factory()->create([
            'newsletter_subscribed' => false,
            'email_verified_at' => now(),
        ]);

        $this->assertFalse($user->canReceiveNewsletter());
    }

    public function test_user_cannot_receive_newsletter_when_email_not_verified(): void
    {
        $user = User::factory()->create([
            'newsletter_subscribed' => true,
            'email_verified_at' => null,
        ]);

        $this->assertFalse($user->canReceiveNewsletter());
    }

    public function test_user_cannot_receive_newsletter_when_email_bounced(): void
    {
        $user = User::factory()->create([
            'newsletter_subscribed' => true,
            'email_verified_at' => now(),
            'email_bounced_at' => now(),
        ]);

        $this->assertFalse($user->canReceiveNewsletter());
    }

    public function test_newsletter_subscribed_scope_filters_correctly(): void
    {
        // Eligible users
        $eligible1 = User::factory()->create([
            'newsletter_subscribed' => true,
            'email_verified_at' => now(),
            'email_bounced_at' => null,
        ]);

        // Unsubscribed
        User::factory()->create([
            'newsletter_subscribed' => false,
            'email_verified_at' => now(),
        ]);

        // Not verified
        User::factory()->create([
            'newsletter_subscribed' => true,
            'email_verified_at' => null,
        ]);

        // Bounced
        User::factory()->create([
            'newsletter_subscribed' => true,
            'email_verified_at' => now(),
            'email_bounced_at' => now(),
        ]);

        $eligibleUsers = User::newsletterSubscribed()->get();

        $this->assertCount(1, $eligibleUsers);
        $this->assertEquals($eligible1->id, $eligibleUsers->first()->id);
    }

    // ========================================
    // Newsletter Model Tests
    // ========================================

    public function test_newsletter_is_draft_by_default(): void
    {
        $newsletter = Newsletter::factory()->create();

        $this->assertTrue($newsletter->isDraft());
        $this->assertEquals(Newsletter::STATUS_DRAFT, $newsletter->status);
    }

    public function test_newsletter_get_title_returns_correct_locale(): void
    {
        $newsletter = Newsletter::factory()->create([
            'title_json' => ['en' => 'English Title', 'nl' => 'Nederlandse Titel'],
        ]);

        $this->assertEquals('English Title', $newsletter->getTitle('en'));
        $this->assertEquals('Nederlandse Titel', $newsletter->getTitle('nl'));
    }

    public function test_newsletter_get_title_falls_back_to_english(): void
    {
        $newsletter = Newsletter::factory()->create([
            'title_json' => ['en' => 'English Title'],
        ]);

        $this->assertEquals('English Title', $newsletter->getTitle('nl'));
        $this->assertEquals('English Title', $newsletter->getTitle('de'));
    }

    public function test_newsletter_can_be_sent_only_when_draft(): void
    {
        $draft = Newsletter::factory()->create();
        $sending = Newsletter::factory()->sending()->create();
        $paused = Newsletter::factory()->paused()->create();
        $sent = Newsletter::factory()->sent()->create();

        $this->assertTrue($draft->canBeSent());
        $this->assertFalse($sending->canBeSent());
        $this->assertFalse($paused->canBeSent());
        $this->assertFalse($sent->canBeSent());
    }

    public function test_newsletter_can_be_paused_only_when_sending(): void
    {
        $draft = Newsletter::factory()->create();
        $sending = Newsletter::factory()->sending()->create();
        $paused = Newsletter::factory()->paused()->create();
        $sent = Newsletter::factory()->sent()->create();

        $this->assertFalse($draft->canBePaused());
        $this->assertTrue($sending->canBePaused());
        $this->assertFalse($paused->canBePaused());
        $this->assertFalse($sent->canBePaused());
    }

    public function test_newsletter_can_be_resumed_only_when_paused(): void
    {
        $draft = Newsletter::factory()->create();
        $sending = Newsletter::factory()->sending()->create();
        $paused = Newsletter::factory()->paused()->create();
        $sent = Newsletter::factory()->sent()->create();

        $this->assertFalse($draft->canBeResumed());
        $this->assertFalse($sending->canBeResumed());
        $this->assertTrue($paused->canBeResumed());
        $this->assertFalse($sent->canBeResumed());
    }

    public function test_newsletter_statistics_rates_calculation(): void
    {
        $newsletter = Newsletter::factory()->create([
            'total_sent' => 100,
            'total_opened' => 50,
            'total_clicked' => 25,
            'total_bounced' => 5,
        ]);

        $this->assertEquals(50.0, $newsletter->getOpenRate());
        $this->assertEquals(25.0, $newsletter->getClickRate());
        $this->assertEquals(5.0, $newsletter->getBounceRate());
    }

    public function test_newsletter_statistics_rates_handle_zero_sent(): void
    {
        $newsletter = Newsletter::factory()->create([
            'total_sent' => 0,
            'total_opened' => 0,
            'total_clicked' => 0,
            'total_bounced' => 0,
        ]);

        $this->assertEquals(0, $newsletter->getOpenRate());
        $this->assertEquals(0, $newsletter->getClickRate());
        $this->assertEquals(0, $newsletter->getBounceRate());
    }

    public function test_newsletter_progress_calculation(): void
    {
        $newsletter = Newsletter::factory()->create([
            'total_recipients' => 100,
            'total_sent' => 60,
            'total_failed' => 10,
        ]);

        $this->assertEquals(70.0, $newsletter->getProgress());
    }

    // ========================================
    // Unsubscribe Tests
    // ========================================

    public function test_user_can_unsubscribe_via_token(): void
    {
        $user = User::factory()->create([
            'newsletter_subscribed' => true,
        ]);

        $response = $this->get(route('newsletter.unsubscribe', ['token' => $user->newsletter_unsubscribe_token]));

        $response->assertOk();
        $response->assertViewIs('newsletter.unsubscribe-success');

        $user->refresh();
        $this->assertFalse($user->newsletter_subscribed);
        $this->assertNotNull($user->newsletter_unsubscribed_at);
    }

    public function test_invalid_token_shows_error_page(): void
    {
        $response = $this->get(route('newsletter.unsubscribe', ['token' => 'invalid-token-12345']));

        $response->assertOk();
        $response->assertViewIs('newsletter.unsubscribe-failed');
    }

    // ========================================
    // NewsletterService Tests
    // ========================================

    public function test_service_can_subscribe_user(): void
    {
        $user = User::factory()->create([
            'newsletter_subscribed' => false,
            'newsletter_unsubscribed_at' => now(),
        ]);

        $service = app(NewsletterService::class);
        $service->subscribe($user);

        $user->refresh();
        $this->assertTrue($user->newsletter_subscribed);
        $this->assertNull($user->newsletter_unsubscribed_at);
    }

    public function test_service_can_unsubscribe_user(): void
    {
        $user = User::factory()->create([
            'newsletter_subscribed' => true,
        ]);

        $service = app(NewsletterService::class);
        $service->unsubscribe($user);

        $user->refresh();
        $this->assertFalse($user->newsletter_subscribed);
        $this->assertNotNull($user->newsletter_unsubscribed_at);
    }

    public function test_service_can_unsubscribe_by_token(): void
    {
        $user = User::factory()->create([
            'newsletter_subscribed' => true,
        ]);

        $service = app(NewsletterService::class);
        $result = $service->unsubscribeByToken($user->newsletter_unsubscribe_token);

        $this->assertNotNull($result);
        $this->assertEquals($user->id, $result->id);

        $user->refresh();
        $this->assertFalse($user->newsletter_subscribed);
    }

    public function test_service_returns_null_for_invalid_token(): void
    {
        $service = app(NewsletterService::class);
        $result = $service->unsubscribeByToken('invalid-token');

        $this->assertNull($result);
    }

    // ========================================
    // NewsletterRecipient Tests
    // ========================================

    public function test_recipient_can_be_marked_as_sent(): void
    {
        $newsletter = Newsletter::factory()->create();
        $user = User::factory()->create();

        $recipient = NewsletterRecipient::create([
            'newsletter_id' => $newsletter->id,
            'user_id' => $user->id,
            'email' => $user->email,
            'locale' => 'en',
            'status' => NewsletterRecipient::STATUS_PENDING,
        ]);

        $recipient->markAsSent('ses-message-id-12345');

        $recipient->refresh();
        $this->assertEquals(NewsletterRecipient::STATUS_SENT, $recipient->status);
        $this->assertEquals('ses-message-id-12345', $recipient->ses_message_id);
        $this->assertNotNull($recipient->sent_at);
    }

    public function test_recipient_can_be_marked_as_opened(): void
    {
        $newsletter = Newsletter::factory()->create(['total_opened' => 0]);
        $user = User::factory()->create();

        $recipient = NewsletterRecipient::create([
            'newsletter_id' => $newsletter->id,
            'user_id' => $user->id,
            'email' => $user->email,
            'locale' => 'en',
            'status' => NewsletterRecipient::STATUS_SENT,
        ]);

        $recipient->markAsOpened();

        $recipient->refresh();
        $newsletter->refresh();

        $this->assertNotNull($recipient->opened_at);
        $this->assertEquals(1, $newsletter->total_opened);
    }

    public function test_recipient_open_only_counted_once(): void
    {
        $newsletter = Newsletter::factory()->create(['total_opened' => 0]);
        $user = User::factory()->create();

        $recipient = NewsletterRecipient::create([
            'newsletter_id' => $newsletter->id,
            'user_id' => $user->id,
            'email' => $user->email,
            'locale' => 'en',
            'status' => NewsletterRecipient::STATUS_SENT,
        ]);

        $recipient->markAsOpened();
        $recipient->markAsOpened(); // Second call

        $newsletter->refresh();
        $this->assertEquals(1, $newsletter->total_opened);
    }

    public function test_recipient_bounce_marks_user_as_bounced(): void
    {
        $newsletter = Newsletter::factory()->create();
        $user = User::factory()->create(['email_bounced_at' => null]);

        $recipient = NewsletterRecipient::create([
            'newsletter_id' => $newsletter->id,
            'user_id' => $user->id,
            'email' => $user->email,
            'locale' => 'en',
            'status' => NewsletterRecipient::STATUS_SENT,
        ]);

        $recipient->markAsBounced();

        $user->refresh();
        $this->assertNotNull($user->email_bounced_at);
    }
}
