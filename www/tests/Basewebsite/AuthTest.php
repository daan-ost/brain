<?php

/**
 * Basewebsite Propagation Smoke Tests — Authentication
 *
 * Verifies that auth flows work correctly after propagation.
 */

declare(strict_types=1);

use App\Models\User;

describe('Registration', function () {
    it('can register a new user', function () {
        $this->post('/register', [
            'name' => 'Test User',
            'email' => 'propagation-test@gmail.com',
            'password' => 'Password123!Secure',
            'password_confirmation' => 'Password123!Secure',
            'terms' => true,
        ])->assertRedirect();

        $this->assertDatabaseHas('users', [
            'email' => 'propagation-test@gmail.com',
        ]);
    });

    it('rejects duplicate email registration', function () {
        User::factory()->create(['email' => 'existing@example.com']);

        $this->post('/register', [
            'name' => 'Duplicate',
            'email' => 'existing@example.com',
            'password' => 'Password123!Secure',
            'password_confirmation' => 'Password123!Secure',
            'terms' => true,
        ])->assertSessionHasErrors('email');
    });
});

describe('Login', function () {
    it('can login with valid credentials', function () {
        $user = User::factory()->create([
            'password' => bcrypt('password'),
        ]);

        $this->post('/login', [
            'email' => $user->email,
            'password' => 'password',
        ])->assertRedirect();

        $this->assertAuthenticatedAs($user);
    });

    it('rejects invalid credentials', function () {
        $user = User::factory()->create([
            'password' => bcrypt('password'),
        ]);

        $this->post('/login', [
            'email' => $user->email,
            'password' => 'wrong-password',
        ]);

        $this->assertGuest();
    });
});

describe('Logout', function () {
    it('can logout', function () {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post('/logout')
            ->assertRedirect();

        $this->assertGuest();
    });
});

describe('Password Reset', function () {
    it('can request password reset link', function () {
        $user = User::factory()->create();

        $this->post('/forgot-password', [
            'email' => $user->email,
        ])->assertSessionHasNoErrors();
    });
});
