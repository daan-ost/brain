<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class TwoFactorEnabledNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(__('Two-Factor Authentication Enabled'))
            ->greeting(__('Hello :name!', ['name' => $notifiable->name]))
            ->line(__('Two-factor authentication has been enabled on your account.'))
            ->line(__('From now on, you will need to enter a verification code from your authenticator app when logging in.'))
            ->line(__('If you did not enable this, please contact support immediately.'))
            ->action(__('Go to Profile'), url('/profile/password'))
            ->line(__('Keep your recovery codes in a safe place in case you lose access to your authenticator app.'));
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'two_factor_enabled',
        ];
    }
}
