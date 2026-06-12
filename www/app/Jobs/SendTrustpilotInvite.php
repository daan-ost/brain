<?php

namespace App\Jobs;

use App\Models\User;
use App\Services\AnalyticsService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SendTrustpilotInvite implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public array $backoff = [60, 120, 300];

    public function __construct(
        private int $userId,
        private string $locale = 'en'
    ) {
        $this->onQueue('default');
    }

    public function handle(): void
    {
        $afsEmail = config('services.trustpilot.afs_email');

        if (! $afsEmail) {
            return;
        }

        $user = User::find($this->userId);

        if (! $user || $user->email_bounced_at) {
            return;
        }

        $brand = config('app.name', 'BaseWebsite');

        $subject = $this->locale === 'nl'
            ? "Hoe was uw ervaring met {$brand}?"
            : "How was your experience with {$brand}?";

        $textBody = $this->locale === 'nl'
            ? "Bedankt voor uw gebruik van {$brand}!\n\nWe hopen dat u tevreden bent met onze service. Binnenkort ontvangt u een uitnodiging om een review achter te laten. We stellen uw feedback zeer op prijs.\n\nHet {$brand}-team"
            : "Thank you for using {$brand}!\n\nWe hope you're happy with our service. You'll soon receive an invitation to leave a review. We really appreciate your feedback.\n\nThe {$brand} team";

        Http::withHeaders([
            'Accept' => 'application/json',
            'X-Postmark-Server-Token' => config('services.postmark.token'),
        ])->asJson()->post('https://api.postmarkapp.com/email', [
            'From' => config('mail.from.address'),
            'To' => $user->email,
            'Bcc' => $afsEmail,
            'Subject' => $subject,
            'TextBody' => $textBody,
            'MessageStream' => 'outbound',
            'Tag' => 'trustpilot-invite',
        ])->throw();

        Log::info('Trustpilot invite sent', [
            'user_id' => $this->userId,
            'customer_email' => $user->email,
        ]);

        AnalyticsService::log('email_sent', [
            'type' => 'trustpilot-invite',
            'recipient' => $user->email,
            'tag' => 'trustpilot-invite',
        ]);
    }
}
