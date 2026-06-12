<?php

namespace App\Livewire\Profile;

use App\Models\InboundEmail;
use App\Models\UserInboundEmailPreference;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

class InboundEmailPreferences extends Component
{
    public bool $inboundEnabled = false;

    public bool $verifySender = true;

    public bool $showAdvanced = false;

    public array $actionEmails = [];

    public array $recentEmails = [];

    public function mount(): void
    {
        $user = Auth::user();
        $preference = $user->inboundEmailPreference ?? null;

        if ($preference) {
            $this->inboundEnabled = $preference->inbound_enabled;
            $this->verifySender = $preference->verify_sender;
            $this->actionEmails = $preference->getAllActionEmails();
        } else {
            // Generate default action emails for preview
            $domain = config('inbound.email_domain', 'inbound.example.com');
            $actions = config('inbound.available_actions', ['merge', 'convert']);
            foreach ($actions as $action) {
                $this->actionEmails[$action] = "{$action}+XXXXXX@{$domain}";
            }
        }

        // Load recent inbound emails for history
        $this->loadRecentEmails();
    }

    /**
     * Load recent inbound emails for history display
     */
    protected function loadRecentEmails(): void
    {
        $user = Auth::user();

        $this->recentEmails = InboundEmail::forUserHistory($user->id, 20)
            ->get()
            ->map(function ($email) {
                // Subject is automatically decrypted by the model's encrypted cast
                $subject = $email->subject;

                return [
                    'id' => $email->id,
                    'uuid' => $email->uuid,
                    'date' => $email->created_at->format('d-m-Y H:i'),
                    'subject' => $subject ? mb_substr($subject, 0, 50).(mb_strlen($subject) > 50 ? '...' : '') : null,
                    'action' => $email->action_type,
                    'status' => $email->status,
                    'output_available' => $email->isOutputAvailable(),
                    'days_remaining' => $email->getDaysUntilExpiry(),
                ];
            })
            ->toArray();
    }

    public function toggleInbound(): void
    {
        $user = Auth::user();

        // Use firstOrCreate to prevent race conditions
        $preference = UserInboundEmailPreference::firstOrCreate(
            ['user_id' => $user->id],
            [
                'inbound_enabled' => false,
                'verify_sender' => true,
            ]
        );

        // Toggle the preference
        $preference->update([
            'inbound_enabled' => ! $preference->inbound_enabled,
        ]);

        $this->inboundEnabled = $preference->inbound_enabled;
        $this->actionEmails = $preference->getAllActionEmails();

        session()->flash('inbound-message', $this->inboundEnabled
            ? __('inbound.enabled_successfully')
            : __('inbound.disabled_successfully')
        );

        $this->dispatch('inbound-preference-updated');
    }

    public function toggleVerifySender(): void
    {
        $user = Auth::user();
        $preference = $user->inboundEmailPreference;

        if ($preference) {
            $preference->update([
                'verify_sender' => ! $preference->verify_sender,
            ]);
            $this->verifySender = $preference->verify_sender;

            session()->flash('inbound-message', __('inbound.verify_sender_updated'));
            $this->dispatch('inbound-preference-updated');
        }
    }

    public function toggleAdvanced(): void
    {
        $this->showAdvanced = ! $this->showAdvanced;
    }

    public function copyToClipboard(string $email): void
    {
        // This is handled via Alpine.js in the view
        session()->flash('inbound-message', __('inbound.copied_to_clipboard'));
    }

    public function render(): \Illuminate\Contracts\View\View
    {
        $actions = config('inbound.available_actions', []);
        $actionDescriptions = config('inbound.action_descriptions', []);
        $locale = app()->getLocale();

        $actionsWithDescriptions = [];
        foreach ($actions as $action) {
            $actionsWithDescriptions[$action] = [
                'email' => $this->actionEmails[$action] ?? null,
                'description' => $actionDescriptions[$action][$locale] ?? $actionDescriptions[$action]['en'] ?? '',
            ];
        }

        return view('livewire.profile.inbound-email-preferences', [
            'actions' => $actionsWithDescriptions,
            'featureEnabled' => config('inbound.enabled', true),
            'recentEmails' => $this->recentEmails,
        ]);
    }
}
