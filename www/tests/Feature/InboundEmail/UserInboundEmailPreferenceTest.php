<?php

namespace Tests\Feature\InboundEmail;

use App\Models\User;
use App\Models\UserInboundEmailPreference;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserInboundEmailPreferenceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['inbound.email_domain' => 'inbound.test.com']);
        config(['inbound.available_actions' => ['merge', 'convert']]);
    }

    public function test_generates_default_actions_on_create(): void
    {
        $user = User::factory()->create();

        $preference = UserInboundEmailPreference::create([
            'user_id' => $user->id,
            'inbound_enabled' => true,
            'verify_sender' => true,
        ]);

        $actions = $preference->available_actions;

        $this->assertArrayHasKey('merge', $actions);
        $this->assertArrayHasKey('convert', $actions);

        // Each action should have token, email, and enabled
        foreach (['merge', 'convert'] as $action) {
            $this->assertArrayHasKey('token', $actions[$action]);
            $this->assertArrayHasKey('email', $actions[$action]);
            $this->assertArrayHasKey('enabled', $actions[$action]);

            // Token should be 12 characters
            $this->assertEquals(12, strlen($actions[$action]['token']));

            // Email format should be action+token@domain
            $expectedEmail = "{$action}+{$actions[$action]['token']}@inbound.test.com";
            $this->assertEquals($expectedEmail, $actions[$action]['email']);
        }
    }

    public function test_generates_unique_tokens_for_different_users(): void
    {
        $user1 = User::factory()->create();
        $user2 = User::factory()->create();

        $preference1 = UserInboundEmailPreference::create([
            'user_id' => $user1->id,
            'inbound_enabled' => true,
        ]);

        $preference2 = UserInboundEmailPreference::create([
            'user_id' => $user2->id,
            'inbound_enabled' => true,
        ]);

        // Tokens should be different
        $this->assertNotEquals(
            $preference1->available_actions['merge']['token'],
            $preference2->available_actions['merge']['token']
        );
    }

    public function test_find_by_token_returns_correct_preference(): void
    {
        $user1 = User::factory()->create();
        $user2 = User::factory()->create();

        $preference1 = UserInboundEmailPreference::create([
            'user_id' => $user1->id,
            'inbound_enabled' => true,
        ]);

        $preference2 = UserInboundEmailPreference::create([
            'user_id' => $user2->id,
            'inbound_enabled' => true,
        ]);

        $token = $preference1->available_actions['merge']['token'];

        $found = UserInboundEmailPreference::findByToken($token);

        $this->assertNotNull($found);
        $this->assertEquals($preference1->id, $found->id);
    }

    public function test_find_by_token_returns_null_for_invalid_token(): void
    {
        $user = User::factory()->create();

        UserInboundEmailPreference::create([
            'user_id' => $user->id,
            'inbound_enabled' => true,
        ]);

        $found = UserInboundEmailPreference::findByToken('nonexistent-token');

        $this->assertNull($found);
    }

    public function test_find_by_token_ignores_disabled_preferences(): void
    {
        $user = User::factory()->create();

        $preference = UserInboundEmailPreference::create([
            'user_id' => $user->id,
            'inbound_enabled' => false, // Disabled
        ]);

        $token = $preference->available_actions['merge']['token'];

        $found = UserInboundEmailPreference::findByToken($token);

        $this->assertNull($found);
    }

    public function test_get_email_for_action(): void
    {
        $user = User::factory()->create();

        $preference = UserInboundEmailPreference::create([
            'user_id' => $user->id,
            'inbound_enabled' => true,
        ]);

        $mergeEmail = $preference->getEmailForAction('merge');
        $convertEmail = $preference->getEmailForAction('convert');
        $invalidEmail = $preference->getEmailForAction('nonexistent');

        $this->assertStringContainsString('merge+', $mergeEmail);
        $this->assertStringContainsString('@inbound.test.com', $mergeEmail);

        $this->assertStringContainsString('convert+', $convertEmail);

        $this->assertNull($invalidEmail);
    }

    public function test_get_all_action_emails_only_returns_enabled(): void
    {
        $user = User::factory()->create();

        $preference = UserInboundEmailPreference::create([
            'user_id' => $user->id,
            'inbound_enabled' => true,
        ]);

        // Disable the convert action
        $preference->disableAction('convert');

        $emails = $preference->getAllActionEmails();

        $this->assertArrayHasKey('merge', $emails);
        $this->assertArrayNotHasKey('convert', $emails);
    }

    public function test_enable_and_disable_action(): void
    {
        $user = User::factory()->create();

        $preference = UserInboundEmailPreference::create([
            'user_id' => $user->id,
            'inbound_enabled' => true,
        ]);

        // Initially enabled
        $this->assertTrue($preference->isActionEnabled('merge'));

        // Disable
        $preference->disableAction('merge');
        $preference->refresh();
        $this->assertFalse($preference->isActionEnabled('merge'));

        // Re-enable
        $preference->enableAction('merge');
        $preference->refresh();
        $this->assertTrue($preference->isActionEnabled('merge'));
    }

    public function test_get_action_for_token(): void
    {
        $user = User::factory()->create();

        $preference = UserInboundEmailPreference::create([
            'user_id' => $user->id,
            'inbound_enabled' => true,
        ]);

        $mergeToken = $preference->available_actions['merge']['token'];
        $convertToken = $preference->available_actions['convert']['token'];

        $this->assertEquals('merge', $preference->getActionForToken($mergeToken));
        $this->assertEquals('convert', $preference->getActionForToken($convertToken));
        $this->assertNull($preference->getActionForToken('invalid-token'));
    }

    public function test_user_relationship(): void
    {
        $user = User::factory()->create();

        $preference = UserInboundEmailPreference::create([
            'user_id' => $user->id,
            'inbound_enabled' => true,
        ]);

        $this->assertEquals($user->id, $preference->user->id);
    }
}
