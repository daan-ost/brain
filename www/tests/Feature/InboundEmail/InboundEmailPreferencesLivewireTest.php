<?php

namespace Tests\Feature\InboundEmail;

use App\Livewire\Profile\InboundEmailPreferences;
use App\Models\User;
use App\Models\UserInboundEmailPreference;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class InboundEmailPreferencesLivewireTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['inbound.enabled' => true]);
        config(['inbound.email_domain' => 'inbound.test.com']);
        config(['inbound.available_actions' => ['merge', 'convert']]);
        config(['inbound.action_descriptions' => [
            'merge' => ['en' => 'Merge documents', 'nl' => 'Documenten samenvoegen'],
            'convert' => ['en' => 'Convert format', 'nl' => 'Formaat converteren'],
        ]]);
    }

    public function test_component_renders(): void
    {
        $user = User::factory()->create();

        Livewire::actingAs($user)
            ->test(InboundEmailPreferences::class)
            ->assertOk()
            ->assertSee(__('inbound.title'));
    }

    public function test_shows_disabled_state_when_no_preference(): void
    {
        $user = User::factory()->create();

        // When no preference exists, inbound is disabled and no email addresses are shown
        Livewire::actingAs($user)
            ->test(InboundEmailPreferences::class)
            ->assertSet('inboundEnabled', false)
            ->assertDontSee('@inbound.test.com'); // No email addresses shown when disabled
    }

    public function test_toggle_inbound_creates_preference(): void
    {
        $user = User::factory()->create();

        $this->assertNull($user->inboundEmailPreference);

        Livewire::actingAs($user)
            ->test(InboundEmailPreferences::class)
            ->call('toggleInbound')
            ->assertSet('inboundEnabled', true);

        $this->assertDatabaseHas('user_inbound_email_preferences', [
            'user_id' => $user->id,
            'inbound_enabled' => true,
        ]);
    }

    public function test_toggle_inbound_disables_existing_preference(): void
    {
        $user = User::factory()->create();

        UserInboundEmailPreference::create([
            'user_id' => $user->id,
            'inbound_enabled' => true,
            'verify_sender' => true,
        ]);

        Livewire::actingAs($user)
            ->test(InboundEmailPreferences::class)
            ->assertSet('inboundEnabled', true)
            ->call('toggleInbound')
            ->assertSet('inboundEnabled', false);

        $this->assertDatabaseHas('user_inbound_email_preferences', [
            'user_id' => $user->id,
            'inbound_enabled' => false,
        ]);
    }

    public function test_shows_real_emails_when_enabled(): void
    {
        $user = User::factory()->create();

        $preference = UserInboundEmailPreference::create([
            'user_id' => $user->id,
            'inbound_enabled' => true,
            'verify_sender' => true,
        ]);

        $mergeToken = $preference->available_actions['merge']['token'];

        Livewire::actingAs($user)
            ->test(InboundEmailPreferences::class)
            ->assertSee("merge+{$mergeToken}@inbound.test.com");
    }

    public function test_toggle_verify_sender(): void
    {
        $user = User::factory()->create();

        UserInboundEmailPreference::create([
            'user_id' => $user->id,
            'inbound_enabled' => true,
            'verify_sender' => true,
        ]);

        Livewire::actingAs($user)
            ->test(InboundEmailPreferences::class)
            ->assertSet('verifySender', true)
            ->call('toggleVerifySender')
            ->assertSet('verifySender', false);

        $this->assertDatabaseHas('user_inbound_email_preferences', [
            'user_id' => $user->id,
            'verify_sender' => false,
        ]);
    }

    public function test_toggle_advanced_options(): void
    {
        $user = User::factory()->create();

        UserInboundEmailPreference::create([
            'user_id' => $user->id,
            'inbound_enabled' => true,
        ]);

        Livewire::actingAs($user)
            ->test(InboundEmailPreferences::class)
            ->assertSet('showAdvanced', false)
            ->call('toggleAdvanced')
            ->assertSet('showAdvanced', true)
            ->call('toggleAdvanced')
            ->assertSet('showAdvanced', false);
    }

    public function test_dispatches_event_on_preference_update(): void
    {
        $user = User::factory()->create();

        Livewire::actingAs($user)
            ->test(InboundEmailPreferences::class)
            ->call('toggleInbound')
            ->assertDispatched('inbound-preference-updated');
    }

    public function test_enables_inbound_successfully(): void
    {
        $user = User::factory()->create();

        // Test that toggling inbound enables it and creates a preference
        Livewire::actingAs($user)
            ->test(InboundEmailPreferences::class)
            ->assertSet('inboundEnabled', false)
            ->call('toggleInbound')
            ->assertSet('inboundEnabled', true)
            ->assertDispatched('inbound-preference-updated');

        $this->assertDatabaseHas('user_inbound_email_preferences', [
            'user_id' => $user->id,
            'inbound_enabled' => true,
        ]);
    }

    public function test_shows_action_descriptions(): void
    {
        $user = User::factory()->create();

        UserInboundEmailPreference::create([
            'user_id' => $user->id,
            'inbound_enabled' => true,
        ]);

        Livewire::actingAs($user)
            ->test(InboundEmailPreferences::class)
            ->assertSee('Merge documents')
            ->assertSee('Convert format');
    }

    public function test_shows_feature_disabled_warning_when_globally_disabled(): void
    {
        config(['inbound.enabled' => false]);

        $user = User::factory()->create();

        Livewire::actingAs($user)
            ->test(InboundEmailPreferences::class)
            ->assertSee(__('inbound.feature_disabled'));
    }

    public function test_handles_race_condition_with_first_or_create(): void
    {
        $user = User::factory()->create();

        // Call toggle twice rapidly - should not fail
        $component = Livewire::actingAs($user)->test(InboundEmailPreferences::class);

        $component->call('toggleInbound');
        $component->call('toggleInbound');

        // Should only have one preference record
        $this->assertEquals(1, UserInboundEmailPreference::where('user_id', $user->id)->count());
    }
}
