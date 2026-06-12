<?php

declare(strict_types=1);

use App\Filament\Resources\UserResource;
use App\Filament\Resources\UserResource\RelationManagers\AnalyticsEventsRelationManager;
use App\Filament\Resources\UserResource\RelationManagers\MessageThreadsRelationManager;
use App\Models\AnalyticsEvent;
use App\Models\MessageThread;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->admin = User::factory()->create(['is_admin' => true]);
    $this->actingAs($this->admin, 'admin');
});

describe('AnalyticsEventsRelationManager', function () {
    it('is registered on UserResource', function () {
        $relations = UserResource::getRelations();
        $relationClasses = array_map(fn ($r) => is_string($r) ? $r : get_class($r), $relations);

        expect($relationClasses)->toContain(AnalyticsEventsRelationManager::class);
    });

    it('renders without errors', function () {
        $user = User::factory()->create();

        Livewire::test(AnalyticsEventsRelationManager::class, [
            'ownerRecord' => $user,
            'pageClass' => \App\Filament\Resources\UserResource\Pages\ViewUser::class,
        ])->assertSuccessful();
    });

    it('has correct relationship name', function () {
        expect(AnalyticsEventsRelationManager::getRelationshipName())->toBe('analyticsEvents');
    });

    it('has correct title', function () {
        $user = User::factory()->create();

        // The title is set via static property
        expect(AnalyticsEventsRelationManager::getTitle($user, \App\Filament\Resources\UserResource\Pages\ViewUser::class))->toBe('Activity & Emails');
    });

    it('creates analytics event correctly', function () {
        $user = User::factory()->create();

        // Create an analytics event for this user
        $event = AnalyticsEvent::create([
            'user_id' => $user->id,
            'event' => 'email_sent',
            'success' => true,
            'meta' => ['type' => 'welcome', 'recipient' => $user->email],
        ]);

        expect($event->user_id)->toBe($user->id);
        expect($event->event)->toBe('email_sent');
        expect($event->success)->toBeTrue();
        expect($event->meta['type'])->toBe('welcome');
    });

    it('user has analytics events relationship', function () {
        $user = User::factory()->create();

        AnalyticsEvent::create([
            'user_id' => $user->id,
            'event' => 'email_sent',
            'success' => true,
        ]);

        AnalyticsEvent::create([
            'user_id' => $user->id,
            'event' => 'user_logged_in',
            'success' => true,
        ]);

        expect($user->analyticsEvents()->count())->toBe(2);
    });

    it('analytics event has postmark message id in meta', function () {
        $user = User::factory()->create();

        $event = AnalyticsEvent::create([
            'user_id' => $user->id,
            'event' => 'email_sent',
            'success' => true,
            'meta' => [
                'type' => 'welcome',
                'postmark_message_id' => 'test-message-id-123',
            ],
        ]);

        expect($event->meta['postmark_message_id'])->toBe('test-message-id-123');
    });

    it('can determine if event is email event', function () {
        $user = User::factory()->create();

        $emailEvent = AnalyticsEvent::create([
            'user_id' => $user->id,
            'event' => 'email_sent',
            'success' => true,
        ]);

        $loginEvent = AnalyticsEvent::create([
            'user_id' => $user->id,
            'event' => 'user_logged_in',
            'success' => true,
        ]);

        expect(str_contains($emailEvent->event, 'email'))->toBeTrue();
        expect(str_contains($loginEvent->event, 'email'))->toBeFalse();
    });
});

describe('MessageThreadsRelationManager', function () {
    it('is registered on UserResource', function () {
        $relations = UserResource::getRelations();
        $relationClasses = array_map(fn ($r) => is_string($r) ? $r : get_class($r), $relations);

        expect($relationClasses)->toContain(MessageThreadsRelationManager::class);
    });

    it('renders without errors', function () {
        $user = User::factory()->create();

        Livewire::test(MessageThreadsRelationManager::class, [
            'ownerRecord' => $user,
            'pageClass' => \App\Filament\Resources\UserResource\Pages\ViewUser::class,
        ])->assertSuccessful();
    });

    it('has correct relationship name', function () {
        expect(MessageThreadsRelationManager::getRelationshipName())->toBe('messageThreads');
    });

    it('has correct title', function () {
        $user = User::factory()->create();
        expect(MessageThreadsRelationManager::getTitle($user, \App\Filament\Resources\UserResource\Pages\ViewUser::class))->toBe('Support Threads');
    });

    it('creates message thread correctly', function () {
        $user = User::factory()->create();

        $thread = MessageThread::create([
            'user_id' => $user->id,
            'title' => 'Support Request',
            'status' => 'open',
            'last_message_at' => now(),
        ]);

        expect($thread->user_id)->toBe($user->id);
        expect($thread->title)->toBe('Support Request');
        expect($thread->status)->toBe('open');
    });

    it('user has message threads relationship', function () {
        $user = User::factory()->create();

        MessageThread::create([
            'user_id' => $user->id,
            'title' => 'Thread 1',
            'status' => 'open',
            'last_message_at' => now(),
        ]);

        MessageThread::create([
            'user_id' => $user->id,
            'title' => 'Thread 2',
            'status' => 'closed',
            'last_message_at' => now(),
        ]);

        expect($user->messageThreads()->count())->toBe(2);
    });

    it('message thread has correct status values', function () {
        $user = User::factory()->create();

        $openThread = MessageThread::create([
            'user_id' => $user->id,
            'title' => 'Open',
            'status' => 'open',
            'last_message_at' => now(),
        ]);

        $closedThread = MessageThread::create([
            'user_id' => $user->id,
            'title' => 'Closed',
            'status' => 'closed',
            'last_message_at' => now(),
        ]);

        $waitingThread = MessageThread::create([
            'user_id' => $user->id,
            'title' => 'Waiting',
            'status' => 'waiting_for_user',
            'last_message_at' => now(),
        ]);

        expect($openThread->isOpen())->toBeTrue();
        expect($closedThread->isClosed())->toBeTrue();
        expect($waitingThread->status)->toBe('waiting_for_user');
    });

    it('message thread tracks unread counts', function () {
        $user = User::factory()->create();

        $thread = MessageThread::create([
            'user_id' => $user->id,
            'title' => 'Thread',
            'status' => 'open',
            'last_message_at' => now(),
            'unread_count_admin' => 0,
            'unread_count_user' => 0,
        ]);

        $thread->incrementUnreadForAdmin();
        $thread->refresh();
        expect($thread->unread_count_admin)->toBe(1);

        $thread->incrementUnreadForUser();
        $thread->refresh();
        expect($thread->unread_count_user)->toBe(1);
    });

    it('message thread can have thumb rating', function () {
        $user = User::factory()->create();

        $upThread = MessageThread::create([
            'user_id' => $user->id,
            'title' => 'Good Thread',
            'status' => 'closed',
            'thumb' => 'up',
            'last_message_at' => now(),
        ]);

        $downThread = MessageThread::create([
            'user_id' => $user->id,
            'title' => 'Bad Thread',
            'status' => 'closed',
            'thumb' => 'down',
            'last_message_at' => now(),
        ]);

        expect($upThread->thumb)->toBe('up');
        expect($downThread->thumb)->toBe('down');
    });
});
