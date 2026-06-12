<?php

namespace Tests\Unit\Models;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Unit-level coverage of User helpers + casts introduced for the
 * passwordless login feature (Google OAuth + email-code).
 */
class UserPasswordlessTest extends TestCase
{
    use RefreshDatabase;

    public function test_hasGoogleLinked_returns_true_when_google_id_set(): void
    {
        $user = User::factory()->create();
        $this->assertFalse($user->hasGoogleLinked());

        $user->google_id = 'google-sub-123';
        $user->save();
        $this->assertTrue($user->fresh()->hasGoogleLinked());
    }

    public function test_hasPassword_returns_true_for_factory_password(): void
    {
        $user = User::factory()->create();
        $this->assertTrue($user->hasPassword());
    }

    public function test_hasPassword_returns_false_for_null(): void
    {
        $user = User::factory()->create();
        $user->forceFill(['password' => null])->save();
        $this->assertFalse($user->fresh()->hasPassword());
    }

    public function test_hasPassword_returns_false_for_short_value(): void
    {
        $user = User::factory()->create();
        // Bypass de hashed cast door direct in de DB te schrijven, simuleer
        // een corrupted/legacy row zonder bcrypt-hash.
        \DB::table('users')->where('id', $user->id)->update(['password' => 'short']);
        $this->assertFalse($user->fresh()->hasPassword());
    }

    public function test_hasPassword_returns_false_for_bcrypt_hash_of_empty_string(): void
    {
        // Reproduceert de anonymizeAndDelete edge case (was L4-extra).
        $user = User::factory()->create();
        $user->update(['password' => '']); // Laravel hashed cast → bcrypt('') = 60+ chars
        $user->refresh();

        $this->assertSame(60, strlen($user->password ?? ''));
        $this->assertFalse(
            $user->hasPassword(),
            'bcrypt("") is 60+ chars but is NOT a usable password'
        );
    }

    public function test_anonymizeAndDelete_sets_password_to_null(): void
    {
        $user = User::factory()->create([
            'name'  => 'Real Name',
            'email' => 'real@example.com',
        ]);

        $user->anonymizeAndDelete();
        $deleted = User::withTrashed()->find($user->id);

        $this->assertNotNull($deleted);
        $this->assertNull($deleted->password, 'anonymizeAndDelete should null the password');
        $this->assertFalse($deleted->hasPassword());
        $this->assertStringContainsString('deleted_', $deleted->email);
    }

    public function test_google_oauth_tokens_are_hidden_in_serialisation(): void
    {
        $user = User::factory()->create();
        $user->google_id            = 'sub-1';
        $user->google_token         = 'access-token-secret';
        $user->google_refresh_token = 'refresh-token-secret';
        $user->save();

        $array = $user->fresh()->toArray();

        // google_id MAG zichtbaar zijn (geen secret), maar tokens niet
        $this->assertArrayNotHasKey('google_token', $array);
        $this->assertArrayNotHasKey('google_refresh_token', $array);
    }

    public function test_google_tokens_are_encrypted_at_rest(): void
    {
        $user = User::factory()->create();
        $user->google_token         = 'plaintext-access-token';
        $user->google_refresh_token = 'plaintext-refresh-token';
        $user->save();

        $raw = \DB::table('users')
            ->where('id', $user->id)
            ->select(['google_token', 'google_refresh_token'])
            ->first();

        $this->assertStringNotContainsString('plaintext-access-token', $raw->google_token);
        $this->assertStringNotContainsString('plaintext-refresh-token', $raw->google_refresh_token);

        // Eloquent decrypts on read
        $fresh = $user->fresh();
        $this->assertSame('plaintext-access-token', $fresh->google_token);
        $this->assertSame('plaintext-refresh-token', $fresh->google_refresh_token);
    }

    public function test_google_id_is_not_mass_assignable(): void
    {
        // M3: voorkomt OAuth-hijack via profile-update endpoints.
        $user = User::factory()->create();

        $user->fill(['google_id' => 'attacker-controlled-sub']);
        $user->save();

        $this->assertNull($user->fresh()->google_id, 'google_id must NOT be fillable');
    }

    public function test_google_token_is_not_mass_assignable(): void
    {
        $user = User::factory()->create();
        $user->fill(['google_token' => 'attacker-token']);
        $user->save();

        $this->assertNull($user->fresh()->google_token);
    }
}
