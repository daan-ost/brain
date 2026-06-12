<?php

namespace Tests\Feature\Livewire;

use App\Livewire\Profile\TwoFactorManager;
use App\Models\User;
use App\Services\TwoFactorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

class TwoFactorManagerTest extends TestCase
{
    use RefreshDatabase;

    protected TwoFactorService $twoFactorService;
    protected Google2FA $google2fa;

    protected function setUp(): void
    {
        parent::setUp();
        $this->twoFactorService = app(TwoFactorService::class);
        $this->google2fa = new Google2FA();
    }

    public function test_component_can_render(): void
    {
        $user = User::factory()->create();

        Livewire::actingAs($user)
            ->test(TwoFactorManager::class)
            ->assertStatus(200)
            ->assertSee('Two-Factor Authentication');
    }

    public function test_user_can_enable_two_factor(): void
    {
        $user = User::factory()->create();

        $component = Livewire::actingAs($user)
            ->test(TwoFactorManager::class)
            ->assertSet('showingQrCode', false)
            ->call('enableTwoFactor')
            ->assertSet('showingQrCode', true);

        // qrCodeSvg is a public property, secretKey is exposed via render() view data
        $this->assertNotEmpty($component->get('qrCodeSvg'));
        $this->assertTrue($user->fresh()->hasTwoFactorPending());
        // Verify secret was stored on the user
        $this->assertNotEmpty($user->fresh()->two_factor_secret);
    }

    public function test_user_can_confirm_two_factor_with_valid_code(): void
    {
        $user = User::factory()->create();

        // Start the setup
        $secret = $this->twoFactorService->enableTwoFactor($user);
        $validCode = $this->google2fa->getCurrentOtp($secret);

        Livewire::actingAs($user->fresh())
            ->test(TwoFactorManager::class)
            ->set('code', $validCode)
            ->call('confirmTwoFactor')
            ->assertSet('showingQrCode', false)
            ->assertSet('showingRecoveryCodes', true)
            ->assertDispatched('two-factor-enabled');

        $this->assertTrue($user->fresh()->hasTwoFactorEnabled());
    }

    public function test_confirm_fails_with_invalid_code(): void
    {
        $user = User::factory()->create();

        $this->twoFactorService->enableTwoFactor($user);

        Livewire::actingAs($user->fresh())
            ->test(TwoFactorManager::class)
            ->set('code', '000000')
            ->call('confirmTwoFactor')
            ->assertHasErrors('code')
            ->assertSet('showingQrCode', true);

        $this->assertFalse($user->fresh()->hasTwoFactorEnabled());
    }

    public function test_user_can_cancel_setup(): void
    {
        $user = User::factory()->create();

        $this->twoFactorService->enableTwoFactor($user);
        $this->assertTrue($user->fresh()->hasTwoFactorPending());

        Livewire::actingAs($user->fresh())
            ->test(TwoFactorManager::class)
            ->call('cancelSetup')
            ->assertSet('showingQrCode', false)
            ->assertSet('secretKey', null);

        $this->assertFalse($user->fresh()->hasTwoFactorPending());
    }

    public function test_user_can_disable_two_factor_with_password(): void
    {
        $user = User::factory()->create(['password' => bcrypt('password')]);

        // Enable 2FA first
        $secret = $this->twoFactorService->enableTwoFactor($user);
        $validCode = $this->google2fa->getCurrentOtp($secret);
        $this->twoFactorService->confirmTwoFactor($user, $validCode);

        Livewire::actingAs($user->fresh())
            ->test(TwoFactorManager::class)
            ->call('confirmDisableTwoFactor')
            ->assertSet('confirmingDisable', true)
            ->set('password', 'password')
            ->call('disableTwoFactor')
            ->assertSet('confirmingDisable', false)
            ->assertDispatched('two-factor-disabled');

        $this->assertFalse($user->fresh()->hasTwoFactorEnabled());
    }

    public function test_disable_fails_with_wrong_password(): void
    {
        $user = User::factory()->create(['password' => bcrypt('password')]);

        // Enable 2FA first
        $secret = $this->twoFactorService->enableTwoFactor($user);
        $validCode = $this->google2fa->getCurrentOtp($secret);
        $this->twoFactorService->confirmTwoFactor($user, $validCode);

        Livewire::actingAs($user->fresh())
            ->test(TwoFactorManager::class)
            ->call('confirmDisableTwoFactor')
            ->set('password', 'wrong-password')
            ->call('disableTwoFactor')
            ->assertHasErrors('password')
            ->assertSet('confirmingDisable', true);

        $this->assertTrue($user->fresh()->hasTwoFactorEnabled());
    }

    public function test_user_can_view_recovery_codes(): void
    {
        $user = User::factory()->create(['password' => bcrypt('password')]);

        // Enable 2FA
        $secret = $this->twoFactorService->enableTwoFactor($user);
        $validCode = $this->google2fa->getCurrentOtp($secret);
        $recoveryCodes = $this->twoFactorService->confirmTwoFactor($user, $validCode);

        // showRecoveryCodes requires password confirmation
        Livewire::actingAs($user->fresh())
            ->test(TwoFactorManager::class)
            ->call('confirmShowRecoveryCodes')
            ->assertSet('confirmingShowCodes', true)
            ->set('password', 'password')
            ->call('showRecoveryCodes')
            ->assertSet('showingRecoveryCodes', true)
            ->assertSet('recoveryCodes', $recoveryCodes);
    }

    public function test_user_can_hide_recovery_codes(): void
    {
        $user = User::factory()->create(['password' => bcrypt('password')]);

        // Enable 2FA
        $secret = $this->twoFactorService->enableTwoFactor($user);
        $validCode = $this->google2fa->getCurrentOtp($secret);
        $this->twoFactorService->confirmTwoFactor($user, $validCode);

        // showRecoveryCodes requires password confirmation
        Livewire::actingAs($user->fresh())
            ->test(TwoFactorManager::class)
            ->call('confirmShowRecoveryCodes')
            ->set('password', 'password')
            ->call('showRecoveryCodes')
            ->assertSet('showingRecoveryCodes', true)
            ->call('hideRecoveryCodes')
            ->assertSet('showingRecoveryCodes', false)
            ->assertSet('recoveryCodes', []);
    }

    public function test_user_can_regenerate_recovery_codes(): void
    {
        $user = User::factory()->create(['password' => bcrypt('password')]);

        // Enable 2FA
        $secret = $this->twoFactorService->enableTwoFactor($user);
        $setupCode = $this->google2fa->getCurrentOtp($secret);
        $oldCodes = $this->twoFactorService->confirmTwoFactor($user, $setupCode);

        // Reset replay-protection timestamp so the regeneration TOTP check passes
        $user->forceFill(['two_factor_code_timestamp' => null])->save();

        // Get a fresh TOTP code for regeneration
        $user->refresh();
        $currentCode = $this->google2fa->getCurrentOtp($user->two_factor_secret);

        Livewire::actingAs($user)
            ->test(TwoFactorManager::class)
            ->call('confirmRegenerateRecoveryCodes')
            ->assertSet('confirmingRegenerateCodes', true)
            ->set('password', 'password')
            ->set('code', $currentCode)
            ->call('regenerateRecoveryCodes')
            ->assertSet('showingRecoveryCodes', true)
            ->assertSet('confirmingRegenerateCodes', false)
            ->assertDispatched('recovery-codes-regenerated');

        $newCodes = $user->fresh()->getTwoFactorRecoveryCodes()->toArray();
        $this->assertNotEquals($oldCodes, $newCodes);
    }

    public function test_regenerate_fails_with_wrong_password(): void
    {
        $user = User::factory()->create(['password' => bcrypt('password')]);

        // Enable 2FA
        $secret = $this->twoFactorService->enableTwoFactor($user);
        $validCode = $this->google2fa->getCurrentOtp($secret);
        $this->twoFactorService->confirmTwoFactor($user, $validCode);

        $user->refresh();
        $currentCode = $this->google2fa->getCurrentOtp($user->two_factor_secret);

        Livewire::actingAs($user)
            ->test(TwoFactorManager::class)
            ->call('confirmRegenerateRecoveryCodes')
            ->set('password', 'wrong-password')
            ->set('code', $currentCode)
            ->call('regenerateRecoveryCodes')
            ->assertHasErrors('password');
    }

    public function test_regenerate_fails_with_invalid_code(): void
    {
        $user = User::factory()->create(['password' => bcrypt('password')]);

        // Enable 2FA
        $secret = $this->twoFactorService->enableTwoFactor($user);
        $validCode = $this->google2fa->getCurrentOtp($secret);
        $this->twoFactorService->confirmTwoFactor($user, $validCode);

        Livewire::actingAs($user->fresh())
            ->test(TwoFactorManager::class)
            ->call('confirmRegenerateRecoveryCodes')
            ->set('password', 'password')
            ->set('code', '000000')
            ->call('regenerateRecoveryCodes')
            ->assertHasErrors('code');
    }

    public function test_component_shows_pending_state(): void
    {
        $user = User::factory()->create();

        $this->twoFactorService->enableTwoFactor($user);

        $component = Livewire::actingAs($user->fresh())
            ->test(TwoFactorManager::class)
            ->assertSet('showingQrCode', true);

        // qrCodeSvg is a public property set in mount(); secretKey lives in render() view data
        $this->assertNotEmpty($component->get('qrCodeSvg'));
        $this->assertNotEmpty($user->fresh()->two_factor_secret);
    }

    public function test_component_shows_enabled_state(): void
    {
        $user = User::factory()->create();

        // Enable 2FA
        $secret = $this->twoFactorService->enableTwoFactor($user);
        $validCode = $this->google2fa->getCurrentOtp($secret);
        $this->twoFactorService->confirmTwoFactor($user, $validCode);

        Livewire::actingAs($user->fresh())
            ->test(TwoFactorManager::class)
            ->assertSee('Two-factor authentication is enabled');
    }

    public function test_cancel_disable_resets_state(): void
    {
        $user = User::factory()->create();

        // Enable 2FA
        $secret = $this->twoFactorService->enableTwoFactor($user);
        $validCode = $this->google2fa->getCurrentOtp($secret);
        $this->twoFactorService->confirmTwoFactor($user, $validCode);

        Livewire::actingAs($user->fresh())
            ->test(TwoFactorManager::class)
            ->call('confirmDisableTwoFactor')
            ->assertSet('confirmingDisable', true)
            ->set('password', 'some-password')
            ->call('cancelDisable')
            ->assertSet('confirmingDisable', false)
            ->assertSet('password', '');
    }

    public function test_cancel_regenerate_resets_state(): void
    {
        $user = User::factory()->create();

        // Enable 2FA
        $secret = $this->twoFactorService->enableTwoFactor($user);
        $validCode = $this->google2fa->getCurrentOtp($secret);
        $this->twoFactorService->confirmTwoFactor($user, $validCode);

        Livewire::actingAs($user->fresh())
            ->test(TwoFactorManager::class)
            ->call('confirmRegenerateRecoveryCodes')
            ->assertSet('confirmingRegenerateCodes', true)
            ->set('password', 'some-password')
            ->set('code', '123456')
            ->call('cancelRegenerate')
            ->assertSet('confirmingRegenerateCodes', false)
            ->assertSet('password', '')
            ->assertSet('code', '');
    }
}
