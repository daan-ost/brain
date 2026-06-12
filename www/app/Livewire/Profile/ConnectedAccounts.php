<?php

namespace App\Livewire\Profile;

use App\Services\AnalyticsService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Livewire\Component;

class ConnectedAccounts extends Component
{
    public function disconnectGoogle(): void
    {
        $user = Auth::user();

        if (! $user) {
            return;
        }

        if (! $user->hasGoogleLinked()) {
            return;
        }

        if (! $user->hasPassword()) {
            $this->addError('google', __('profile.disconnect_google_no_password'));
            return;
        }

        // M3: assign individually instead of mass-update (google_* are not
        // in $fillable for security reasons).
        $user->google_id            = null;
        $user->google_token         = null;
        $user->google_refresh_token = null;

        // M4: cycle the remember_token so any "remember me" cookies issued
        // during a Google login are invalidated. The user clicked "disconnect
        // Google" — they expect Google-derived sessions on other devices to
        // stop working. The current session is preserved (we just re-issued
        // its remember token via Auth::user()).
        $user->setRememberToken(Str::random(60));
        $user->save();

        AnalyticsService::log('user_google_disconnected', [
            'user_id' => $user->id,
        ]);

        $this->dispatch('google-disconnected');
        session()->flash('status', 'google-disconnected');
    }

    public function render()
    {
        return view('livewire.profile.connected-accounts', [
            'user' => Auth::user(),
            'googleEnabled' => \App\Http\Controllers\Auth\SocialiteController::googleEnabled(),
        ]);
    }
}
