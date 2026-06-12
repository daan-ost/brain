<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class TwoFactorDisabledNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(__('Two-Factor Authentication Disabled'))
            ->greeting(__('Hello :name!', ['name' => $notifiable->name]))
            ->line(__('Two-factor authentication has been disabled on your account.'))
            ->line(__('Your account is now protected only by your password.'))
            ->line(__('If you did not disable this, please change your password immediately and contact support.'))
            ->action(__('Re-enable Two-Factor Authentication'), url('/profile/password'))
            ->line(__('We recommend keeping two-factor authentication enabled for better security.'));
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'two_factor_disabled',
        ];
    }
}
