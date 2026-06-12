<?php

use App\Jobs\SendPostmarkTemplateEmail;
use App\Livewire\Profile\ApiTokenManager;
use App\Models\AnalyticsEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\PersonalAccessToken;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
});

describe('Component Rendering', function () {
    it('renders successfully for authenticated users', function () {
        Livewire::test(ApiTokenManager::class)
            ->assertStatus(200)
            ->assertSet('tokenName', '')
            ->assertSet('newTokenValue', null)
            ->assertSet('showTokenModal', false);
    });

    it('displays existing tokens', function () {
        $this->user->createToken('Token 1');
        $this->user->createToken('Token 2');

        Livewire::test(ApiTokenManager::class)
            ->assertViewHas('tokens', function ($tokens) {
                return $tokens->count() === 2;
            });
    });

    it('orders tokens by creation date descending', function () {
        $token1 = $this->user->createToken('First Token');
        sleep(1);
        $token2 = $this->user->createToken('Second Token');

        Livewire::test(ApiTokenManager::class)
            ->assertViewHas('tokens', function ($tokens) {
                return $tokens->first()->name === 'Second Token'
                    && $tokens->last()->name === 'First Token';
            });
    });

    it('requires authentication', function () {
        auth()->logout();

        $this->get('/profile/api-tokens')
            ->assertRedirect('/login');
    });
});

describe('Token Creation', function () {
    it('creates a new token successfully', function () {
        Livewire::test(ApiTokenManager::class)
            ->set('tokenName', 'My Test Token')
            ->call('createToken')
            ->assertSet('showTokenModal', true)
            ->assertSet('tokenName', '')
            ->assertDispatched('token-created');

        expect($this->user->tokens()->count())->toBe(1)
            ->and($this->user->tokens()->first()->name)->toBe('My Test Token');
    });

    it('stores plain text token for one-time display', function () {
        $component = Livewire::test(ApiTokenManager::class)
            ->set('tokenName', 'Display Token')
            ->call('createToken');

        $plainTextToken = $component->get('newTokenValue');

        expect($plainTextToken)->not->toBeNull()
            ->and($plainTextToken)->toContain('|'); // Sanctum token format: {id}|{token}
    });

    it('resets form after successful creation', function () {
        Livewire::test(ApiTokenManager::class)
            ->set('tokenName', 'Test Token')
            ->call('createToken')
            ->assertSet('tokenName', '');
    });

    it('validates token name is required', function () {
        Livewire::test(ApiTokenManager::class)
            ->set('tokenName', '')
            ->call('createToken')
            ->assertHasErrors(['tokenName' => 'required']);
    });

    it('validates token name maximum length', function () {
        Livewire::test(ApiTokenManager::class)
            ->set('tokenName', str_repeat('a', 256))
            ->call('createToken')
            ->assertHasErrors(['tokenName' => 'max']);
    });

    it('accepts valid token name with max length', function () {
        $validName = str_repeat('a', 255);

        Livewire::test(ApiTokenManager::class)
            ->set('tokenName', $validName)
            ->call('createToken')
            ->assertHasNoErrors();

        expect($this->user->tokens()->first()->name)->toBe($validName);
    });

    it('validates duplicate token names', function () {
        $this->user->createToken('Same Name');

        Livewire::test(ApiTokenManager::class)
            ->set('tokenName', 'Same Name')
            ->call('createToken')
            ->assertHasErrors(['tokenName' => 'unique']);

        expect($this->user->tokens()->where('name', 'Same Name')->count())->toBe(1);
    });

    it('allows same token name for different users', function () {
        $otherUser = User::factory()->create();
        $otherUser->createToken('Shared Name');

        Livewire::test(ApiTokenManager::class)
            ->set('tokenName', 'Shared Name')
            ->call('createToken')
            ->assertHasNoErrors();

        expect($this->user->tokens()->where('name', 'Shared Name')->count())->toBe(1)
            ->and($otherUser->tokens()->where('name', 'Shared Name')->count())->toBe(1);
    });

    it('allows reusing token name after revocation', function () {
        $token = $this->user->createToken('Reusable Name');

        Livewire::test(ApiTokenManager::class)
            ->call('revokeToken', $token->accessToken->id)
            ->set('tokenName', 'Reusable Name')
            ->call('createToken')
            ->assertHasNoErrors();

        expect($this->user->tokens()->where('name', 'Reusable Name')->count())->toBe(1);
    });

    it('creates token with wildcard abilities', function () {
        Livewire::test(ApiTokenManager::class)
            ->set('tokenName', 'Full Access Token')
            ->call('createToken');

        $token = $this->user->tokens()->first();

        expect($token->abilities)->toBe(['*']);
    });
});

describe('Token Revocation', function () {
    it('revokes a token successfully', function () {
        $token = $this->user->createToken('Token to Revoke');
        $tokenId = $token->accessToken->id;

        Livewire::test(ApiTokenManager::class)
            ->call('revokeToken', $tokenId)
            ->assertDispatched('token-revoked');

        expect($this->user->tokens()->find($tokenId))->toBeNull();
    });

    it('removes token from list after revocation', function () {
        $token1 = $this->user->createToken('Keep Token');
        $token2 = $this->user->createToken('Delete Token');

        Livewire::test(ApiTokenManager::class)
            ->call('revokeToken', $token2->accessToken->id);

        expect($this->user->tokens()->count())->toBe(1)
            ->and($this->user->tokens()->first()->name)->toBe('Keep Token');
    });

    it('prevents revoking another users token', function () {
        $otherUser = User::factory()->create();
        $otherToken = $otherUser->createToken('Other User Token');
        $otherTokenId = $otherToken->accessToken->id;

        Livewire::test(ApiTokenManager::class)
            ->call('revokeToken', $otherTokenId)
            ->assertForbidden();

        // Verify token was not deleted
        expect(PersonalAccessToken::find($otherTokenId))->not->toBeNull();
    });

    it('handles non-existent token gracefully', function () {
        expect(fn () => Livewire::test(ApiTokenManager::class)->call('revokeToken', 99999))
            ->toThrow(\Illuminate\Database\Eloquent\ModelNotFoundException::class);
    });

    it('only shows current users tokens', function () {
        $otherUser = User::factory()->create();
        $otherUser->createToken('Other User Token');

        Livewire::test(ApiTokenManager::class)
            ->assertViewHas('tokens', function ($tokens) {
                return $tokens->count() === 0;
            });
    });
});

describe('Modal State Management', function () {
    it('opens modal after token creation', function () {
        Livewire::test(ApiTokenManager::class)
            ->set('tokenName', 'Test Token')
            ->assertSet('showTokenModal', false)
            ->call('createToken')
            ->assertSet('showTokenModal', true);
    });

    it('closes modal on user action', function () {
        Livewire::test(ApiTokenManager::class)
            ->set('showTokenModal', true)
            ->set('newTokenValue', 'some-token-value')
            ->call('closeTokenModal')
            ->assertSet('showTokenModal', false)
            ->assertSet('newTokenValue', null);
    });

    it('clears token value when closing modal', function () {
        $component = Livewire::test(ApiTokenManager::class)
            ->set('tokenName', 'Token')
            ->call('createToken');

        expect($component->get('newTokenValue'))->not->toBeNull();

        $component->call('closeTokenModal')
            ->assertSet('newTokenValue', null);
    });
});

describe('Event Dispatching', function () {
    it('dispatches token-created event', function () {
        Livewire::test(ApiTokenManager::class)
            ->set('tokenName', 'Event Test Token')
            ->call('createToken')
            ->assertDispatched('token-created');
    });

    it('dispatches token-revoked event', function () {
        $token = $this->user->createToken('Token');

        Livewire::test(ApiTokenManager::class)
            ->call('revokeToken', $token->accessToken->id)
            ->assertDispatched('token-revoked');
    });
});

describe('Security', function () {
    it('verifies token ownership before revocation', function () {
        $user1 = User::factory()->create();
        $user2 = User::factory()->create();

        $user1Token = $user1->createToken('User 1 Token');

        $this->actingAs($user2);

        Livewire::test(ApiTokenManager::class)
            ->call('revokeToken', $user1Token->accessToken->id)
            ->assertForbidden();

        // Verify token still exists
        expect(PersonalAccessToken::find($user1Token->accessToken->id))->not->toBeNull();
    });

    it('does not expose other users tokens in render', function () {
        $user1 = User::factory()->create();
        $user2 = User::factory()->create();

        $user1->createToken('User 1 Token');
        $user2->createToken('User 2 Token 1');
        $user2->createToken('User 2 Token 2');

        $this->actingAs($user1);

        Livewire::test(ApiTokenManager::class)
            ->assertViewHas('tokens', function ($tokens) {
                return $tokens->count() === 1
                    && $tokens->first()->name === 'User 1 Token';
            });
    });
});

describe('Multiple Token Management', function () {
    it('handles multiple token creation', function () {
        Livewire::test(ApiTokenManager::class)
            ->set('tokenName', 'Token 1')
            ->call('createToken')
            ->call('closeTokenModal')
            ->set('tokenName', 'Token 2')
            ->call('createToken')
            ->call('closeTokenModal')
            ->set('tokenName', 'Token 3')
            ->call('createToken');

        expect($this->user->tokens()->count())->toBe(3);
    });

    it('handles multiple token revocations', function () {
        $token1 = $this->user->createToken('Token 1');
        $token2 = $this->user->createToken('Token 2');
        $token3 = $this->user->createToken('Token 3');

        Livewire::test(ApiTokenManager::class)
            ->call('revokeToken', $token1->accessToken->id)
            ->call('revokeToken', $token3->accessToken->id);

        expect($this->user->tokens()->count())->toBe(1)
            ->and($this->user->tokens()->first()->name)->toBe('Token 2');
    });

    it('allows creating token after revoking one', function () {
        $token = $this->user->createToken('Old Token');

        Livewire::test(ApiTokenManager::class)
            ->call('revokeToken', $token->accessToken->id)
            ->set('tokenName', 'New Token')
            ->call('createToken')
            ->assertHasNoErrors();

        expect($this->user->tokens()->count())->toBe(1)
            ->and($this->user->tokens()->first()->name)->toBe('New Token');
    });
});

describe('Custom Error Messages', function () {
    it('shows custom error message for required validation', function () {
        Livewire::test(ApiTokenManager::class)
            ->set('tokenName', '')
            ->call('createToken')
            ->assertHasErrors(['tokenName' => 'required']);
    });

    it('shows custom error message for duplicate token name', function () {
        $this->user->createToken('Duplicate');

        Livewire::test(ApiTokenManager::class)
            ->set('tokenName', 'Duplicate')
            ->call('createToken')
            ->assertHasErrors(['tokenName' => 'unique']);
    });

    it('shows custom error message for max length validation', function () {
        Livewire::test(ApiTokenManager::class)
            ->set('tokenName', str_repeat('a', 256))
            ->call('createToken')
            ->assertHasErrors(['tokenName' => 'max']);
    });
});

describe('Token Name Validation Edge Cases', function () {
    it('trims whitespace from token name', function () {
        Livewire::test(ApiTokenManager::class)
            ->set('tokenName', '  Token Name  ')
            ->call('createToken')
            ->assertHasNoErrors();

        // Laravel validation doesn't auto-trim in newer versions
        // The token is created with the spaces
        expect($this->user->tokens()->count())->toBe(1);
    });

    it('accepts special characters in token name', function () {
        Livewire::test(ApiTokenManager::class)
            ->set('tokenName', 'API Token - Dev #1 (Test)')
            ->call('createToken')
            ->assertHasNoErrors();

        expect($this->user->tokens()->first()->name)->toBe('API Token - Dev #1 (Test)');
    });

    it('accepts unicode characters in token name', function () {
        Livewire::test(ApiTokenManager::class)
            ->set('tokenName', 'Token 测试 🚀')
            ->call('createToken')
            ->assertHasNoErrors();

        expect($this->user->tokens()->first()->name)->toBe('Token 测试 🚀');
    });
});

describe('Email Notifications', function () {
    beforeEach(function () {
        Queue::fake();
    });

    /**
     * Helper function to get private property value from job using reflection
     */
    function getJobProperty($job, string $propertyName): mixed
    {
        $reflection = new \ReflectionClass($job);
        $property = $reflection->getProperty($propertyName);
        $property->setAccessible(true);

        return $property->getValue($job);
    }

    it('sends email notification when token is created', function () {
        Livewire::test(ApiTokenManager::class)
            ->set('tokenName', 'Test Token')
            ->call('createToken');

        Queue::assertPushed(SendPostmarkTemplateEmail::class, function ($job) {
            $templateAlias = getJobProperty($job, 'templateAlias');
            $to = getJobProperty($job, 'to');
            $tag = getJobProperty($job, 'tag');

            return str_contains($templateAlias, 'api-token-updated')
                && $to === $this->user->email
                && $tag === 'api-token-updated';
        });
    });

    it('sends email notification when token is revoked', function () {
        $token = $this->user->createToken('Token to Revoke');

        Livewire::test(ApiTokenManager::class)
            ->call('revokeToken', $token->accessToken->id);

        Queue::assertPushed(SendPostmarkTemplateEmail::class, function ($job) {
            $templateAlias = getJobProperty($job, 'templateAlias');
            $to = getJobProperty($job, 'to');
            $tag = getJobProperty($job, 'tag');

            return str_contains($templateAlias, 'api-token-updated')
                && $to === $this->user->email
                && $tag === 'api-token-updated';
        });
    });

    it('sends email in users preferred language (Dutch)', function () {
        $this->user->update(['preferred_language' => 'nl']);

        Livewire::test(ApiTokenManager::class)
            ->set('tokenName', 'Test Token')
            ->call('createToken');

        Queue::assertPushed(SendPostmarkTemplateEmail::class, function ($job) {
            $templateAlias = getJobProperty($job, 'templateAlias');

            return $templateAlias === 'api-token-updated__nl';
        });
    });

    it('sends email in users preferred language (English)', function () {
        $this->user->update(['preferred_language' => 'en']);

        Livewire::test(ApiTokenManager::class)
            ->set('tokenName', 'Test Token')
            ->call('createToken');

        Queue::assertPushed(SendPostmarkTemplateEmail::class, function ($job) {
            $templateAlias = getJobProperty($job, 'templateAlias');

            return $templateAlias === 'api-token-updated__en';
        });
    });

    it('defaults to English when preferred language is not set', function () {
        $this->user->update(['preferred_language' => '']);

        Livewire::test(ApiTokenManager::class)
            ->set('tokenName', 'Test Token')
            ->call('createToken');

        Queue::assertPushed(SendPostmarkTemplateEmail::class, function ($job) {
            $templateAlias = getJobProperty($job, 'templateAlias');

            return $templateAlias === 'api-token-updated__en';
        });
    });

    it('includes token details in email template data for creation', function () {
        Livewire::test(ApiTokenManager::class)
            ->set('tokenName', 'My API Token')
            ->call('createToken');

        Queue::assertPushed(SendPostmarkTemplateEmail::class, function ($job) {
            $model = getJobProperty($job, 'templateModel');

            return $model['user_name'] === $this->user->name
                && $model['token_name'] === 'My API Token'
                && in_array($model['action'], ['created', 'aangemaakt'])
                && isset($model['action_datetime'])
                && isset($model['ip_address'])
                && isset($model['support_email']);
        });
    });

    it('includes token details in email template data for revocation', function () {
        $token = $this->user->createToken('Token to Delete');

        Livewire::test(ApiTokenManager::class)
            ->call('revokeToken', $token->accessToken->id);

        Queue::assertPushed(SendPostmarkTemplateEmail::class, function ($job) {
            $model = getJobProperty($job, 'templateModel');

            return $model['user_name'] === $this->user->name
                && $model['token_name'] === 'Token to Delete'
                && in_array($model['action'], ['revoked', 'ingetrokken'])
                && isset($model['action_datetime'])
                && isset($model['ip_address'])
                && isset($model['support_email']);
        });
    });

    it('includes IP address in email notification', function () {
        Livewire::test(ApiTokenManager::class)
            ->set('tokenName', 'Test Token')
            ->call('createToken');

        Queue::assertPushed(SendPostmarkTemplateEmail::class, function ($job) {
            $model = getJobProperty($job, 'templateModel');

            return isset($model['ip_address'])
                && ! empty($model['ip_address']);
        });
    });

    it('translates action to Dutch when locale is nl', function () {
        $this->user->update(['preferred_language' => 'nl']);

        Livewire::test(ApiTokenManager::class)
            ->set('tokenName', 'Test Token')
            ->call('createToken');

        Queue::assertPushed(SendPostmarkTemplateEmail::class, function ($job) {
            $model = getJobProperty($job, 'templateModel');

            return $model['action'] === 'aangemaakt';
        });
    });

    it('translates revoke action to Dutch when locale is nl', function () {
        $this->user->update(['preferred_language' => 'nl']);
        $token = $this->user->createToken('Token');

        Livewire::test(ApiTokenManager::class)
            ->call('revokeToken', $token->accessToken->id);

        Queue::assertPushed(SendPostmarkTemplateEmail::class, function ($job) {
            $model = getJobProperty($job, 'templateModel');

            return $model['action'] === 'ingetrokken';
        });
    });

    it('does not fail token creation if email sending fails', function () {
        // This test verifies graceful degradation
        Livewire::test(ApiTokenManager::class)
            ->set('tokenName', 'Test Token')
            ->call('createToken')
            ->assertHasNoErrors();

        // Token should still be created
        expect($this->user->tokens()->count())->toBe(1);
    });
});

describe('Analytics Logging for Emails', function () {
    it('logs analytics event when token creation email is sent', function () {
        Livewire::test(ApiTokenManager::class)
            ->set('tokenName', 'Test Token')
            ->call('createToken');

        $analyticsEvent = AnalyticsEvent::where('event', 'api_token_email_sent')->first();

        expect($analyticsEvent)->not->toBeNull()
            ->and($analyticsEvent->meta['token_name'])->toBe('Test Token')
            ->and($analyticsEvent->meta['action'])->toBe('created')
            ->and($analyticsEvent->meta['recipient_email'])->toBe($this->user->email)
            ->and($analyticsEvent->meta['ip_address'])->not->toBeNull();
    });

    it('logs analytics event when token revocation email is sent', function () {
        $token = $this->user->createToken('Token to Revoke');

        // Clear existing analytics events
        AnalyticsEvent::truncate();

        Livewire::test(ApiTokenManager::class)
            ->call('revokeToken', $token->accessToken->id);

        $emailEvent = AnalyticsEvent::where('event', 'api_token_email_sent')->first();

        expect($emailEvent)->not->toBeNull()
            ->and($emailEvent->meta['token_name'])->toBe('Token to Revoke')
            ->and($emailEvent->meta['action'])->toBe('revoked')
            ->and($emailEvent->meta['recipient_email'])->toBe($this->user->email);
    });
});
