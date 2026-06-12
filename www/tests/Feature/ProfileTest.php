<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ProfileTest extends TestCase
{
    use RefreshDatabase;

    public function test_profile_page_is_displayed(): void
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->get('/profile/account');

        $response->assertOk();
    }

    public function test_profile_information_can_be_updated(): void
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->patch('/profile', [
                'name' => 'Test User',
                'email' => $user->email, // Keep same email
                'preferred_language' => 'nl',
            ]);

        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect('/profile/account');

        $user->refresh();

        $this->assertSame('Test User', $user->name);
        $this->assertSame('nl', $user->preferred_language);
    }

    public function test_email_change_creates_pending_email_change(): void
    {
        Queue::fake(); // Don't send actual emails

        $user = User::factory()->create([
            'email' => 'original@example.com',
            'preferred_language' => 'en',
        ]);

        $response = $this
            ->actingAs($user)
            ->patch('/profile', [
                'name' => $user->name,
                'email' => 'newemail@example.com',
                'preferred_language' => 'en',
            ]);

        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect('/profile/account')
            ->assertSessionHas('status', 'email-change-pending');

        $user->refresh();

        // Email should NOT change yet
        $this->assertSame('original@example.com', $user->email);
        // Pending email should be set
        $this->assertSame('newemail@example.com', $user->pending_email);
        // Token should be set
        $this->assertNotNull($user->email_change_token);
        $this->assertNotNull($user->email_change_token_expires_at);

        // Verify emails were queued (2 emails: verification + notification)
        Queue::assertPushed(\App\Jobs\SendPostmarkTemplateEmail::class, 2);
    }

    public function test_email_verification_status_is_unchanged_when_the_email_address_is_unchanged(): void
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->patch('/profile', [
                'name' => 'Test User',
                'email' => $user->email,
                'preferred_language' => 'nl',
            ]);

        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect('/profile/account');

        $this->assertNotNull($user->refresh()->email_verified_at);
    }

    public function test_user_can_delete_their_account(): void
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->delete('/profile', [
                'password' => 'password',
            ]);

        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect('/');

        $this->assertGuest();
        $this->assertNull(User::find($user->id));
        $this->assertSoftDeleted($user);
    }

    public function test_correct_password_must_be_provided_to_delete_account(): void
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->from('/profile')
            ->delete('/profile', [
                'password' => 'wrong-password',
            ]);

        $response
            ->assertSessionHasErrorsIn('userDeletion', 'password')
            ->assertRedirect('/profile');

        $this->assertNotNull($user->fresh());
    }
}
