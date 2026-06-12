<?php

namespace Tests\Unit\Models;

use App\Models\MessageCategory;
use App\Models\MessageThread;
use App\Models\ThreadMessage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MessageThreadTest extends TestCase
{
    use RefreshDatabase;

    // ==================== RELATIONSHIP TESTS ====================

    public function test_thread_belongs_to_user(): void
    {
        $user = User::factory()->create();
        $thread = $this->createThread($user);

        $this->assertInstanceOf(User::class, $thread->user);
        $this->assertEquals($user->id, $thread->user->id);
    }

    public function test_thread_belongs_to_category(): void
    {
        $user = User::factory()->create();
        $category = $this->createCategory();
        $thread = $this->createThread($user, $category);

        $this->assertInstanceOf(MessageCategory::class, $thread->category);
        $this->assertEquals($category->id, $thread->category->id);
    }

    public function test_thread_has_many_messages(): void
    {
        $user = User::factory()->create();
        $thread = $this->createThread($user);

        // Create 3 messages
        for ($i = 0; $i < 3; $i++) {
            ThreadMessage::create([
                'thread_id' => $thread->id,
                'sender_id' => $user->id,
                'sender_type' => MessageThread::SENDER_USER,
                'content' => "Message {$i}",
            ]);
        }

        $this->assertCount(3, $thread->messages);
    }

    public function test_latest_message_returns_most_recent_message(): void
    {
        $user = User::factory()->create();
        $thread = $this->createThread($user);

        // Create messages with different timestamps
        ThreadMessage::create([
            'thread_id' => $thread->id,
            'sender_id' => $user->id,
            'sender_type' => MessageThread::SENDER_USER,
            'content' => 'First message',
            'created_at' => now()->subMinutes(10),
        ]);

        ThreadMessage::create([
            'thread_id' => $thread->id,
            'sender_id' => $user->id,
            'sender_type' => MessageThread::SENDER_USER,
            'content' => 'Second message',
            'created_at' => now()->subMinutes(5),
        ]);

        $latestMessage = ThreadMessage::create([
            'thread_id' => $thread->id,
            'sender_id' => null,
            'sender_type' => MessageThread::SENDER_ADMIN,
            'content' => 'Latest admin reply',
            'created_at' => now(),
        ]);

        // Refresh thread to load relationship
        $thread->refresh();

        $this->assertNotNull($thread->latestMessage);
        $this->assertEquals($latestMessage->id, $thread->latestMessage->id);
        $this->assertEquals('Latest admin reply', $thread->latestMessage->content);
    }

    public function test_latest_message_returns_null_when_no_messages(): void
    {
        $user = User::factory()->create();
        $thread = $this->createThread($user);

        $this->assertNull($thread->latestMessage);
    }

    // ==================== STATUS TESTS ====================

    public function test_is_open_returns_true_for_open_thread(): void
    {
        $user = User::factory()->create();
        $thread = $this->createThread($user, null, ['status' => MessageThread::STATUS_OPEN]);

        $this->assertTrue($thread->isOpen());
        $this->assertFalse($thread->isClosed());
    }

    public function test_is_closed_returns_true_for_closed_thread(): void
    {
        $user = User::factory()->create();
        $thread = $this->createThread($user, null, ['status' => MessageThread::STATUS_CLOSED]);

        $this->assertTrue($thread->isClosed());
        $this->assertFalse($thread->isOpen());
    }

    public function test_status_constants_have_correct_values(): void
    {
        $this->assertEquals('open', MessageThread::STATUS_OPEN);
        $this->assertEquals('waiting_for_user', MessageThread::STATUS_WAITING_FOR_USER);
        $this->assertEquals('closed', MessageThread::STATUS_CLOSED);
    }

    public function test_sender_type_constants_have_correct_values(): void
    {
        $this->assertEquals('user', MessageThread::SENDER_USER);
        $this->assertEquals('admin', MessageThread::SENDER_ADMIN);
        $this->assertEquals('llm', MessageThread::SENDER_LLM);
        $this->assertEquals('system', MessageThread::SENDER_SYSTEM);
    }

    // ==================== UNREAD COUNT TESTS ====================

    public function test_increment_unread_for_user_increases_count(): void
    {
        $user = User::factory()->create();
        $thread = $this->createThread($user, null, ['unread_count_user' => 0]);

        $thread->incrementUnreadForUser();
        $thread->incrementUnreadForUser();

        $this->assertEquals(2, $thread->fresh()->unread_count_user);
    }

    public function test_increment_unread_for_admin_increases_count(): void
    {
        $user = User::factory()->create();
        $thread = $this->createThread($user, null, ['unread_count_admin' => 0]);

        $thread->incrementUnreadForAdmin();
        $thread->incrementUnreadForAdmin();
        $thread->incrementUnreadForAdmin();

        $this->assertEquals(3, $thread->fresh()->unread_count_admin);
    }

    public function test_mark_read_for_user_resets_count(): void
    {
        $user = User::factory()->create();
        $thread = $this->createThread($user, null, ['unread_count_user' => 5]);

        // Add an admin message that should be marked as read
        ThreadMessage::create([
            'thread_id' => $thread->id,
            'sender_type' => MessageThread::SENDER_ADMIN,
            'content' => 'Admin message',
            'is_read' => false,
        ]);

        $thread->markReadForUser();

        $this->assertEquals(0, $thread->fresh()->unread_count_user);
    }

    public function test_mark_read_for_admin_resets_count(): void
    {
        $user = User::factory()->create();
        $thread = $this->createThread($user, null, ['unread_count_admin' => 3]);

        // Add a user message that should be marked as read
        ThreadMessage::create([
            'thread_id' => $thread->id,
            'sender_id' => $user->id,
            'sender_type' => MessageThread::SENDER_USER,
            'content' => 'User message',
            'is_read' => false,
        ]);

        $thread->markReadForAdmin();

        $this->assertEquals(0, $thread->fresh()->unread_count_admin);
    }

    // ==================== UPDATE LAST MESSAGE TESTS ====================

    public function test_update_last_message_sets_timestamp_and_sender(): void
    {
        $user = User::factory()->create();
        $thread = $this->createThread($user, null, [
            'last_message_at' => null,
            'last_message_from' => null,
        ]);

        $thread->updateLastMessage(MessageThread::SENDER_ADMIN);

        $thread->refresh();
        $this->assertNotNull($thread->last_message_at);
        $this->assertEquals(MessageThread::SENDER_ADMIN, $thread->last_message_from);
    }

    // ==================== CONTEXT AND SETTINGS TESTS ====================

    public function test_get_context_returns_value(): void
    {
        $user = User::factory()->create();
        $thread = $this->createThread($user, null, [
            'context_json' => [
                'page_url' => 'https://example.com/page',
                'browser' => 'Chrome',
            ],
        ]);

        $this->assertEquals('https://example.com/page', $thread->getContext('page_url'));
        $this->assertEquals('Chrome', $thread->getContext('browser'));
        $this->assertNull($thread->getContext('nonexistent'));
        $this->assertEquals('default', $thread->getContext('nonexistent', 'default'));
    }

    public function test_get_setting_returns_value(): void
    {
        $user = User::factory()->create();
        $thread = $this->createThread($user, null, [
            'settings_json' => [
                'notify_email' => true,
                'priority' => 'high',
            ],
        ]);

        $this->assertTrue($thread->getSetting('notify_email'));
        $this->assertEquals('high', $thread->getSetting('priority'));
        $this->assertNull($thread->getSetting('nonexistent'));
    }

    // ==================== SCOPES TESTS ====================

    public function test_scope_open_filters_correctly(): void
    {
        $user = User::factory()->create();
        $this->createThread($user, null, ['status' => MessageThread::STATUS_OPEN]);
        $this->createThread($user, null, ['status' => MessageThread::STATUS_OPEN]);
        $this->createThread($user, null, ['status' => MessageThread::STATUS_CLOSED]);

        $openThreads = MessageThread::open()->get();

        $this->assertCount(2, $openThreads);
    }

    public function test_scope_unread_for_admin_filters_correctly(): void
    {
        $user = User::factory()->create();
        $this->createThread($user, null, ['unread_count_admin' => 3]);
        $this->createThread($user, null, ['unread_count_admin' => 1]);
        $this->createThread($user, null, ['unread_count_admin' => 0]);

        $unreadThreads = MessageThread::unreadForAdmin()->get();

        $this->assertCount(2, $unreadThreads);
    }

    public function test_scope_unread_for_user_filters_correctly(): void
    {
        $user = User::factory()->create();
        $this->createThread($user, null, ['unread_count_user' => 2]);
        $this->createThread($user, null, ['unread_count_user' => 0]);
        $this->createThread($user, null, ['unread_count_user' => 0]);

        $unreadThreads = MessageThread::unreadForUser()->get();

        $this->assertCount(1, $unreadThreads);
    }

    // ==================== HELPER METHODS ====================

    private function createCategory(): MessageCategory
    {
        return MessageCategory::create([
            'name_en' => 'Support',
            'name_nl' => 'Ondersteuning',
            'slug' => 'support-'.uniqid(),
            'is_visible' => true,
            'order' => 1,
        ]);
    }

    private function createThread(User $user, ?MessageCategory $category = null, array $attributes = []): MessageThread
    {
        return MessageThread::create(array_merge([
            'user_id' => $user->id,
            'category_id' => $category?->id,
            'title' => 'Test Thread',
            'status' => MessageThread::STATUS_OPEN,
            'unread_count_user' => 0,
            'unread_count_admin' => 0,
        ], $attributes));
    }
}
