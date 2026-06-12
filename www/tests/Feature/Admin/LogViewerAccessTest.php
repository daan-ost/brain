<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\Gate;

describe('Log Viewer Access Control', function () {

    describe('viewLogViewer Gate', function () {
        it('denies access to unauthenticated users', function () {
            expect(Gate::allows('viewLogViewer'))->toBeFalse();
        });

        it('denies access to non-admin users', function () {
            $user = User::factory()->create(['is_admin' => false]);

            $this->actingAs($user, 'admin');

            expect(Gate::allows('viewLogViewer'))->toBeFalse();
        });

        it('allows access to users with is_admin flag', function () {
            $admin = User::factory()->create(['is_admin' => true]);

            $this->actingAs($admin, 'admin');

            expect(Gate::allows('viewLogViewer'))->toBeTrue();
        });

        it('allows access to users with admin role', function () {
            // Create the admin role for the web guard (User model's default guard)
            $role = \Spatie\Permission\Models\Role::findOrCreate('admin', 'web');

            $user = User::factory()->create(['is_admin' => false]);
            $user->assignRole($role);

            $this->actingAs($user, 'admin');

            expect(Gate::allows('viewLogViewer'))->toBeTrue();
        });
    });

    describe('downloadLogFile Gate', function () {
        it('denies access to unauthenticated users', function () {
            expect(Gate::allows('downloadLogFile'))->toBeFalse();
        });

        it('denies access to non-admin users', function () {
            $user = User::factory()->create(['is_admin' => false]);

            $this->actingAs($user, 'admin');

            expect(Gate::allows('downloadLogFile'))->toBeFalse();
        });

        it('allows access to admin users', function () {
            $admin = User::factory()->create(['is_admin' => true]);

            $this->actingAs($admin, 'admin');

            expect(Gate::allows('downloadLogFile'))->toBeTrue();
        });
    });

    describe('downloadLogFolder Gate', function () {
        it('denies access to unauthenticated users', function () {
            expect(Gate::allows('downloadLogFolder'))->toBeFalse();
        });

        it('allows access to admin users', function () {
            $admin = User::factory()->create(['is_admin' => true]);

            $this->actingAs($admin, 'admin');

            expect(Gate::allows('downloadLogFolder'))->toBeTrue();
        });
    });

    describe('deleteLogFile Gate', function () {
        it('always denies delete operations for security', function () {
            expect(Gate::allows('deleteLogFile'))->toBeFalse();
        });

        it('denies delete even for admin users', function () {
            $admin = User::factory()->create(['is_admin' => true]);

            $this->actingAs($admin, 'admin');

            expect(Gate::allows('deleteLogFile'))->toBeFalse();
        });
    });

    describe('deleteLogFolder Gate', function () {
        it('always denies delete operations for security', function () {
            expect(Gate::allows('deleteLogFolder'))->toBeFalse();
        });

        it('denies delete even for admin users', function () {
            $admin = User::factory()->create(['is_admin' => true]);

            $this->actingAs($admin, 'admin');

            expect(Gate::allows('deleteLogFolder'))->toBeFalse();
        });
    });

    describe('Log Viewer Route Access', function () {
        it('redirects unauthenticated users to login', function () {
            $this->get('/beheer/log-viewer')
                ->assertRedirect();
        });

        it('denies access to non-admin users', function () {
            $user = User::factory()->create(['is_admin' => false]);

            $this->actingAs($user, 'admin')
                ->get('/beheer/log-viewer')
                ->assertForbidden();
        });

        it('allows access to admin users', function () {
            $admin = User::factory()->create(['is_admin' => true]);

            $response = $this->actingAs($admin, 'admin')
                ->get('/beheer/log-viewer');

            // Should not be redirected (302) or forbidden (403)
            expect($response->status())->not->toBe(302);
            expect($response->status())->not->toBe(403);
        });
    });

    describe('Log Viewer API Access', function () {
        it('denies API access to unauthenticated users', function () {
            $this->getJson('/beheer/log-viewer/api/files')
                ->assertUnauthorized();
        });

        it('denies API access to non-admin users', function () {
            $user = User::factory()->create(['is_admin' => false]);

            $this->actingAs($user, 'admin')
                ->getJson('/beheer/log-viewer/api/files')
                ->assertForbidden();
        });

        it('allows API access to admin users', function () {
            $admin = User::factory()->create(['is_admin' => true]);

            $response = $this->actingAs($admin, 'admin')
                ->getJson('/beheer/log-viewer/api/files');

            // Should return OK (200)
            expect($response->status())->toBe(200);
        });
    });
});
