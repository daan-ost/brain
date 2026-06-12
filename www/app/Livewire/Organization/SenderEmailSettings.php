<?php

namespace App\Livewire\Organization;

use App\Enums\OrganizationRole;
use App\Enums\SenderConfigStatus;
use App\Enums\SenderLevel;
use App\Models\Organization;
use App\Models\OrganizationSenderConfig;
use App\Services\SenderConfigService;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

class SenderEmailSettings extends Component
{
    public string $selectedLevel = 'reply_to';

    public string $fromEmail = '';

    public string $fromName = '';

    public string $replyToEmail = '';

    public string $domain = '';

    public ?OrganizationSenderConfig $config = null;

    protected SenderConfigService $service;

    public function boot(SenderConfigService $service): void
    {
        $this->service = $service;
    }

    public function mount(): void
    {
        if (! config('features.send_email_functionality')) {
            abort(404);
        }

        $org = $this->getOrganization();

        if (! $org) {
            $this->redirectRoute('profile.organization');

            return;
        }

        $this->config = $org->senderConfig;

        if ($this->config) {
            $this->selectedLevel = $this->config->sender_level->value;
            $this->fromEmail = $this->config->from_email ?? '';
            $this->fromName = $this->config->from_name ?? '';
            $this->replyToEmail = $this->config->reply_to_email ?? '';
            $this->domain = $this->config->domain ?? '';
        }
    }

    public function rules(): array
    {
        return match ($this->selectedLevel) {
            'reply_to' => [
                'replyToEmail' => ['required', 'email', 'max:255'],
                'fromName' => ['required', 'string', 'max:255'],
            ],
            'sender_signature' => [
                'fromEmail' => ['required', 'email', 'max:255'],
                'fromName' => ['required', 'string', 'max:255'],
            ],
            'domain_auth' => [
                'domain' => ['required', 'string', 'max:255', 'regex:/^[a-zA-Z0-9][a-zA-Z0-9.-]+\.[a-zA-Z]{2,}$/', function (string $attribute, mixed $value, \Closure $fail) {
                    $org = $this->getOrganization();
                    $existing = \App\Models\OrganizationSenderConfig::where('domain', strtolower($value))
                        ->where('organization_id', '!=', $org?->id)
                        ->exists();
                    if ($existing) {
                        $fail(__('profile.sender_domain_already_registered'));
                    }
                }],
                'fromEmail' => ['required', 'email', 'max:255', function (string $attribute, mixed $value, \Closure $fail) {
                    $emailDomain = strtolower(substr($value, strrpos($value, '@') + 1));
                    if ($emailDomain !== strtolower($this->domain)) {
                        $fail(__('profile.sender_email_domain_mismatch'));
                    }
                }],
            ],
            default => [],
        };
    }

    public function saveReplyTo(): void
    {
        $this->selectedLevel = 'reply_to';
        $this->validate();

        $org = $this->getOrganization();
        if (! $org) {
            return;
        }

        $this->config = $this->service->configureReplyTo($org, $this->replyToEmail, $this->fromName);

        session()->flash('message', __('profile.sender_saved'));
    }

    public function saveSenderSignature(): void
    {
        $this->selectedLevel = 'sender_signature';
        $this->validate();

        if (! $this->service->isBusinessEmail($this->fromEmail)) {
            $this->addError('fromEmail', __('profile.sender_blocked_domain'));

            return;
        }

        $org = $this->getOrganization();
        if (! $org) {
            return;
        }

        try {
            $this->config = $this->service->createSenderSignature($org, $this->fromEmail, $this->fromName);
            session()->flash('message', __('profile.sender_verification_sent'));
        } catch (\Exception $e) {
            $postmarkMessage = SenderConfigService::extractPostmarkError($e);
            $this->addError('fromEmail', $postmarkMessage ?? __('profile.sender_creation_failed'));
        }
    }

    public function saveDomainAuth(): void
    {
        $this->selectedLevel = 'domain_auth';
        $this->validate();

        $org = $this->getOrganization();
        if (! $org) {
            return;
        }

        try {
            $this->config = $this->service->createDomain($org, $this->domain, $this->fromEmail);
            session()->flash('message', __('profile.sender_dns_records_shown'));
        } catch (\Exception $e) {
            $postmarkMessage = SenderConfigService::extractPostmarkError($e);
            $this->addError('domain', $postmarkMessage ?? __('profile.sender_creation_failed'));
        }
    }

    public function checkVerification(): void
    {
        if (! $this->config) {
            return;
        }

        $org = $this->getOrganization();
        if (! $org || $this->config->organization_id !== $org->id) {
            abort(403);
        }

        if ($this->config->sender_level === SenderLevel::SenderSignature) {
            $this->config = $this->service->checkSignatureStatus($this->config);
        } elseif ($this->config->sender_level === SenderLevel::DomainAuth) {
            $this->config = $this->service->verifyDomainDns($this->config);
        }

        if ($this->config->status === SenderConfigStatus::Verified) {
            session()->flash('message', __('profile.sender_verified'));
        } elseif ($this->config->status === SenderConfigStatus::Failed) {
            session()->flash('error', $this->config->failure_reason ?? __('profile.sender_verification_failed'));
        }
    }

    public function resendVerification(): void
    {
        if (! $this->config || $this->config->sender_level !== SenderLevel::SenderSignature) {
            return;
        }

        $org = $this->getOrganization();
        if (! $org || $this->config->organization_id !== $org->id) {
            abort(403);
        }

        try {
            $this->service->resendSignatureVerification($this->config);
            session()->flash('message', __('profile.sender_verification_resent'));
        } catch (\Exception $e) {
            session()->flash('error', __('profile.sender_resend_failed'));
        }
    }

    public function remove(): void
    {
        if (! $this->config) {
            return;
        }

        $org = $this->getOrganization();
        if (! $org || $this->config->organization_id !== $org->id) {
            abort(403);
        }

        $this->service->removeConfig($this->config);
        $this->config = null;
        $this->reset(['fromEmail', 'fromName', 'replyToEmail', 'domain']);
        $this->selectedLevel = 'reply_to';

        session()->flash('message', __('profile.sender_removed'));
    }

    public function render()
    {
        return view('livewire.organization.sender-email-settings');
    }

    private function getOrganization(): ?Organization
    {
        $organizationId = session('current_organization_id');
        $user = Auth::user();

        if ($organizationId) {
            $org = $user->organizations()
                ->where('organizations.id', $organizationId)
                ->wherePivot('role', OrganizationRole::Owner)
                ->first();

            if ($org) {
                return $org;
            }
        }

        return $user->organizations()->wherePivot('role', OrganizationRole::Owner)->first();
    }
}
