<?php

/**
 * Basewebsite Propagation Smoke Tests — User Dashboard & Profile
 *
 * Verifies that authenticated user pages work correctly after propagation.
 */

declare(strict_types=1);

use App\Models\User;
use Livewire\Livewire;

describe('Dashboard', function () {
    it('shows dashboard for authenticated user', function () {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get('/dashboard');
        // Some sites redirect /dashboard to a specific landing page
        expect($response->status())->toBeIn([200, 302]);
    });
});

describe('Profile Pages', function () {
    beforeEach(function () {
        $this->user = User::factory()->create();
        $this->actingAs($this->user);
    });

    it('can access profile account page', function () {
        $this->get('/profile/account')->assertStatus(200);
    });

    it('can access profile password page', function () {
        $this->get('/profile/password')->assertStatus(200);
    });

    it('can access profile api-tokens page', function () {
        $this->get('/profile/api-tokens')->assertStatus(200);
    });

    it('can update profile info', function () {
        $this->patch('/profile', [
            'name' => 'Updated Name',
            'email' => $this->user->email,
            'preferred_language' => $this->user->preferred_language ?? 'en',
        ])->assertSessionHasNoErrors();

        $this->user->refresh();
        expect($this->user->name)->toBe('Updated Name');
    });
});

describe('Localization Settings', function () {
    beforeEach(function () {
        if (! class_exists(\App\Livewire\Profile\LocalizationSettings::class)) {
            skip('LocalizationSettings component not available');
        }

        $this->user = User::factory()->create([
            'timezone' => 'Europe/Amsterdam',
            'decimal_separator' => ',',
            'date_format' => 'd-m-Y',
            'datetime_format' => 'd-m-Y H:i',
            'currency_preference' => 'EUR',
        ]);
        $this->actingAs($this->user);
    });

    it('can save locale preferences via Livewire component', function () {
        Livewire::test(\App\Livewire\Profile\LocalizationSettings::class)
            ->set('timezone', 'America/New_York')
            ->set('decimal_separator', '.')
            ->set('date_format', 'm/d/Y')
            ->set('currency_preference', 'USD')
            ->set('time_format', '12h')
            ->set('first_day_of_week', 0)
            ->call('save')
            ->assertHasNoErrors()
            ->assertDispatched('localization-saved');

        $this->user->refresh();
        expect($this->user->timezone)->toBe('America/New_York');
        expect($this->user->decimal_separator)->toBe('.');
        expect($this->user->date_format)->toBe('m/d/Y');
        expect($this->user->currency_preference)->toBe('USD');
    });

    it('rejects invalid locale values', function () {
        Livewire::test(\App\Livewire\Profile\LocalizationSettings::class)
            ->set('decimal_separator', 'X')
            ->set('time_format', '48h')
            ->call('save')
            ->assertHasErrors(['decimal_separator', 'time_format']);
    });

    it('formats numbers according to user preferences', function () {
        if (! function_exists('format_number')) {
            skip('format_number helper not available');
        }

        // EU style: comma as decimal, dot as thousands
        expect(format_number(1234.50, 2, $this->user))->toBe('1.234,50');

        // US style user
        $usUser = User::factory()->create(['decimal_separator' => '.']);
        expect(format_number(1234.50, 2, $usUser))->toBe('1,234.50');
    });

    it('formats dates according to user preferences', function () {
        if (! function_exists('format_date')) {
            skip('format_date helper not available');
        }

        $date = \Carbon\Carbon::create(2026, 7, 4, 0, 0, 0, 'UTC');

        // d-m-Y user
        expect(format_date($date, $this->user))->toBe('04-07-2026');

        // m/d/Y user
        $usUser = User::factory()->create(['date_format' => 'm/d/Y']);
        expect(format_date($date, $usUser))->toBe('07/04/2026');
    });

    it('formats currency with correct symbol', function () {
        if (! function_exists('format_currency')) {
            skip('format_currency helper not available');
        }

        $result = format_currency(29.99, 'EUR', $this->user);
        expect($result)->toContain('29');
        expect($result)->toContain('€');
    });
});

describe('Organization Management', function () {
    it('can access organization page', function () {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get('/profile/organization')
            ->assertStatus(200);
    });

    it('can create an organization', function () {
        $user = User::factory()->create([
            'email_verified_at' => now(),
        ]);

        $this->actingAs($user)
            ->post('/profile/organization', [
                'name' => 'Test Organization',
                'billing_country_code' => 'NL',
                'currency_preference' => 'EUR',
            ])->assertSessionHasNoErrors();

        $this->assertDatabaseHas('organizations', [
            'name' => 'Test Organization',
        ]);
    });
});
