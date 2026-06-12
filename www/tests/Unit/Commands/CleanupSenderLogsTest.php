<?php

use App\Models\Organization;
use App\Models\OrganizationSenderLog;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('deletes logs older than default 30 days', function () {
    $org = Organization::factory()->create();

    $recent = OrganizationSenderLog::logSent($org->id, 'a@example.com');
    $old = OrganizationSenderLog::logSent($org->id, 'b@example.com');
    OrganizationSenderLog::where('id', $old->id)->update(['created_at' => now()->subDays(31)]);

    $this->artisan('sender:cleanup-logs')
        ->assertExitCode(0);

    expect(OrganizationSenderLog::count())->toBe(1);
    expect(OrganizationSenderLog::first()->id)->toBe($recent->id);
});

it('respects custom days option', function () {
    $org = Organization::factory()->create();

    $log = OrganizationSenderLog::logSent($org->id, 'a@example.com');
    OrganizationSenderLog::where('id', $log->id)->update(['created_at' => now()->subDays(8)]);

    $this->artisan('sender:cleanup-logs --days=7')
        ->assertExitCode(0);

    expect(OrganizationSenderLog::count())->toBe(0);
});

it('reports zero when nothing to delete', function () {
    $this->artisan('sender:cleanup-logs')
        ->expectsOutputToContain('Deleted 0 sender logs')
        ->assertExitCode(0);
});
