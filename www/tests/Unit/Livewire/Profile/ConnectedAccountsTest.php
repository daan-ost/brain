<?php

namespace Tests\Unit\Livewire\Profile;

use App\Livewire\Profile\ConnectedAccounts;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ConnectedAccountsTest extends TestCase
{
    use RefreshDatabase;

    public function test_disconnect_blocked_when_user_has_no_password(): void
    {
        $user = User::factory()->create([
            'password'             => null,
            'google_id'            => 'sub-1',
            'google_token'         => 'token',
            'google_refresh_token' => 'refresh',
        ]);

        Livewire::actingAs($user)
            ->test(ConnectedAccounts::class)
            ->call('disconnectGoogle')
            ->assertHasErrors('google');

        $fresh = $user->fresh();
        $this->assertSame('sub-1', $fresh->google_id, 'Google MUST stay linked when no password is set');
    }

    public function test_disconnect_succeeds_when_user_has_password(): void
    {
        $user = User::factory()->create([
            'password'             => bcrypt('s3cret-pass'),
            'google_id'            => 'sub-2',
            'google_token'         => 'token',
            'google_refresh_token' => 'refresh',
        ]);

        Livewire::actingAs($user)
            ->test(ConnectedAccounts::class)
            ->call('disconnectGoogle')
            ->assertHasNoErrors()
            ->assertDispatched('google-disconnected');

        $fresh = $user->fresh();
        $this->assertNull($fresh->google_id);
        $this->assertNull($fresh->google_token);
        $this->assertNull($fresh->google_refresh_token);
    }

    public function test_disconnect_cycles_remember_token(): void
    {
        $user = User::factory()->create([
            'password'             => bcrypt('s3cret-pass'),
            'google_id'            => 'sub-3',
            'remember_token'       => str_repeat('A', 60),
        ]);
        $oldToken = $user->remember_token;

        Livewire::actingAs($user)
            ->test(ConnectedAccounts::class)
            ->call('disconnectGoogle');

        $newToken = $user->fresh()->remember_token;
        $this->assertNotSame($oldToken, $newToken, 'M4: remember_token MUST be cycled on disconnect');
        $this->assertSame(60, strlen($newToken), 'New remember_token should be 60 random chars');
    }

    public function test_disconnect_noop_when_no_google_linked(): void
    {
        $user = User::factory()->create([
            'password'  => bcrypt('pwd'),
            'google_id' => null,
        ]);

        Livewire::actingAs($user)
            ->test(ConnectedAccounts::class)
            ->call('disconnectGoogle')
            ->assertHasNoErrors();
    }

    public function test_view_data_includes_googleEnabled_flag(): void
    {
        config([
            'services.google.client_id'     => 'test-id',
            'services.google.client_secret' => 'test-secret',
        ]);

        $user = User::factory()->create();

        Livewire::actingAs($user)
            ->test(ConnectedAccounts::class)
            ->assertViewHas('googleEnabled', true)
            ->assertViewHas('user');
    }

    public function test_view_data_googleEnabled_false_when_not_configured(): void
    {
        config([
            'services.google.client_id'     => null,
            'services.google.client_secret' => null,
        ]);

        $user = User::factory()->create();

        Livewire::actingAs($user)
            ->test(ConnectedAccounts::class)
            ->assertViewHas('googleEnabled', false);
    }
}
