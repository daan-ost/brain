<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class TwoFactorRecoveryCodeUsedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        protected ?string $ipAddress = null,
        protected ?string $userAgent = null
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject(__('Recovery Code Used'))
            ->greeting(__('Hello :name!', ['name' => $notifiable->name]))
            ->line(__('A recovery code was used to sign in to your account.'));

        if ($this->ipAddress) {
            $mail->line(__('IP Address: :ip', ['ip' => $this->ipAddress]));
        }

        $codesRemaining = $notifiable->getTwoFactorRecoveryCodes()->count();

        return $mail
            ->line(__('You have :count recovery codes remaining.', ['count' => $codesRemaining]))
            ->line(__('If this was not you, your account may be compromised. Please change your password immediately.'))
            ->action(__('View Recovery Codes'), url('/profile/password'))
            ->line(__('We recommend regenerating your recovery codes and storing them in a secure location.'));
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'two_factor_recovery_code_used',
            'ip_address' => $this->ipAddress,
        ];
    }
}
