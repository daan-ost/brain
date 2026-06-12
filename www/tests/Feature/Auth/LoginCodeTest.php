<?php

namespace Tests\Feature\Auth;

use App\Models\LoginCode;
use App\Models\User;
use App\Notifications\LoginCodeNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

class LoginCodeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        RateLimiter::clear('login-code-send:user@example.com');
        RateLimiter::clear('login-code-verify:user@example.com');
    }

    public function test_request_form_renders(): void
    {
        $this->get(route('login.code'))->assertStatus(200);
    }

    public function test_send_code_creates_record_and_dispatches_notification(): void
    {
        Notification::fake();
        $user = User::factory()->create(['email' => 'user@example.com']);

        $this->post(route('login.code.send'), ['email' => 'user@example.com'])
            ->assertRedirect(route('login.code.verify', ['email' => 'user@example.com']))
            ->assertSessionHas('status', 'login-code-sent');

        $this->assertDatabaseCount('login_codes', 1);
        $this->assertDatabaseHas('login_codes', ['email' => 'user@example.com', 'used_at' => null]);
        Notification::assertSentTo($user, LoginCodeNotification::class);
    }

    public function test_send_code_for_unknown_email_returns_success_without_creating_record(): void
    {
        Notification::fake();

        $this->post(route('login.code.send'), ['email' => 'nobody@example.com'])
            ->assertRedirect(route('login.code.verify', ['email' => 'nobody@example.com']))
            ->assertSessionHas('status', 'login-code-sent');

        $this->assertDatabaseCount('login_codes', 0);
        Notification::assertNothingSent();
    }

    public function test_verify_code_logs_in_user(): void
    {
        $user = User::factory()->create(['email' => 'user@example.com', 'email_verified_at' => null]);
        $code = '123456';
        LoginCode::create([
            'email'      => 'user@example.com',
            'code'       => Hash::make($code),
            'expires_at' => now()->addMinutes(15),
        ]);

        $this->post(route('login.code.verify.submit'), [
            'email' => 'user@example.com',
            'code'  => $code,
        ])->assertRedirect(route('dashboard'));

        $this->assertAuthenticatedAs($user);
        $this->assertNotNull($user->fresh()->email_verified_at, 'email_verified_at must be set after code login');
    }

    public function test_invalid_code_does_not_log_in(): void
    {
        User::factory()->create(['email' => 'user@example.com']);
        LoginCode::create([
            'email'      => 'user@example.com',
            'code'       => Hash::make('123456'),
            'expires_at' => now()->addMinutes(15),
        ]);

        $this->from(route('login.code.verify', ['email' => 'user@example.com']))
            ->post(route('login.code.verify.submit'), [
                'email' => 'user@example.com',
                'code'  => '000000',
            ])->assertSessionHasErrors('code');

        $this->assertGuest();
    }

    public function test_expired_code_is_rejected(): void
    {
        User::factory()->create(['email' => 'user@example.com']);
        LoginCode::create([
            'email'      => 'user@example.com',
            'code'       => Hash::make('123456'),
            'expires_at' => now()->subMinute(),
        ]);

        $this->from(route('login.code.verify', ['email' => 'user@example.com']))
            ->post(route('login.code.verify.submit'), [
                'email' => 'user@example.com',
                'code'  => '123456',
            ])->assertSessionHasErrors('code');

        $this->assertGuest();
    }

    public function test_used_code_cannot_be_reused(): void
    {
        $user = User::factory()->create(['email' => 'user@example.com']);
        $code = '123456';
        LoginCode::create([
            'email'      => 'user@example.com',
            'code'       => Hash::make($code),
            'expires_at' => now()->addMinutes(15),
            'used_at'    => now(),
        ]);

        $this->from(route('login.code.verify', ['email' => 'user@example.com']))
            ->post(route('login.code.verify.submit'), [
                'email' => 'user@example.com',
                'code'  => $code,
            ])->assertSessionHasErrors('code');

        $this->assertGuest();
    }

    public function test_sending_a_new_code_invalidates_previous_codes(): void
    {
        Notification::fake();
        User::factory()->create(['email' => 'user@example.com']);

        $this->post(route('login.code.send'), ['email' => 'user@example.com']);
        $this->post(route('login.code.send'), ['email' => 'user@example.com']);

        $codes = LoginCode::where('email', 'user@example.com')->get();
        $this->assertCount(2, $codes);
        $this->assertCount(1, $codes->whereNull('used_at'), 'Only the latest code should be unused');
    }
}
