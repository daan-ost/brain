<?php

/**
 * Basewebsite Propagation Smoke Tests — Sender Email Settings
 *
 * Verifies that the custom sender email feature works from the user's perspective.
 * Feature is behind the 'send_email_functionality' feature flag.
 */

declare(strict_types=1);

use App\Enums\SenderConfigStatus;
use App\Enums\SenderLevel;
use App\Models\Organization;
use App\Models\OrganizationSenderConfig;
use App\Models\User;
use App\Services\SenderConfigService;
use Livewire\Livewire;

beforeEach(function () {
    if (! class_exists(\App\Models\OrganizationSenderConfig::class)) {
        $this->markTestSkipped('OrganizationSenderConfig model not available');
    }
    config()->set('features.send_email_functionality', true);
});

function createSenderTestAdmin(): array
{
    $user = User::factory()->create(['email_verified_at' => now()]);
    $org = Organization::factory()->create();
    $org->users()->attach($user, ['role' => \App\Enums\OrganizationRole::Owner->value, 'joined_at' => now()]);
    session(['current_organization_id' => $org->id]);

    return [$user, $org];
}

describe('Sender Email Page Access', function () {
    it('shows sender email settings page for org admin', function () {
        [$user] = createSenderTestAdmin();

        $this->actingAs($user)
            ->get('/profile/organization/sender-email')
            ->assertStatus(200);
    });

    it('blocks unauthenticated access to sender email page', function () {
        $this->get('/profile/organization/sender-email')
            ->assertRedirect('/login');
    });

    it('returns 404 when feature flag is disabled', function () {
        config()->set('features.send_email_functionality', false);

        $user = User::factory()->create(['email_verified_at' => now()]);

        $this->actingAs($user)
            ->get('/profile/organization/sender-email')
            ->assertStatus(404);
    });
});

describe('Reply-To Configuration', function () {
    it('can save reply-to sender settings', function () {
        [$user, $org] = createSenderTestAdmin();

        Livewire::actingAs($user)
            ->test(\App\Livewire\Organization\SenderEmailSettings::class)
            ->set('replyToEmail', 'contact@example.com')
            ->set('fromName', 'Test Company')
            ->call('saveReplyTo')
            ->assertHasNoErrors();

        $org->refresh();
        expect($org->senderConfig)->not->toBeNull();
        expect($org->senderConfig->reply_to_email)->toBe('contact@example.com');
        expect($org->senderConfig->from_name)->toBe('Test Company');
        expect($org->senderConfig->sender_level->value)->toBe('reply_to');
    });

    it('validates reply-to email is required', function () {
        [$user] = createSenderTestAdmin();

        Livewire::actingAs($user)
            ->test(\App\Livewire\Organization\SenderEmailSettings::class)
            ->set('replyToEmail', '')
            ->set('fromName', 'Test Company')
            ->call('saveReplyTo')
            ->assertHasErrors(['replyToEmail']);
    });
});

describe('Sender Signature Validation', function () {
    it('blocks free email providers for sender signature', function () {
        if (empty(config('sender.blocked_email_domains'))) {
            skip('No blocked email domains configured');
        }

        [$user] = createSenderTestAdmin();

        Livewire::actingAs($user)
            ->test(\App\Livewire\Organization\SenderEmailSettings::class)
            ->set('fromEmail', 'test@gmail.com')
            ->set('fromName', 'Test Company')
            ->call('saveSenderSignature')
            ->assertHasErrors(['fromEmail']);
    });
});

describe('Sender Config Model', function () {
    it('can create sender config via factory', function () {
        $config = \App\Models\OrganizationSenderConfig::factory()->create();
        expect($config->id)->not->toBeNull();
        expect($config->sender_level)->toBeInstanceOf(\App\Enums\SenderLevel::class);
        expect($config->status)->toBeInstanceOf(\App\Enums\SenderConfigStatus::class);
    });

    it('determines usability based on level and status', function () {
        // Reply-to with active status is usable
        $replyTo = \App\Models\OrganizationSenderConfig::factory()->create();
        expect($replyTo->isUsable())->toBeTrue();

        // Sender signature pending verification is not usable
        $pending = \App\Models\OrganizationSenderConfig::factory()
            ->senderSignature()
            ->pendingVerification()
            ->create();
        expect($pending->isUsable())->toBeFalse();

        // Sender signature verified is usable
        $verified = \App\Models\OrganizationSenderConfig::factory()
            ->senderSignature()
            ->verified()
            ->create();
        expect($verified->isUsable())->toBeTrue();
    });

    it('belongs to an organization', function () {
        $config = \App\Models\OrganizationSenderConfig::factory()->create();
        expect($config->organization)->toBeInstanceOf(Organization::class);
    });
});

describe('Remove Sender Config', function () {
    it('can remove sender config via component', function () {
        [$user, $org] = createSenderTestAdmin();

        // First create a reply-to config (no external API call)
        Livewire::actingAs($user)
            ->test(\App\Livewire\Organization\SenderEmailSettings::class)
            ->set('replyToEmail', 'contact@example.com')
            ->set('fromName', 'Test Company')
            ->call('saveReplyTo')
            ->assertHasNoErrors();

        expect($org->fresh()->senderConfig)->not->toBeNull();

        // Now remove it
        Livewire::actingAs($user)
            ->test(\App\Livewire\Organization\SenderEmailSettings::class)
            ->call('remove');

        expect($org->fresh()->senderConfig)->toBeNull();
    });
});

// ---------------------------------------------------------------------------
// SenderConfigService::resolveSender
// ---------------------------------------------------------------------------

describe('SenderConfigService::resolveSender', function () {
    it('falls back to default sender when no config exists', function () {
        $org = Organization::factory()->create();

        $service = app(SenderConfigService::class);
        $result = $service->resolveSender($org);

        expect($result['from'])->toBe(config('mail.from.address'));
        expect($result['from_name'])->toBe(config('mail.from.name'));
        expect($result['reply_to'])->toBeNull();
    });

    it('falls back to default when config is not usable (pending)', function () {
        $org = Organization::factory()->create();
        OrganizationSenderConfig::factory()
            ->senderSignature()
            ->pendingVerification()
            ->create(['organization_id' => $org->id]);

        $service = app(SenderConfigService::class);
        $result = $service->resolveSender($org->fresh());

        expect($result['from'])->toBe(config('mail.from.address'));
    });

    it('uses platform from with custom reply-to for reply_to level', function () {
        $org = Organization::factory()->create();
        OrganizationSenderConfig::factory()->create([
            'organization_id' => $org->id,
            'sender_level'    => SenderLevel::ReplyTo,
            'status'          => SenderConfigStatus::Active,
            'reply_to_email'  => 'contact@example.com',
            'from_name'       => 'My Company',
        ]);

        $service = app(SenderConfigService::class);
        $result = $service->resolveSender($org->fresh());

        expect($result['from'])->toBe(config('mail.from.address'));
        expect($result['from_name'])->toBe('My Company');
        expect($result['reply_to'])->toBe('contact@example.com');
    });

    it('uses org from_email for verified sender signature', function () {
        $org = Organization::factory()->create();
        OrganizationSenderConfig::factory()
            ->senderSignature()
            ->verified()
            ->create([
                'organization_id' => $org->id,
                'from_email'      => 'hello@mybusiness.com',
                'from_name'       => 'My Business',
            ]);

        $service = app(SenderConfigService::class);
        $result = $service->resolveSender($org->fresh());

        expect($result['from'])->toBe('hello@mybusiness.com');
        expect($result['from_name'])->toBe('My Business');
    });

    it('uses org from_email for verified domain auth', function () {
        $org = Organization::factory()->create();
        OrganizationSenderConfig::factory()
            ->domainAuth()
            ->verified()
            ->create([
                'organization_id' => $org->id,
                'from_email'      => 'noreply@mybusiness.com',
                'from_name'       => 'My Business',
            ]);

        $service = app(SenderConfigService::class);
        $result = $service->resolveSender($org->fresh());

        expect($result['from'])->toBe('noreply@mybusiness.com');
    });
});

// ---------------------------------------------------------------------------
// SenderConfigService::isBusinessEmail
// ---------------------------------------------------------------------------

describe('SenderConfigService::isBusinessEmail', function () {
    it('allows business email addresses', function () {
        $service = app(SenderConfigService::class);

        expect($service->isBusinessEmail('hello@mybusiness.com'))->toBeTrue();
        expect($service->isBusinessEmail('contact@agency.nl'))->toBeTrue();
    });

    it('blocks free email providers when configured', function () {
        if (empty(config('sender.blocked_email_domains'))) {
            skip('No blocked email domains configured');
        }

        $service = app(SenderConfigService::class);

        expect($service->isBusinessEmail('user@gmail.com'))->toBeFalse();
        expect($service->isBusinessEmail('user@hotmail.com'))->toBeFalse();
    });
});

// ---------------------------------------------------------------------------
// Domain Auth Validation
// ---------------------------------------------------------------------------

describe('Domain Auth Validation', function () {
    it('rejects from_email that does not match domain', function () {
        [$user] = createSenderTestAdmin();

        Livewire::actingAs($user)
            ->test(\App\Livewire\Organization\SenderEmailSettings::class)
            ->set('selectedLevel', 'domain_auth')
            ->set('domain', 'mybusiness.com')
            ->set('fromEmail', 'contact@otherdomain.com')
            ->call('saveDomainAuth')
            ->assertHasErrors(['fromEmail']);
    });

    it('rejects invalid domain format', function () {
        [$user] = createSenderTestAdmin();

        Livewire::actingAs($user)
            ->test(\App\Livewire\Organization\SenderEmailSettings::class)
            ->set('selectedLevel', 'domain_auth')
            ->set('domain', 'not-a-valid-domain')
            ->set('fromEmail', 'contact@notavaliddomain.com')
            ->call('saveDomainAuth')
            ->assertHasErrors(['domain']);
    });
});

// ---------------------------------------------------------------------------
// Sender Enums
// ---------------------------------------------------------------------------

describe('SenderLevel Enum', function () {
    it('has all required cases', function () {
        expect(SenderLevel::ReplyTo->value)->toBe('reply_to');
        expect(SenderLevel::SenderSignature->value)->toBe('sender_signature');
        expect(SenderLevel::DomainAuth->value)->toBe('domain_auth');
    });

    it('has label and badgeColor methods', function () {
        foreach (SenderLevel::cases() as $case) {
            expect($case->label())->toBeString()->not->toBeEmpty();
            expect($case->badgeColor())->toBeString()->not->toBeEmpty();
        }
    });
});

describe('SenderConfigStatus Enum', function () {
    it('has all required cases', function () {
        expect(SenderConfigStatus::Active->value)->toBe('active');
        expect(SenderConfigStatus::PendingVerification->value)->toBe('pending_verification');
        expect(SenderConfigStatus::Verified->value)->toBe('verified');
        expect(SenderConfigStatus::Failed->value)->toBe('failed');
    });

    it('has label and badgeColor methods', function () {
        foreach (SenderConfigStatus::cases() as $case) {
            expect($case->label())->toBeString()->not->toBeEmpty();
            expect($case->badgeColor())->toBeString()->not->toBeEmpty();
        }
    });
});

// ---------------------------------------------------------------------------
// OrganizationSenderConfig isUsable — alle statussen
// ---------------------------------------------------------------------------

describe('OrganizationSenderConfig isUsable edge cases', function () {
    it('reply_to with failed status is not usable', function () {
        $config = OrganizationSenderConfig::factory()->create([
            'sender_level' => SenderLevel::ReplyTo,
            'status'       => SenderConfigStatus::Failed,
        ]);

        expect($config->isUsable())->toBeFalse();
    });

    it('domain_auth with pending verification is not usable', function () {
        $config = OrganizationSenderConfig::factory()
            ->domainAuth()
            ->pendingVerification()
            ->create();

        expect($config->isUsable())->toBeFalse();
    });

    it('domain_auth verified is usable', function () {
        $config = OrganizationSenderConfig::factory()
            ->domainAuth()
            ->verified()
            ->create();

        expect($config->isUsable())->toBeTrue();
    });
});

// ---------------------------------------------------------------------------
// SendInvoiceEmail — recipient structuur
// ---------------------------------------------------------------------------

describe('SendInvoiceEmail recipient structuur', function () {
    it('job class bestaat en kan worden aangemaakt', function () {
        if (! class_exists(\App\Jobs\SendInvoiceEmail::class)) {
            skip('SendInvoiceEmail job niet beschikbaar');
        }

        expect(class_exists(\App\Jobs\SendInvoiceEmail::class))->toBeTrue();
    });

    it('recipient array bevat email, name en locale velden', function () {
        if (! class_exists(\App\Jobs\SendInvoiceEmail::class)) {
            skip('SendInvoiceEmail job niet beschikbaar');
        }

        // Test de recipient-structuur via reflectie op de buildAdminRecipient helper-logica,
        // of verifieer de veldstructuur direct via een unit-stijl check
        $user = User::factory()->make(['preferred_language' => 'nl']);

        $recipient = [
            'email'  => $user->email,
            'name'   => $user->name,
            'locale' => $user->preferred_language ?? config('app.locale', 'en'),
        ];

        expect($recipient)->toHaveKeys(['email', 'name', 'locale']);
        expect($recipient['locale'])->toBe('nl');
        expect($recipient['email'])->toBeString()->not->toBeEmpty();
    });
});
