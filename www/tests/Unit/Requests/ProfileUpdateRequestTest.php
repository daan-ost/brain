<?php

namespace Tests\Unit\Requests;

use App\Http\Requests\ProfileUpdateRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Validator;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ProfileUpdateRequestTest extends TestCase
{
    use RefreshDatabase;

    private function makeRequest(array $data, ?User $user = null): array
    {
        $user = $user ?? User::factory()->create();

        $request = new ProfileUpdateRequest;
        $request->setUserResolver(fn () => $user);
        $request->merge($data);

        $validator = Validator::make($data, $request->rules());

        return [
            'passes' => $validator->passes(),
            'errors' => $validator->errors()->toArray(),
        ];
    }

    #[Test]
    public function it_validates_required_name(): void
    {
        $result = $this->makeRequest([
            'email' => 'test@example.com',
            'preferred_language' => 'en',
        ]);

        $this->assertFalse($result['passes']);
        $this->assertArrayHasKey('name', $result['errors']);
    }

    #[Test]
    public function it_validates_name_max_length(): void
    {
        $result = $this->makeRequest([
            'name' => str_repeat('a', 256),
            'email' => 'test@example.com',
            'preferred_language' => 'en',
        ]);

        $this->assertFalse($result['passes']);
        $this->assertArrayHasKey('name', $result['errors']);
    }

    #[Test]
    public function it_validates_required_email(): void
    {
        $result = $this->makeRequest([
            'name' => 'Test User',
            'preferred_language' => 'en',
        ]);

        $this->assertFalse($result['passes']);
        $this->assertArrayHasKey('email', $result['errors']);
    }

    #[Test]
    public function it_validates_email_format(): void
    {
        $result = $this->makeRequest([
            'name' => 'Test User',
            'email' => 'invalid-email',
            'preferred_language' => 'en',
        ]);

        $this->assertFalse($result['passes']);
        $this->assertArrayHasKey('email', $result['errors']);
    }

    #[Test]
    public function it_validates_email_must_be_lowercase(): void
    {
        $result = $this->makeRequest([
            'name' => 'Test User',
            'email' => 'TEST@EXAMPLE.COM',
            'preferred_language' => 'en',
        ]);

        $this->assertFalse($result['passes']);
        $this->assertArrayHasKey('email', $result['errors']);
    }

    #[Test]
    public function it_validates_email_uniqueness_but_ignores_current_user(): void
    {
        $existingUser = User::factory()->create(['email' => 'existing@example.com']);
        $currentUser = User::factory()->create(['email' => 'current@example.com']);

        // Should fail when trying to use another user's email
        $result = $this->makeRequest([
            'name' => 'Test User',
            'email' => 'existing@example.com',
            'preferred_language' => 'en',
        ], $currentUser);

        $this->assertFalse($result['passes']);
        $this->assertArrayHasKey('email', $result['errors']);

        // Should pass when keeping current user's own email
        $result = $this->makeRequest([
            'name' => 'Test User',
            'email' => 'current@example.com',
            'preferred_language' => 'en',
        ], $currentUser);

        $this->assertTrue($result['passes']);
    }

    #[Test]
    public function it_validates_required_preferred_language(): void
    {
        $result = $this->makeRequest([
            'name' => 'Test User',
            'email' => 'test@example.com',
        ]);

        $this->assertFalse($result['passes']);
        $this->assertArrayHasKey('preferred_language', $result['errors']);
    }

    #[Test]
    public function it_validates_preferred_language_must_be_en_or_nl(): void
    {
        $result = $this->makeRequest([
            'name' => 'Test User',
            'email' => 'test@example.com',
            'preferred_language' => 'de',
        ]);

        $this->assertFalse($result['passes']);
        $this->assertArrayHasKey('preferred_language', $result['errors']);

        // Valid languages
        foreach (['en', 'nl'] as $lang) {
            $result = $this->makeRequest([
                'name' => 'Test User',
                'email' => 'test@example.com',
                'preferred_language' => $lang,
            ]);

            $this->assertTrue($result['passes'], "Language '$lang' should be valid");
        }
    }

    #[Test]
    public function it_validates_billing_country_code_is_optional(): void
    {
        $result = $this->makeRequest([
            'name' => 'Test User',
            'email' => 'test@example.com',
            'preferred_language' => 'en',
        ]);

        $this->assertTrue($result['passes']);
    }

    #[Test]
    public function it_validates_billing_country_code_must_be_2_characters(): void
    {
        $result = $this->makeRequest([
            'name' => 'Test User',
            'email' => 'test@example.com',
            'preferred_language' => 'en',
            'billing_country_code' => 'NLD',
        ]);

        $this->assertFalse($result['passes']);
        $this->assertArrayHasKey('billing_country_code', $result['errors']);
    }

    #[Test]
    public function it_validates_billing_country_code_must_be_uppercase(): void
    {
        $result = $this->makeRequest([
            'name' => 'Test User',
            'email' => 'test@example.com',
            'preferred_language' => 'en',
            'billing_country_code' => 'nl',
        ]);

        $this->assertFalse($result['passes']);
        $this->assertArrayHasKey('billing_country_code', $result['errors']);
    }

    #[Test]
    public function it_passes_with_valid_data(): void
    {
        $result = $this->makeRequest([
            'name' => 'Test User',
            'email' => 'test@example.com',
            'preferred_language' => 'en',
            'billing_country_code' => 'NL',
        ]);

        $this->assertTrue($result['passes']);
    }
}
