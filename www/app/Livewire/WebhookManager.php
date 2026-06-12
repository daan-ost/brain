<?php

namespace App\Livewire;

use App\Models\Webhook;
use App\Services\WebhookService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Livewire\Component;

class WebhookManager extends Component
{
    public $webhooks = [];

    public $showModal = false;

    public $showDeliveriesModal = false;

    public $editingWebhookId = null;

    public $selectedWebhookDeliveries = [];

    // Form fields
    public $url = '';

    public $description = '';

    public $secret = '';

    public $events = [];

    public $isActive = true;

    // Messages
    public $successMessage = '';

    public $errorMessage = '';

    protected function rules()
    {
        return [
            'url' => [
                'required',
                'url',
                'max:500',
                function ($attribute, $value, $fail) {
                    $parsed = parse_url($value);
                    $scheme = $parsed['scheme'] ?? '';
                    if ($scheme === 'http') {
                        $host = $parsed['host'] ?? '';
                        if (! app()->environment('local') && ! in_array($host, ['localhost', '127.0.0.1'])) {
                            $fail(__('webhooks.https_required'));
                        }
                    } elseif ($scheme !== 'https') {
                        $fail(__('webhooks.https_required'));
                    }
                },
            ],
            'events' => 'required|array|min:1',
            'events.*' => [Rule::in(Webhook::VALID_EVENTS)],
            'description' => 'nullable|string|max:255',
            'secret' => 'nullable|string|max:255',
        ];
    }

    public function mount()
    {
        $this->loadWebhooks();
    }

    public function loadWebhooks()
    {
        $this->webhooks = Auth::user()->webhooks()
            ->orderBy('created_at', 'desc')
            ->get()
            ->toArray();
    }

    public function openCreateModal()
    {
        $this->resetForm();
        $this->showModal = true;
        $this->editingWebhookId = null;
    }

    public function openEditModal($webhookId)
    {
        $webhook = Auth::user()->webhooks()->find($webhookId);

        if (! $webhook) {
            $this->errorMessage = __('webhooks.not_found');

            return;
        }

        $this->editingWebhookId = $webhookId;
        $this->url = $webhook->url;
        $this->description = $webhook->description ?? '';
        $this->events = $webhook->events;
        $this->isActive = $webhook->is_active;
        $this->secret = '';
        $this->showModal = true;
    }

    public function closeModal()
    {
        $this->showModal = false;
        $this->resetForm();
    }

    public function resetForm()
    {
        $this->url = '';
        $this->description = '';
        $this->secret = '';
        $this->events = [];
        $this->isActive = true;
        $this->editingWebhookId = null;
        $this->clearMessages();
    }

    public function clearMessages()
    {
        $this->successMessage = '';
        $this->errorMessage = '';
    }

    public function save()
    {
        $this->clearMessages();
        $this->validate();

        try {
            if ($this->editingWebhookId) {
                $webhook = Auth::user()->webhooks()->find($this->editingWebhookId);
                if (! $webhook) {
                    $this->errorMessage = __('webhooks.not_found');

                    return;
                }

                $data = [
                    'url' => $this->url,
                    'events' => $this->events,
                    'description' => $this->description ?: null,
                    'is_active' => $this->isActive,
                ];

                if ($this->secret) {
                    $data['secret'] = $this->secret;
                }

                $webhook->update($data);

                if ($this->isActive) {
                    $webhook->resetFailureCount();
                }

                $this->successMessage = __('webhooks.updated');
            } else {
                // Check limit
                if (Auth::user()->webhooks()->count() >= Webhook::MAX_WEBHOOKS_PER_USER) {
                    $this->errorMessage = __('webhooks.limit_reached', ['max' => Webhook::MAX_WEBHOOKS_PER_USER]);

                    return;
                }

                Auth::user()->webhooks()->create([
                    'url' => $this->url,
                    'events' => $this->events,
                    'description' => $this->description ?: null,
                    'secret' => $this->secret ?: null,
                    'is_active' => true,
                ]);

                $this->successMessage = __('webhooks.created');
            }

            $this->loadWebhooks();
            $this->closeModal();

        } catch (\Exception $e) {
            $this->errorMessage = __('webhooks.error_saving');
        }
    }

    public function delete($webhookId)
    {
        $this->clearMessages();

        $webhook = Auth::user()->webhooks()->find($webhookId);

        if (! $webhook) {
            $this->errorMessage = __('webhooks.not_found');

            return;
        }

        $webhook->delete();
        $this->loadWebhooks();
        $this->successMessage = __('webhooks.deleted');
    }

    public function toggleActive($webhookId)
    {
        $webhook = Auth::user()->webhooks()->find($webhookId);

        if (! $webhook) {
            return;
        }

        $webhook->update(['is_active' => ! $webhook->is_active]);

        if ($webhook->is_active) {
            $webhook->resetFailureCount();
        }

        $this->loadWebhooks();
    }

    public function sendTest($webhookId)
    {
        $this->clearMessages();

        $webhook = Auth::user()->webhooks()->find($webhookId);

        if (! $webhook) {
            $this->errorMessage = __('webhooks.not_found');

            return;
        }

        if (! $webhook->is_active) {
            $this->errorMessage = __('webhooks.test_inactive');

            return;
        }

        try {
            $webhookService = app(WebhookService::class);
            $webhookService->sendTestEvent($webhook);
            $this->successMessage = __('webhooks.test_sent');
        } catch (\Exception $e) {
            $this->errorMessage = __('webhooks.test_failed');
        }
    }

    public function showDeliveries($webhookId)
    {
        $webhook = Auth::user()->webhooks()->find($webhookId);

        if (! $webhook) {
            return;
        }

        $this->selectedWebhookDeliveries = $webhook->deliveries()
            ->orderBy('created_at', 'desc')
            ->limit(20)
            ->get()
            ->toArray();

        $this->showDeliveriesModal = true;
    }

    public function closeDeliveriesModal()
    {
        $this->showDeliveriesModal = false;
        $this->selectedWebhookDeliveries = [];
    }

    public function render()
    {
        return view('livewire.webhook-manager', [
            'availableEvents' => Webhook::VALID_EVENTS,
            'maxWebhooks' => Webhook::MAX_WEBHOOKS_PER_USER,
        ]);
    }
}
