<?php

use App\Models\Organization;
use App\Models\OrganizationSenderLog;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('logs a sent email', function () {
    $org = Organization::factory()->create();

    $log = OrganizationSenderLog::logSent($org->id, 'user@example.com', 'welcome', 'onboarding', 'msg-123');

    expect($log->status)->toBe('sent');
    expect($log->recipient_email)->toBe('user@example.com');
    expect($log->template_alias)->toBe('welcome');
    expect($log->tag)->toBe('onboarding');
    expect($log->postmark_message_id)->toBe('msg-123');
});

it('logs a failed email', function () {
    $org = Organization::factory()->create();

    $log = OrganizationSenderLog::logFailed($org->id, 'user@example.com', 'Connection timeout', '500', 'welcome', 'onboarding');

    expect($log->status)->toBe('failed');
    expect($log->error_message)->toBe('Connection timeout');
    expect($log->error_code)->toBe('500');
});

it('logs a rate limited email', function () {
    $org = Organization::factory()->create();

    $log = OrganizationSenderLog::logRateLimited($org->id, 'user@example.com', 'welcome');

    expect($log->status)->toBe('rate_limited');
});

it('logs a bounced email', function () {
    $org = Organization::factory()->create();

    $log = OrganizationSenderLog::logBounced($org->id, 'user@example.com', 'welcome');

    expect($log->status)->toBe('bounced');
});

it('belongs to organization', function () {
    $org = Organization::factory()->create();
    $log = OrganizationSenderLog::logSent($org->id, 'user@example.com');

    expect($log->organization->id)->toBe($org->id);
});

it('returns correct stats for organization', function () {
    $org = Organization::factory()->create();

    OrganizationSenderLog::logSent($org->id, 'a@example.com');
    OrganizationSenderLog::logSent($org->id, 'b@example.com');
    OrganizationSenderLog::logFailed($org->id, 'c@example.com', 'error');
    OrganizationSenderLog::logBounced($org->id, 'd@example.com');

    $stats = OrganizationSenderLog::getStats($org->id);

    expect($stats['sent'])->toBe(2);
    expect($stats['failed'])->toBe(1);
    expect($stats['bounced'])->toBe(1);
    expect($stats['rate_limited'])->toBe(0);
    expect($stats['total'])->toBe(4);
});

it('stats only counts within days window', function () {
    $org = Organization::factory()->create();

    OrganizationSenderLog::logSent($org->id, 'a@example.com');

    // Create an old log by directly setting created_at
    $old = OrganizationSenderLog::logSent($org->id, 'b@example.com');
    OrganizationSenderLog::where('id', $old->id)->update(['created_at' => now()->subDays(10)]);

    $stats = OrganizationSenderLog::getStats($org->id, 7);

    expect($stats['sent'])->toBe(1);
    expect($stats['total'])->toBe(1);
});

it('stats are scoped to organization', function () {
    $org1 = Organization::factory()->create();
    $org2 = Organization::factory()->create();

    OrganizationSenderLog::logSent($org1->id, 'a@example.com');
    OrganizationSenderLog::logSent($org2->id, 'b@example.com');
    OrganizationSenderLog::logSent($org2->id, 'c@example.com');

    expect(OrganizationSenderLog::getStats($org1->id)['total'])->toBe(1);
    expect(OrganizationSenderLog::getStats($org2->id)['total'])->toBe(2);
});
