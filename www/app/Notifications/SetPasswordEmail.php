<?php

namespace App\Notifications;

use App\Jobs\SendPostmarkTemplateEmail;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\URL;

class SetPasswordEmail extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public $locale = 'en'
    ) {
        $this->onQueue('default');
    }

    public function via(object $notifiable): array
    {
        return ['database']; // Store notification in database for tracking
    }

    public function toArray(object $notifiable): array
    {
        Log::info('SetPasswordEmail notification triggered', [
            'user_id' => $notifiable->id,
            'user_email' => $notifiable->email,
            'user_name' => $notifiable->name,
            'locale' => $this->locale,
        ]);

        // Dispatch email job when notification is processed
        $passwordSetupUrl = $this->generatePasswordSetupUrl($notifiable);
        $templateAlias = "set-password__{$this->locale}";

        $subject = $this->locale === 'nl'
            ? 'Stel je wachtwoord in'
            : 'Set Your Password';

        SendPostmarkTemplateEmail::dispatch(
            templateAlias: $templateAlias,
            templateModel: [
                'name' => $notifiable->name,
                'action_url' => $passwordSetupUrl,
                'action_label' => $this->locale === 'nl' ? 'Stel mijn wachtwoord in' : 'Set My Password',
                'subject' => $subject,

                // Layout variables from .env
                'product_name' => env('APP_PRODUCT_NAME', config('app.name')),
                'product_url' => config('app.url'),
                'logo_url' => env('APP_LOGO_URL', ''),
                'company_name' => env('APP_COMPANY_NAME', config('app.name')),
                'company_address' => env('APP_COMPANY_ADDRESS', 'Amsterdam, Netherlands'),
            ],
            to: $notifiable->email,
            toName: $notifiable->name,
            tag: 'password-setup',
            messageStream: config('services.postmark.message_stream_id')
        );

        return [
            'locale' => $this->locale,
            'password_setup_url' => $passwordSetupUrl,
            'email_dispatched' => true,
        ];
    }

    private function generatePasswordSetupUrl($user): string
    {
        return URL::temporarySignedRoute(
            'password.setup',
            now()->addDays(7), // Valid for 7 days
            ['user' => $user->id, 'hash' => sha1($user->email)]
        );
    }
}
