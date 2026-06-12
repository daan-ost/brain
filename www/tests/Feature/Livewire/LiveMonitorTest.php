<?php

use App\Livewire\LiveMonitor;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->user = User::factory()->create();
});

it('renders the live monitor component', function () {
    $this->actingAs($this->user);

    Livewire::test(LiveMonitor::class)
        ->assertOk()
        ->assertSee('Live Monitor')
        ->assertSee('Position')
        ->assertSee('Telemetry')
        ->assertSee('Event Log')
        ->assertSee('Camera Feed');
});

it('displays telemetry gauges', function () {
    $this->actingAs($this->user);

    Livewire::test(LiveMonitor::class)
        ->assertSee('Speed')
        ->assertSee('Altitude')
        ->assertSee('Battery')
        ->assertSee('Signal');
});

it('displays event log entries', function () {
    $this->actingAs($this->user);

    Livewire::test(LiveMonitor::class)
        ->assertSee('System initialized')
        ->assertSee('GPS lock acquired')
        ->assertSee('Wind speed above threshold');
});

it('requires authentication', function () {
    $this->get(route('live-monitor'))
        ->assertRedirect(route('login'));
});
