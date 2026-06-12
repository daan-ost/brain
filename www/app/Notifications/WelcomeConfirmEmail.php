<?php

namespace App\Notifications;

use App\Jobs\SendPostmarkTemplateEmail;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\URL;

class WelcomeConfirmEmail extends Notification implements ShouldQueue
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
        Log::info('WelcomeConfirmEmail notification triggered', [
            'user_id' => $notifiable->id,
            'user_email' => $notifiable->email,
            'user_name' => $notifiable->name,
            'locale' => $this->locale,
        ]);

        // Dispatch email job when notification is processed
        $confirmUrl = $this->generateConfirmationUrl($notifiable);
        $templateAlias = "welcome__{$this->locale}";

        SendPostmarkTemplateEmail::dispatch(
            templateAlias: $templateAlias,
            templateModel: [
                'name' => $notifiable->name,
                'action_url' => $confirmUrl,
                'action_label' => __('auth.verify_email', [], $this->locale),
                'subject' => __('auth.verify_your_email', [], $this->locale),

                // Layout variabelen uit .env
                'product_name' => env('APP_PRODUCT_NAME', config('app.name')),
                'product_url' => config('app.url'),
                'logo_url' => env('APP_LOGO_URL', ''),
                'company_name' => env('APP_COMPANY_NAME', config('app.name')),
                'company_address' => env('APP_COMPANY_ADDRESS', 'Amsterdam, Netherlands'),
            ],
            to: $notifiable->email,
            toName: $notifiable->name,
            tag: 'email-confirmation',
            messageStream: config('services.postmark.message_stream_id')
        );

        return [
            'locale' => $this->locale,
            'confirm_url' => $confirmUrl,
            'email_dispatched' => true,
        ];
    }

    private function generateConfirmationUrl($user): string
    {
        return URL::temporarySignedRoute(
            'email.confirm',
            now()->addHours(24),
            ['user' => $user->id, 'hash' => sha1($user->email)]
        );
    }
}
