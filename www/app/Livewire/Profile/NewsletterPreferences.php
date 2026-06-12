<?php

namespace App\Livewire\Profile;

use App\Services\NewsletterService;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

class NewsletterPreferences extends Component
{
    public bool $subscribed = true;

    public function mount(): void
    {
        $user = Auth::user();
        $this->subscribed = $user->newsletter_subscribed ?? true;
    }

    public function toggleSubscription(): void
    {
        $user = Auth::user();
        $newsletterService = app(NewsletterService::class);

        if ($this->subscribed) {
            $newsletterService->unsubscribe($user);
            $this->subscribed = false;
            session()->flash('newsletter-message', __('newsletter.unsubscribed_successfully'));
        } else {
            $newsletterService->subscribe($user);
            $this->subscribed = true;
            session()->flash('newsletter-message', __('newsletter.subscribed_successfully'));
        }

        $this->dispatch('newsletter-preference-updated');
    }

    public function render(): \Illuminate\Contracts\View\View
    {
        return view('livewire.profile.newsletter-preferences');
    }
}
