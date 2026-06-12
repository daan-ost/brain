<?php

namespace App\Notifications;

use App\Jobs\SendPostmarkTemplateEmail;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

class LoginCodeNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public string $code)
    {
        $this->onQueue('default');
    }

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        $locale = $notifiable->preferred_language ?? 'nl';
        if (! in_array($locale, ['en', 'nl'], true)) {
            $locale = 'en';
        }

        $templateAlias = "login_code__{$locale}";

        SendPostmarkTemplateEmail::dispatch(
            templateAlias: $templateAlias,
            templateModel: [
                'name'            => $notifiable->name,
                'code'            => $this->code,
                'expires_minutes' => 15,
                'subject'         => __('auth.login_code_email_subject', [], $locale),
                'product_name'    => env('APP_PRODUCT_NAME', config('app.name')),
                'product_url'     => config('app.url'),
                'logo_url'        => env('APP_LOGO_URL', ''),
                'company_name'    => env('APP_COMPANY_NAME', config('app.name')),
                'company_address' => env('APP_COMPANY_ADDRESS', 'Amsterdam, Netherlands'),
            ],
            to: $notifiable->email,
            toName: $notifiable->name,
            tag: 'login-code',
            messageStream: config('services.postmark.message_stream_id')
        );

        return [
            'code_sent' => true,
            'locale'    => $locale,
        ];
    }
}
