<?php

namespace Tests\Unit\Services;

use App\Models\User;
use App\Services\TwoFactorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

class TwoFactorServiceTest extends TestCase
{
    use RefreshDatabase;

    protected TwoFactorService $service;
    protected Google2FA $google2fa;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new TwoFactorService();
        $this->google2fa = new Google2FA();
    }

    public function test_generate_secret_key_returns_valid_base32_string(): void
    {
        $secret = $this->service->generateSecretKey();

        $this->assertNotEmpty($secret);
        $this->assertEquals(16, strlen($secret));
        // Base32 characters only
        $this->assertMatchesRegularExpression('/^[A-Z2-7]+$/', $secret);
    }

    public function test_get_qr_code_url_contains_required_parameters(): void
    {
        $user = User::factory()->create(['email' => 'test@example.com']);
        $secret = $this->service->generateSecretKey();

        $url = $this->service->getQrCodeUrl($user, $secret);

        $this->assertStringContainsString('otpauth://totp/', $url);
        $this->assertStringContainsString(urlencode($user->email), $url);
        $this->assertStringContainsString('secret=' . $secret, $url);
    }

    public function test_generate_qr_code_svg_returns_valid_svg(): void
    {
        $user = User::factory()->create();
        $secret = $this->service->generateSecretKey();

        $svg = $this->service->generateQrCodeSvg($user, $secret);

        $this->assertStringStartsWith('<?xml', $svg);
        $this->assertStringContainsString('<svg', $svg);
    }

    public function test_verify_code_validates_totp(): void
    {
        $secret = $this->service->generateSecretKey();
        $validCode = $this->google2fa->getCurrentOtp($secret);

        $this->assertTrue($this->service->verifyCode($secret, $validCode));
        $this->assertFalse($this->service->verifyCode($secret, '000000'));
    }

    public function test_enable_two_factor_sets_secret_and_clears_confirmation(): void
    {
        $user = User::factory()->create();

        $secret = $this->service->enableTwoFactor($user);
        $user->refresh();

        $this->assertEquals($secret, $user->two_factor_secret);
        $this->assertNull($user->two_factor_confirmed_at);
        $this->assertNull($user->two_factor_recovery_codes);
    }

    public function test_confirm_two_factor_sets_confirmation_and_generates_codes(): void
    {
        $user = User::factory()->create();

        $secret = $this->service->enableTwoFactor($user);
        $validCode = $this->google2fa->getCurrentOtp($secret);

        $recoveryCodes = $this->service->confirmTwoFactor($user, $validCode);
        $user->refresh();

        $this->assertIsArray($recoveryCodes);
        $this->assertCount(8, $recoveryCodes);
        $this->assertNotNull($user->two_factor_confirmed_at);
        $this->assertCount(8, $user->two_factor_recovery_codes);
    }

    public function test_confirm_two_factor_fails_without_pending_setup(): void
    {
        $user = User::factory()->create();

        $result = $this->service->confirmTwoFactor($user, '123456');

        $this->assertFalse($result);
    }

    public function test_confirm_two_factor_fails_with_invalid_code(): void
    {
        $user = User::factory()->create();

        $this->service->enableTwoFactor($user);

        $result = $this->service->confirmTwoFactor($user, '000000');

        $this->assertFalse($result);
        $this->assertNull($user->fresh()->two_factor_confirmed_at);
    }

    public function test_disable_two_factor_clears_all_data(): void
    {
        $user = User::factory()->create();

        // First enable 2FA
        $secret = $this->service->enableTwoFactor($user);
        $validCode = $this->google2fa->getCurrentOtp($secret);
        $this->service->confirmTwoFactor($user, $validCode);

        // Create a remember token
        $user->createTwoFactorRememberToken('web');
        $this->assertCount(1, $user->twoFactorRememberTokens);

        // Disable 2FA
        $this->service->disableTwoFactor($user, sendNotification: false);
        $user->refresh();

        $this->assertNull($user->two_factor_secret);
        $this->assertNull($user->two_factor_confirmed_at);
        $this->assertNull($user->two_factor_recovery_codes);
        $this->assertCount(0, $user->twoFactorRememberTokens);
    }

    public function test_verify_login_code_allows_access_when_2fa_not_enabled(): void
    {
        $user = User::factory()->create();

        // Any code should work when 2FA is not enabled
        $this->assertTrue($this->service->verifyLoginCode($user, 'anything'));
    }

    public function test_verify_login_code_validates_when_2fa_enabled(): void
    {
        // Bootstrap a fully-confirmed 2FA user without consuming a TOTP code,
        // so that the very first verifyLoginCode call uses a fresh code.
        $secret = $this->service->generateSecretKey();
        $user = User::factory()->create([
            'two_factor_secret'        => $secret,
            'two_factor_confirmed_at'  => now(),
            'two_factor_code_timestamp' => null,
        ]);

        // Test with valid code — no code has been consumed yet, so no replay issue
        $validCode = $this->google2fa->getCurrentOtp($secret);
        $this->assertTrue($this->service->verifyLoginCode($user, $validCode));

        // Test with invalid code
        $user->refresh();
        $this->assertFalse($this->service->verifyLoginCode($user, '000000'));
    }

    public function test_verify_recovery_code_normalizes_input(): void
    {
        $user = User::factory()->create();

        // Enable 2FA
        $secret = $this->service->enableTwoFactor($user);
        $validCode = $this->google2fa->getCurrentOtp($secret);
        $recoveryCodes = $this->service->confirmTwoFactor($user, $validCode);

        // Test with lowercase (should be normalized to uppercase)
        $lowercaseCode = strtolower($recoveryCodes[0]);
        $this->assertTrue($this->service->verifyRecoveryCode($user, $lowercaseCode));

        // Test with spaces (should be trimmed)
        $codeWithSpaces = '  ' . $recoveryCodes[1] . '  ';
        $this->assertTrue($this->service->verifyRecoveryCode($user, $codeWithSpaces));
    }

    public function test_regenerate_recovery_codes_returns_new_codes(): void
    {
        $user = User::factory()->create();

        // Enable 2FA
        $secret = $this->service->enableTwoFactor($user);
        $validCode = $this->google2fa->getCurrentOtp($secret);
        $oldCodes = $this->service->confirmTwoFactor($user, $validCode);

        // Regenerate
        $newCodes = $this->service->regenerateRecoveryCodes($user);

        $this->assertCount(8, $newCodes);
        $this->assertNotEquals($oldCodes, $newCodes);
    }

    public function test_cookie_name_is_guard_specific(): void
    {
        // Use reflection to test protected method
        $reflection = new \ReflectionClass($this->service);
        $method = $reflection->getMethod('getRememberCookieName');
        $method->setAccessible(true);

        $this->assertEquals('two_factor_remember_web', $method->invoke($this->service, 'web'));
        $this->assertEquals('two_factor_remember_admin', $method->invoke($this->service, 'admin'));
    }

    // ===========================================
    // REPLAY PROTECTION TESTS
    // ===========================================

    public function test_verify_code_for_user_rejects_replayed_code(): void
    {
        $user = User::factory()->create(['two_factor_code_timestamp' => null]);
        $secret = $this->service->enableTwoFactor($user);
        $validCode = $this->google2fa->getCurrentOtp($secret);

        // First use must succeed
        $result1 = $this->service->verifyCodeForUser($user, $validCode);
        $this->assertTrue($result1);

        // Second use with the exact same code in the same 30-second window must be rejected
        $user->refresh();
        $result2 = $this->service->verifyCodeForUser($user, $validCode);
        $this->assertFalse($result2);
    }

    public function test_verify_code_for_user_updates_timestamp_on_success(): void
    {
        $user = User::factory()->create(['two_factor_code_timestamp' => null]);
        $secret = $this->service->enableTwoFactor($user);
        $validCode = $this->google2fa->getCurrentOtp($secret);

        $this->service->verifyCodeForUser($user, $validCode);
        $user->refresh();

        $this->assertNotNull($user->two_factor_code_timestamp);
    }

    public function test_verify_code_for_user_does_not_update_timestamp_on_failure(): void
    {
        $user = User::factory()->create(['two_factor_code_timestamp' => null]);
        $this->service->enableTwoFactor($user);

        $this->service->verifyCodeForUser($user, '000000');
        $user->refresh();

        $this->assertNull($user->two_factor_code_timestamp);
    }
}
