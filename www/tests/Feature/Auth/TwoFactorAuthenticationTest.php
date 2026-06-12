<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Services\TwoFactorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use App\Notifications\TwoFactorEnabledNotification;
use App\Notifications\TwoFactorDisabledNotification;
use App\Notifications\TwoFactorRecoveryCodeUsedNotification;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

class TwoFactorAuthenticationTest extends TestCase
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

    public function test_user_can_enable_two_factor_authentication(): void
    {
        Notification::fake();

        $user = User::factory()->create();

        // Step 1: Generate secret
        $secret = $this->twoFactorService->enableTwoFactor($user);

        $this->assertNotNull($secret);
        $this->assertTrue($user->fresh()->hasTwoFactorPending());
        $this->assertFalse($user->fresh()->hasTwoFactorEnabled());

        // Step 2: Confirm with valid code
        $validCode = $this->google2fa->getCurrentOtp($secret);
        $recoveryCodes = $this->twoFactorService->confirmTwoFactor($user, $validCode);

        $this->assertIsArray($recoveryCodes);
        $this->assertCount(8, $recoveryCodes);
        $this->assertTrue($user->fresh()->hasTwoFactorEnabled());
        $this->assertFalse($user->fresh()->hasTwoFactorPending());

        Notification::assertSentTo($user, TwoFactorEnabledNotification::class);
    }

    public function test_two_factor_confirmation_fails_with_invalid_code(): void
    {
        $user = User::factory()->create();

        $this->twoFactorService->enableTwoFactor($user);

        $result = $this->twoFactorService->confirmTwoFactor($user, '000000');

        $this->assertFalse($result);
        $this->assertFalse($user->fresh()->hasTwoFactorEnabled());
    }

    public function test_user_can_disable_two_factor_authentication(): void
    {
        Notification::fake();

        $user = User::factory()->create();

        // Enable 2FA first
        $secret = $this->twoFactorService->enableTwoFactor($user);
        $validCode = $this->google2fa->getCurrentOtp($secret);
        $this->twoFactorService->confirmTwoFactor($user, $validCode);

        $this->assertTrue($user->fresh()->hasTwoFactorEnabled());

        // Disable 2FA
        $this->twoFactorService->disableTwoFactor($user);

        $this->assertFalse($user->fresh()->hasTwoFactorEnabled());
        $this->assertNull($user->fresh()->two_factor_secret);

        Notification::assertSentTo($user, TwoFactorDisabledNotification::class);
    }

    public function test_recovery_codes_are_unique_and_unambiguous(): void
    {
        $user = User::factory()->create();

        $codes = $user->generateRecoveryCodes();

        $this->assertCount(8, $codes);
        $this->assertCount(8, array_unique($codes)); // All unique

        foreach ($codes as $code) {
            // Format: XXXX-XXXX
            $this->assertMatchesRegularExpression('/^[A-Z0-9]{4}-[A-Z0-9]{4}$/', $code);
            // No ambiguous characters
            $this->assertStringNotContainsString('0', $code);
            $this->assertStringNotContainsString('O', $code);
            $this->assertStringNotContainsString('1', $code);
            $this->assertStringNotContainsString('I', $code);
            $this->assertStringNotContainsString('L', $code);
        }
    }

    public function test_user_can_use_recovery_code(): void
    {
        $user = User::factory()->create();

        // Enable 2FA
        $secret = $this->twoFactorService->enableTwoFactor($user);
        $validCode = $this->google2fa->getCurrentOtp($secret);
        $recoveryCodes = $this->twoFactorService->confirmTwoFactor($user, $validCode);

        $firstCode = $recoveryCodes[0];

        // Use recovery code
        $result = $this->twoFactorService->verifyRecoveryCode($user, $firstCode);

        $this->assertTrue($result);
        $this->assertCount(7, $user->fresh()->getTwoFactorRecoveryCodes());
    }

    public function test_recovery_code_can_only_be_used_once(): void
    {
        $user = User::factory()->create();

        // Enable 2FA
        $secret = $this->twoFactorService->enableTwoFactor($user);
        $validCode = $this->google2fa->getCurrentOtp($secret);
        $recoveryCodes = $this->twoFactorService->confirmTwoFactor($user, $validCode);

        $firstCode = $recoveryCodes[0];

        // Use recovery code first time
        $result1 = $this->twoFactorService->verifyRecoveryCode($user, $firstCode);
        $this->assertTrue($result1);

        // Try to use same code again
        $result2 = $this->twoFactorService->verifyRecoveryCode($user, $firstCode);
        $this->assertFalse($result2);
    }

    public function test_user_can_regenerate_recovery_codes(): void
    {
        $user = User::factory()->create();

        // Enable 2FA
        $secret = $this->twoFactorService->enableTwoFactor($user);
        $validCode = $this->google2fa->getCurrentOtp($secret);
        $oldCodes = $this->twoFactorService->confirmTwoFactor($user, $validCode);

        // Regenerate codes
        $newCodes = $this->twoFactorService->regenerateRecoveryCodes($user);

        $this->assertCount(8, $newCodes);
        $this->assertNotEquals($oldCodes, $newCodes);

        // Old codes should no longer work
        $result = $this->twoFactorService->verifyRecoveryCode($user, $oldCodes[0]);
        $this->assertFalse($result);
    }

    public function test_two_factor_challenge_page_requires_authentication(): void
    {
        $response = $this->get('/two-factor-challenge');

        $response->assertRedirect('/login');
    }

    public function test_two_factor_challenge_redirects_if_not_enabled(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get('/two-factor-challenge');

        $response->assertRedirect(route('dashboard'));
    }

    public function test_two_factor_challenge_shows_for_enabled_users(): void
    {
        $user = User::factory()->create();

        // Enable 2FA
        $secret = $this->twoFactorService->enableTwoFactor($user);
        $validCode = $this->google2fa->getCurrentOtp($secret);
        $this->twoFactorService->confirmTwoFactor($user, $validCode);

        $response = $this->actingAs($user)->get('/two-factor-challenge');

        $response->assertStatus(200);
    }

    public function test_two_factor_challenge_validates_code(): void
    {
        $user = User::factory()->create();

        // Enable 2FA
        $secret = $this->twoFactorService->enableTwoFactor($user);
        $validCode = $this->google2fa->getCurrentOtp($secret);
        $this->twoFactorService->confirmTwoFactor($user, $validCode);

        // Reset replay-protection timestamp so the next OTP is accepted
        $user->forceFill(['two_factor_code_timestamp' => null])->save();

        // Submit valid code — session has no two_factor_intended_url, falls back to dashboard
        $currentCode = $this->google2fa->getCurrentOtp($user->fresh()->two_factor_secret);

        $response = $this->actingAs($user)->post('/two-factor-challenge', [
            'code' => $currentCode,
        ]);

        $response->assertRedirect(route('dashboard'));
        $this->assertTrue(session('two_factor_verified_web'));
    }

    public function test_two_factor_challenge_rejects_invalid_code(): void
    {
        $user = User::factory()->create();

        // Enable 2FA
        $secret = $this->twoFactorService->enableTwoFactor($user);
        $validCode = $this->google2fa->getCurrentOtp($secret);
        $this->twoFactorService->confirmTwoFactor($user, $validCode);

        $response = $this->actingAs($user)->post('/two-factor-challenge', [
            'code' => '000000',
        ]);

        $response->assertSessionHasErrors('code');
        $this->assertFalse(session('two_factor_verified_web', false));
    }

    public function test_two_factor_challenge_accepts_recovery_code(): void
    {
        Notification::fake();

        $user = User::factory()->create();

        // Enable 2FA
        $secret = $this->twoFactorService->enableTwoFactor($user);
        $validCode = $this->google2fa->getCurrentOtp($secret);
        $recoveryCodes = $this->twoFactorService->confirmTwoFactor($user, $validCode);

        $response = $this->actingAs($user)->post('/two-factor-challenge', [
            'recovery_code' => $recoveryCodes[0],
        ]);

        $response->assertRedirect(route('dashboard'));
        $this->assertTrue(session('two_factor_verified_web'));

        Notification::assertSentTo($user, TwoFactorRecoveryCodeUsedNotification::class);
    }

    public function test_two_factor_challenge_requires_at_least_one_code(): void
    {
        $user = User::factory()->create();

        // Enable 2FA
        $secret = $this->twoFactorService->enableTwoFactor($user);
        $validCode = $this->google2fa->getCurrentOtp($secret);
        $this->twoFactorService->confirmTwoFactor($user, $validCode);

        $response = $this->actingAs($user)->post('/two-factor-challenge', [
            'code' => '',
            'recovery_code' => '',
        ]);

        $response->assertSessionHasErrors('code');
    }

    public function test_remember_token_can_be_created_and_validated(): void
    {
        $user = User::factory()->create();

        // Enable 2FA
        $secret = $this->twoFactorService->enableTwoFactor($user);
        $validCode = $this->google2fa->getCurrentOtp($secret);
        $this->twoFactorService->confirmTwoFactor($user, $validCode);

        // Create remember token
        $token = $user->createTwoFactorRememberToken('web', 'Mozilla/5.0', '127.0.0.1');

        $this->assertNotEmpty($token);
        $this->assertTrue($user->hasValidTwoFactorRememberToken($token, 'web'));

        // Token for different guard should not work
        $this->assertFalse($user->hasValidTwoFactorRememberToken($token, 'admin'));
    }

    public function test_verify_code_method_works_correctly(): void
    {
        $user = User::factory()->create();

        // Enable 2FA
        $secret = $this->twoFactorService->enableTwoFactor($user);
        $validCode = $this->google2fa->getCurrentOtp($secret);
        $this->twoFactorService->confirmTwoFactor($user, $validCode);

        // Reset replay-protection timestamp: confirmTwoFactor consumed the OTP window,
        // so the same 30s code would be rejected. Clearing allows the next check through.
        $user->forceFill(['two_factor_code_timestamp' => null])->save();

        $currentCode = $this->google2fa->getCurrentOtp($user->fresh()->two_factor_secret);

        $this->assertTrue($this->twoFactorService->verifyLoginCode($user->fresh(), $currentCode));
        $this->assertFalse($this->twoFactorService->verifyLoginCode($user->fresh(), '000000'));
    }

    public function test_verify_login_code_returns_true_if_2fa_not_enabled(): void
    {
        $user = User::factory()->create();

        // 2FA not enabled - should always return true
        $this->assertTrue($this->twoFactorService->verifyLoginCode($user, '000000'));
    }

    public function test_qr_code_can_be_generated(): void
    {
        $user = User::factory()->create();
        $secret = $this->twoFactorService->generateSecretKey();

        $svg = $this->twoFactorService->generateQrCodeSvg($user, $secret);

        $this->assertStringContainsString('<svg', $svg);
        $this->assertStringContainsString('</svg>', $svg);
    }
}
