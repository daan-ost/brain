<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class TwoFactorResetByAdminNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        protected ?string $reason = null
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject(__('Two-Factor Authentication Reset'))
            ->greeting(__('Hello :name!', ['name' => $notifiable->name]))
            ->line(__('Two-factor authentication has been reset on your account by an administrator.'));

        if ($this->reason) {
            $mail->line(__('Reason: :reason', ['reason' => $this->reason]));
        }

        return $mail
            ->line(__('You will need to set up two-factor authentication again if you wish to use it.'))
            ->action(__('Set Up Two-Factor Authentication'), url('/profile/password'))
            ->line(__('If you have questions about this action, please contact support.'));
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'two_factor_reset_by_admin',
            'reason' => $this->reason,
        ];
    }
}
