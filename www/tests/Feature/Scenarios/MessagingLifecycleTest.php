<?php

/**
 * Messaging System Lifecycle Tests
 *
 * Comprehensive tests for the complete messaging/support system lifecycle:
 * - Thread creation from profile
 * - Admin reply and status updates
 * - Unread count tracking
 * - Thread viewing and marking as read
 * - Multiple threads per user
 * - Thread closing
 */

use App\Models\MessageCategory;
use App\Models\MessageThread;
use App\Models\ThreadMessage;
use App\Models\User;
use App\Services\ThreadService;
use Illuminate\Support\Facades\Queue;

// ==================== SETUP ====================

beforeEach(function () {
    Queue::fake();

    // Create required categories
    $this->supportCategory = MessageCategory::create([
        'name_en' => 'Support',
        'name_nl' => 'Ondersteuning',
        'slug' => 'support',
        'is_visible' => true,
        'order' => 1,
    ]);

    $this->bugCategory = MessageCategory::create([
        'name_en' => 'Bug Report',
        'name_nl' => 'Bug melden',
        'slug' => 'bug',
        'is_visible' => true,
        'order' => 2,
    ]);
});

// ==================== HELPER FUNCTIONS ====================

function createMessagingTestUser(array $attributes = []): User
{
    return User::factory()->create(array_merge([
        'email_verified_at' => now(),
    ], $attributes));
}

function createMessageThread(User $user, MessageCategory $category, array $attributes = []): MessageThread
{
    return MessageThread::create(array_merge([
        'user_id' => $user->id,
        'category_id' => $category->id,
        'title' => 'Test question',
        'status' => MessageThread::STATUS_OPEN,
        'unread_count_user' => 0,
        'unread_count_admin' => 1,
        'source' => 'profile-messages',
        'last_message_at' => now(),
        'last_message_from' => MessageThread::SENDER_USER,
    ], $attributes));
}

function addUserMessage(MessageThread $thread, User $user, string $content): ThreadMessage
{
    $message = ThreadMessage::create([
        'thread_id' => $thread->id,
        'sender_id' => $user->id,
        'sender_type' => MessageThread::SENDER_USER,
        'content' => $content,
        'is_read' => false,
    ]);

    $thread->update([
        'last_message_at' => now(),
        'last_message_from' => MessageThread::SENDER_USER,
    ]);
    $thread->incrementUnreadForAdmin();

    return $message;
}

function addAdminReply(MessageThread $thread, string $content): ThreadMessage
{
    $message = ThreadMessage::create([
        'thread_id' => $thread->id,
        'sender_id' => null, // Admin without specific user
        'sender_type' => MessageThread::SENDER_ADMIN,
        'content' => $content,
        'is_read' => false,
    ]);

    $thread->update([
        'status' => MessageThread::STATUS_WAITING_FOR_USER,
        'last_message_at' => now(),
        'last_message_from' => MessageThread::SENDER_ADMIN,
        'unread_count_admin' => 0,
    ]);
    $thread->incrementUnreadForUser();

    return $message;
}

// ==================== GROUP 1: THREAD CREATION ====================

describe('Thread Creation', function () {

    it('creates a new support thread from profile', function () {
        $user = createMessagingTestUser();

        $response = $this->actingAs($user)
            ->post('/profile/messages', [
                'category_id' => $this->supportCategory->id,
                'content' => 'I need help with my PDF conversion',
            ]);

        $response->assertRedirect();

        // Verify thread created
        $thread = MessageThread::where('user_id', $user->id)->first();
        expect($thread)->not->toBeNull();
        expect($thread->category_id)->toBe($this->supportCategory->id);
        expect($thread->status)->toBe(MessageThread::STATUS_OPEN);
        expect($thread->unread_count_admin)->toBe(1);
        expect($thread->source)->toBe('profile-messages');

        // Verify message created
        expect($thread->messages()->count())->toBe(1);
        expect($thread->messages->first()->content)->toBe('I need help with my PDF conversion');
        expect($thread->messages->first()->sender_type)->toBe(MessageThread::SENDER_USER);
    });

    it('creates thread with different categories', function () {
        $user = createMessagingTestUser();

        $response = $this->actingAs($user)
            ->post('/profile/messages', [
                'category_id' => $this->bugCategory->id,
                'content' => 'Found a bug in the merger',
            ]);

        $response->assertRedirect();

        $thread = MessageThread::where('user_id', $user->id)->first();
        expect($thread->category_id)->toBe($this->bugCategory->id);
    });

    it('validates required fields when creating thread', function () {
        $user = createMessagingTestUser();

        // Missing content
        $response = $this->actingAs($user)
            ->post('/profile/messages', [
                'category_id' => $this->supportCategory->id,
            ]);

        $response->assertSessionHasErrors('content');

        // Missing category
        $response = $this->actingAs($user)
            ->post('/profile/messages', [
                'content' => 'Test message',
            ]);

        $response->assertSessionHasErrors('category_id');
    });

    it('requires authentication to create thread', function () {
        $response = $this->post('/profile/messages', [
            'category_id' => $this->supportCategory->id,
            'content' => 'Test message',
        ]);

        $response->assertRedirect('/login');
    });

});

// ==================== GROUP 2: UNREAD COUNT TRACKING ====================

describe('Unread Count Tracking', function () {

    it('increments admin unread count when user sends message', function () {
        $user = createMessagingTestUser();
        $thread = createMessageThread($user, $this->supportCategory, [
            'unread_count_admin' => 0,
        ]);

        addUserMessage($thread, $user, 'New user message');

        expect($thread->fresh()->unread_count_admin)->toBe(1);
    });

    it('increments user unread count when admin replies', function () {
        $user = createMessagingTestUser();
        $thread = createMessageThread($user, $this->supportCategory, [
            'unread_count_user' => 0,
        ]);

        addAdminReply($thread, 'Admin response');

        expect($thread->fresh()->unread_count_user)->toBe(1);
    });

    it('accumulates unread counts with multiple messages', function () {
        $user = createMessagingTestUser();
        $thread = createMessageThread($user, $this->supportCategory, [
            'unread_count_user' => 0,
            'unread_count_admin' => 0,
        ]);

        // Admin sends 3 replies
        addAdminReply($thread, 'Reply 1');
        $thread->refresh();
        addAdminReply($thread, 'Reply 2');
        $thread->refresh();
        addAdminReply($thread, 'Reply 3');

        expect($thread->fresh()->unread_count_user)->toBe(3);
    });

    it('resets user unread count when viewing thread', function () {
        $user = createMessagingTestUser();
        $thread = createMessageThread($user, $this->supportCategory, [
            'unread_count_user' => 5,
        ]);

        // User views the thread
        $response = $this->actingAs($user)
            ->get("/profile/messages/{$thread->uuid}");

        $response->assertOk();
        expect($thread->fresh()->unread_count_user)->toBe(0);
    });

    it('counts threads with unread messages correctly', function () {
        $user = createMessagingTestUser();

        // Create 3 threads, 2 with unread messages
        createMessageThread($user, $this->supportCategory, ['unread_count_user' => 2]);
        createMessageThread($user, $this->bugCategory, ['unread_count_user' => 1]);
        createMessageThread($user, $this->supportCategory, ['unread_count_user' => 0]);

        $unreadCount = $user->messageThreads()
            ->where('unread_count_user', '>', 0)
            ->count();

        expect($unreadCount)->toBe(2);
    });

});

// ==================== GROUP 3: STATUS TRANSITIONS ====================

describe('Thread Status Transitions', function () {

    it('changes status to waiting_for_user when admin replies', function () {
        $user = createMessagingTestUser();
        $thread = createMessageThread($user, $this->supportCategory, [
            'status' => MessageThread::STATUS_OPEN,
        ]);

        addAdminReply($thread, 'We will look into this');

        expect($thread->fresh()->status)->toBe(MessageThread::STATUS_WAITING_FOR_USER);
    });

    it('changes status back to open when user replies', function () {
        $user = createMessagingTestUser();
        $thread = createMessageThread($user, $this->supportCategory, [
            'status' => MessageThread::STATUS_WAITING_FOR_USER,
        ]);

        // User replies via controller
        $response = $this->actingAs($user)
            ->post("/profile/messages/{$thread->uuid}/reply", [
                'content' => 'Thanks, here is more info',
            ]);

        $response->assertRedirect();
        expect($thread->fresh()->status)->toBe(MessageThread::STATUS_OPEN);
    });

    it('prevents reply to closed thread', function () {
        $user = createMessagingTestUser();
        $thread = createMessageThread($user, $this->supportCategory, [
            'status' => MessageThread::STATUS_CLOSED,
        ]);
        addUserMessage($thread, $user, 'Initial message');

        $response = $this->actingAs($user)
            ->post("/profile/messages/{$thread->uuid}/reply", [
                'content' => 'Trying to reply to closed thread',
            ]);

        $response->assertSessionHas('error');

        // No new message should be created
        expect($thread->messages()->count())->toBe(1);
    });

});

// ==================== GROUP 4: THREAD VIEWING ====================

describe('Thread Viewing', function () {

    it('displays messages in chronological order', function () {
        $user = createMessagingTestUser();
        $thread = createMessageThread($user, $this->supportCategory);

        // Add messages
        $message1 = addUserMessage($thread, $user, 'First question');
        sleep(1); // Ensure different timestamps
        $message2 = addAdminReply($thread, 'Admin response');
        sleep(1);
        $message3 = addUserMessage($thread, $user, 'Follow up');

        $response = $this->actingAs($user)
            ->get("/profile/messages/{$thread->uuid}");

        $response->assertOk();
        $response->assertSeeInOrder([
            'First question',
            'Admin response',
            'Follow up',
        ]);
    });

    it('prevents viewing another users thread', function () {
        $user1 = createMessagingTestUser();
        $user2 = createMessagingTestUser();
        $thread = createMessageThread($user1, $this->supportCategory);

        $response = $this->actingAs($user2)
            ->get("/profile/messages/{$thread->uuid}");

        $response->assertForbidden();
    });

    it('shows latest message preview in thread list', function () {
        $user = createMessagingTestUser();
        $thread = createMessageThread($user, $this->supportCategory);
        addUserMessage($thread, $user, 'My first message');
        addAdminReply($thread, 'This is the latest reply from support');

        $response = $this->actingAs($user)
            ->get('/profile/messages');

        $response->assertOk();
        $response->assertSee('This is the latest reply from support');
    });

});

// ==================== GROUP 5: MULTIPLE THREADS ====================

describe('Multiple Threads Management', function () {

    it('user can have multiple open threads', function () {
        $user = createMessagingTestUser();

        createMessageThread($user, $this->supportCategory, ['title' => 'Question 1']);
        createMessageThread($user, $this->bugCategory, ['title' => 'Bug report']);
        createMessageThread($user, $this->supportCategory, ['title' => 'Question 2']);

        expect($user->messageThreads()->count())->toBe(3);
    });

    it('orders threads by last message date', function () {
        $user = createMessagingTestUser();

        $oldThread = createMessageThread($user, $this->supportCategory, [
            'title' => 'Old thread',
            'last_message_at' => now()->subDays(5),
        ]);

        $newThread = createMessageThread($user, $this->bugCategory, [
            'title' => 'New thread',
            'last_message_at' => now(),
        ]);

        $response = $this->actingAs($user)
            ->get('/profile/messages');

        $response->assertOk();
        $response->assertSeeInOrder(['New thread', 'Old thread']);
    });

    it('counts total unread across all threads', function () {
        $user = createMessagingTestUser();

        createMessageThread($user, $this->supportCategory, ['unread_count_user' => 3]);
        createMessageThread($user, $this->bugCategory, ['unread_count_user' => 2]);
        createMessageThread($user, $this->supportCategory, ['unread_count_user' => 0]);

        $totalUnread = $user->messageThreads()
            ->where('unread_count_user', '>', 0)
            ->count();

        expect($totalUnread)->toBe(2);
    });

});

// ==================== GROUP 6: THREAD MESSAGE TYPES ====================

describe('Message Types', function () {

    it('correctly identifies user messages', function () {
        $user = createMessagingTestUser();
        $thread = createMessageThread($user, $this->supportCategory);
        $message = addUserMessage($thread, $user, 'User message');

        expect($message->isFromUser())->toBeTrue();
        expect($message->isFromAdmin())->toBeFalse();
        expect($message->isFromLlm())->toBeFalse();
        expect($message->isFromSystem())->toBeFalse();
    });

    it('correctly identifies admin messages', function () {
        $user = createMessagingTestUser();
        $thread = createMessageThread($user, $this->supportCategory);
        $message = addAdminReply($thread, 'Admin message');

        expect($message->isFromAdmin())->toBeTrue();
        expect($message->isFromUser())->toBeFalse();
    });

    it('correctly identifies LLM messages', function () {
        $user = createMessagingTestUser();
        $thread = createMessageThread($user, $this->supportCategory);

        $message = ThreadMessage::create([
            'thread_id' => $thread->id,
            'sender_type' => MessageThread::SENDER_LLM,
            'content' => 'AI response',
        ]);

        expect($message->isFromLlm())->toBeTrue();
        expect($message->isFromAdmin())->toBeFalse();
        expect($message->isFromUser())->toBeFalse();
    });

    it('correctly identifies system messages', function () {
        $user = createMessagingTestUser();
        $thread = createMessageThread($user, $this->supportCategory);

        $message = ThreadMessage::create([
            'thread_id' => $thread->id,
            'sender_type' => MessageThread::SENDER_SYSTEM,
            'content' => 'Thread was closed automatically',
        ]);

        expect($message->isFromSystem())->toBeTrue();
    });

});

// ==================== GROUP 7: CONTEXT INFORMATION ====================

describe('Thread Context', function () {

    it('stores context information with thread', function () {
        $user = createMessagingTestUser();
        $thread = createMessageThread($user, $this->supportCategory, [
            'context_json' => [
                'page_url' => 'https://example.com/word-to-pdf',
                'converter_type' => 'word-to-pdf',
                'browser' => 'Chrome 119',
            ],
        ]);

        expect($thread->getContext('page_url'))->toBe('https://example.com/word-to-pdf');
        expect($thread->getContext('converter_type'))->toBe('word-to-pdf');
        expect($thread->getContext('browser'))->toBe('Chrome 119');
        expect($thread->getContext('nonexistent', 'default'))->toBe('default');
    });

    it('stores feedback thumb with thread', function () {
        $user = createMessagingTestUser();

        $upThread = createMessageThread($user, $this->supportCategory, ['thumb' => 'up']);
        $downThread = createMessageThread($user, $this->supportCategory, ['thumb' => 'down']);

        expect($upThread->thumb)->toBe('up');
        expect($downThread->thumb)->toBe('down');
    });

});

// ==================== GROUP 8: EDGE CASES ====================

describe('Edge Cases', function () {

    it('handles thread with no messages gracefully', function () {
        $user = createMessagingTestUser();
        $thread = createMessageThread($user, $this->supportCategory);

        // No messages added
        expect($thread->messages()->count())->toBe(0);
        expect($thread->latestMessage)->toBeNull();
    });

    it('handles very long message content', function () {
        $user = createMessagingTestUser();
        $thread = createMessageThread($user, $this->supportCategory);

        $longContent = str_repeat('Lorem ipsum dolor sit amet. ', 100);
        $message = addUserMessage($thread, $user, $longContent);

        expect($message->content)->toBe($longContent);
    });

    it('enforces max content length validation', function () {
        $user = createMessagingTestUser();
        $thread = createMessageThread($user, $this->supportCategory);
        addUserMessage($thread, $user, 'Initial message');

        $tooLongContent = str_repeat('x', 2001); // Over 2000 char limit for replies

        $response = $this->actingAs($user)
            ->post("/profile/messages/{$thread->uuid}/reply", [
                'content' => $tooLongContent,
            ]);

        $response->assertSessionHasErrors('content');
    });

});

// ==================== GROUP 9: NOTIFICATION TRACKING ====================

describe('Notification Tracking', function () {

    it('tracks notification_sent_at on admin messages', function () {
        $user = createMessagingTestUser();
        $thread = createMessageThread($user, $this->supportCategory);
        $message = addAdminReply($thread, 'Admin response');

        // Initially null
        expect($message->notification_sent_at)->toBeNull();

        // Can be set
        $message->update(['notification_sent_at' => now()]);
        expect($message->fresh()->notification_sent_at)->not->toBeNull();
    });

    it('casts notification_sent_at to datetime', function () {
        $user = createMessagingTestUser();
        $thread = createMessageThread($user, $this->supportCategory);
        $message = addAdminReply($thread, 'Admin response');

        $message->update(['notification_sent_at' => '2025-01-15 10:30:00']);

        expect($message->fresh()->notification_sent_at)->toBeInstanceOf(\Carbon\Carbon::class);
    });

    it('admin reply does not send immediate email', function () {
        \Illuminate\Support\Facades\Mail::fake();

        $user = createMessagingTestUser();
        $admin = createMessagingTestUser();
        $thread = createMessageThread($user, $this->supportCategory);

        // Use ThreadService to add admin reply
        $threadService = app(\App\Services\ThreadService::class);
        $threadService->addAdminReply($thread, $admin, 'Admin response');

        // No immediate email should be sent
        \Illuminate\Support\Facades\Mail::assertNothingSent();
    });

});
