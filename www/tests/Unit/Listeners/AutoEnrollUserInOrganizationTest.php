<?php

use App\Listeners\AutoEnrollUserInOrganization;
use App\Models\User;
use App\Services\OrganizationAutoEnrollmentService;
use Illuminate\Auth\Events\Verified;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Unit tests for AutoEnrollUserInOrganization listener
 *
 * This listener is a thin wrapper that delegates to OrganizationAutoEnrollmentService.
 * These tests verify that the listener correctly calls the service.
 */
it('delegates enrollment to OrganizationAutoEnrollmentService when Verified event is fired', function () {
    $user = User::factory()->create(['email' => 'test@example.com']);

    // Mock the service
    $mockService = Mockery::mock(OrganizationAutoEnrollmentService::class);
    $mockService->shouldReceive('enrollUser')
        ->once()
        ->with(Mockery::on(function ($arg) use ($user) {
            return $arg->id === $user->id;
        }))
        ->andReturn(collect()); // Return empty collection

    // Create listener with mocked service
    $listener = new AutoEnrollUserInOrganization($mockService);

    // Fire Verified event
    $event = new Verified($user);
    $listener->handle($event);

    // Mock expectations are verified automatically by Mockery
});

it('passes correct user to service when handling Verified event', function () {
    $user = User::factory()->create([
        'email' => 'john@company.com',
        'name' => 'John Doe',
    ]);

    // Mock the service to capture the user passed to it
    $capturedUser = null;
    $mockService = Mockery::mock(OrganizationAutoEnrollmentService::class);
    $mockService->shouldReceive('enrollUser')
        ->once()
        ->with(Mockery::capture($capturedUser))
        ->andReturn(collect());

    $listener = new AutoEnrollUserInOrganization($mockService);

    $event = new Verified($user);
    $listener->handle($event);

    // Verify the correct user was passed
    expect($capturedUser)->not->toBeNull();
    expect($capturedUser->id)->toBe($user->id);
    expect($capturedUser->email)->toBe('john@company.com');
});

it('handles service returning empty collection', function () {
    $user = User::factory()->create(['email' => 'test@example.com']);

    $mockService = Mockery::mock(OrganizationAutoEnrollmentService::class);
    $mockService->shouldReceive('enrollUser')
        ->once()
        ->andReturn(collect()); // No organizations enrolled

    $listener = new AutoEnrollUserInOrganization($mockService);

    $event = new Verified($user);

    // Should not throw any exceptions
    expect(fn () => $listener->handle($event))->not->toThrow(Exception::class);
});

it('handles service returning multiple organizations', function () {
    $user = User::factory()->create(['email' => 'test@example.com']);

    $mockOrganizations = collect([
        (object) ['id' => 1, 'name' => 'Org A'],
        (object) ['id' => 2, 'name' => 'Org B'],
    ]);

    $mockService = Mockery::mock(OrganizationAutoEnrollmentService::class);
    $mockService->shouldReceive('enrollUser')
        ->once()
        ->andReturn($mockOrganizations);

    $listener = new AutoEnrollUserInOrganization($mockService);

    $event = new Verified($user);

    // Should not throw any exceptions
    expect(fn () => $listener->handle($event))->not->toThrow(Exception::class);
});
