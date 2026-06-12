<?php

use App\Livewire\Profile\LocalizationSettings;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create([
        'email_verified_at' => now(),
        'timezone' => 'UTC',
        'decimal_separator' => '.',
    ]);
    $this->actingAs($this->user);
});

describe('Localization Settings', function () {

    it('renders on the account page', function () {
        $response = $this->get('/profile/account');

        $response->assertOk();
        $response->assertSeeLivewire(LocalizationSettings::class);
    });

    it('loads user settings on mount', function () {
        $this->user->update([
            'timezone' => 'Europe/Amsterdam',
            'decimal_separator' => ',',
            'time_format' => '12h',
            'first_day_of_week' => 0,
        ]);

        Livewire::test(LocalizationSettings::class)
            ->assertSet('timezone', 'Europe/Amsterdam')
            ->assertSet('decimal_separator', ',')
            ->assertSet('time_format', '12h')
            ->assertSet('first_day_of_week', 0);
    });

    it('saves timezone', function () {
        Livewire::test(LocalizationSettings::class)
            ->set('timezone', 'Europe/Amsterdam')
            ->set('currency_preference', 'EUR')
            ->set('date_format', 'd/m/Y')
            ->call('save');

        $this->user->refresh();
        expect($this->user->timezone)->toBe('Europe/Amsterdam');
    });

    it('saves decimal separator to comma', function () {
        Livewire::test(LocalizationSettings::class)
            ->set('timezone', 'UTC')
            ->set('currency_preference', 'EUR')
            ->set('date_format', 'd/m/Y')
            ->set('decimal_separator', ',')
            ->call('save');

        $this->user->refresh();
        expect($this->user->decimal_separator)->toBe(',');
    });

    it('saves decimal separator to period', function () {
        Livewire::test(LocalizationSettings::class)
            ->set('timezone', 'UTC')
            ->set('currency_preference', 'EUR')
            ->set('date_format', 'd/m/Y')
            ->set('decimal_separator', '.')
            ->call('save');

        $this->user->refresh();
        expect($this->user->decimal_separator)->toBe('.');
    });

    it('validates decimal separator rejects invalid values', function () {
        Livewire::test(LocalizationSettings::class)
            ->set('decimal_separator', 'invalid')
            ->set('timezone', 'UTC')
            ->set('currency_preference', 'EUR')
            ->set('date_format', 'd/m/Y')
            ->call('save')
            ->assertHasErrors('decimal_separator');
    });

    it('validates timezone is required', function () {
        Livewire::test(LocalizationSettings::class)
            ->set('timezone', '')
            ->set('currency_preference', 'EUR')
            ->set('date_format', 'd/m/Y')
            ->call('save')
            ->assertHasErrors('timezone');
    });

    it('validates currency preference is required', function () {
        Livewire::test(LocalizationSettings::class)
            ->set('timezone', 'UTC')
            ->set('currency_preference', '')
            ->set('date_format', 'd/m/Y')
            ->call('save')
            ->assertHasErrors('currency_preference');
    });

    it('validates date format is required', function () {
        Livewire::test(LocalizationSettings::class)
            ->set('timezone', 'UTC')
            ->set('currency_preference', 'EUR')
            ->set('date_format', '')
            ->call('save')
            ->assertHasErrors('date_format');
    });

    it('validates time format must be 12h or 24h', function () {
        Livewire::test(LocalizationSettings::class)
            ->set('timezone', 'UTC')
            ->set('currency_preference', 'EUR')
            ->set('date_format', 'd/m/Y')
            ->set('time_format', 'invalid')
            ->call('save')
            ->assertHasErrors('time_format');
    });

    it('validates first day of week must be 0 or 1', function () {
        Livewire::test(LocalizationSettings::class)
            ->set('timezone', 'UTC')
            ->set('currency_preference', 'EUR')
            ->set('date_format', 'd/m/Y')
            ->set('first_day_of_week', 5)
            ->call('save')
            ->assertHasErrors('first_day_of_week');
    });

    it('saves all settings at once', function () {
        Livewire::test(LocalizationSettings::class)
            ->set('timezone', 'America/New_York')
            ->set('currency_preference', 'USD')
            ->set('date_format', 'm/d/Y')
            ->set('time_format', '12h')
            ->set('decimal_separator', '.')
            ->set('first_day_of_week', 0)
            ->call('save');

        $this->user->refresh();
        expect($this->user->timezone)->toBe('America/New_York');
        expect($this->user->currency_preference)->toBe('USD');
        expect($this->user->date_format)->toBe('m/d/Y');
        expect($this->user->time_format)->toBe('12h');
        expect($this->user->decimal_separator)->toBe('.');
        expect($this->user->first_day_of_week)->toBe(0);
        expect($this->user->locale_manually_set)->toBeTrue();
    });

    it('sets locale_manually_set flag on save', function () {
        expect($this->user->locale_manually_set)->toBeFalsy();

        Livewire::test(LocalizationSettings::class)
            ->set('timezone', 'UTC')
            ->set('currency_preference', 'EUR')
            ->set('date_format', 'd/m/Y')
            ->call('save');

        $this->user->refresh();
        expect($this->user->locale_manually_set)->toBeTrue();
    });

    it('dispatches localization-saved event on save', function () {
        Livewire::test(LocalizationSettings::class)
            ->set('timezone', 'UTC')
            ->set('currency_preference', 'EUR')
            ->set('date_format', 'd/m/Y')
            ->call('save')
            ->assertDispatched('localization-saved');
    });

    it('requires authentication', function () {
        auth()->logout();

        $response = $this->get('/profile/account');
        $response->assertRedirect('/login');
    });

});

describe('detectFromBrowser', function () {

    it('detects and persists settings from browser when not manually set', function () {
        $user = User::factory()->create([
            'email_verified_at' => now(),
            'locale_manually_set' => false,
            'timezone' => null,
        ]);
        $this->actingAs($user);

        Livewire::test(LocalizationSettings::class)
            ->call('detectFromBrowser', 'Europe/Amsterdam', 'nl-NL');

        $user->refresh();
        expect($user->timezone)->toBe('Europe/Amsterdam');
        expect($user->locale_manually_set)->toBeFalse();
    });

    it('does not overwrite manually set settings', function () {
        $user = User::factory()->create([
            'email_verified_at' => now(),
            'locale_manually_set' => true,
            'timezone' => 'UTC',
        ]);
        $this->actingAs($user);

        Livewire::test(LocalizationSettings::class)
            ->call('detectFromBrowser', 'America/New_York', 'en-US');

        $user->refresh();
        expect($user->timezone)->toBe('UTC');
    });

    it('ignores oversized browserTimezone input', function () {
        $user = User::factory()->create([
            'email_verified_at' => now(),
            'locale_manually_set' => false,
            'timezone' => 'UTC',
        ]);
        $this->actingAs($user);

        $oversized = str_repeat('A', 101);

        Livewire::test(LocalizationSettings::class)
            ->call('detectFromBrowser', $oversized, 'nl-NL');

        // Timezone should remain unchanged
        $user->refresh();
        expect($user->timezone)->toBe('UTC');
    });

    it('ignores oversized browserLocale input', function () {
        $user = User::factory()->create([
            'email_verified_at' => now(),
            'locale_manually_set' => false,
            'timezone' => 'UTC',
        ]);
        $this->actingAs($user);

        $oversized = str_repeat('A', 21);

        Livewire::test(LocalizationSettings::class)
            ->call('detectFromBrowser', 'Europe/Amsterdam', $oversized);

        // Timezone should remain unchanged
        $user->refresh();
        expect($user->timezone)->toBe('UTC');
    });

});
