<?php

namespace App\Mail;

use GuzzleHttp\Client;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class PostmarkApiMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public $subject;

    public $to;

    public $locale;

    public function __construct(
        private string $templateAlias,
        private array $templateModel = [],
        $subject = null,
        private ?string $tag = null,
        private ?string $messageStream = null,
        $to = null,
        private ?string $toName = null
    ) {
        $this->subject = $subject;
        $this->to = $to;
        $this->onQueue('default');
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: $this->subject ?? 'Email from ' . config('app.name'),
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.postmark-template'
        );
    }

    public function build()
    {
        Log::info('PostmarkApiMail build method called', [
            'templateAlias' => $this->templateAlias,
            'to' => $this->to,
            'subject' => $this->subject,
        ]);

        return $this->view('emails.postmark-template');
    }

    public function __invoke()
    {
        // This will be called when the job is processed
        $this->sendViaPostmarkApi();
    }

    private function sendViaPostmarkApi()
    {
        Log::info('Sending via Postmark API', [
            'templateAlias' => $this->templateAlias,
            'to' => $this->to,
            'subject' => $this->subject,
        ]);

        $client = new Client;

        $payload = [
            'From' => config('mail.from.address'),
            'To' => $this->to,
            'TemplateAlias' => $this->templateAlias,
            'TemplateModel' => $this->templateModel,
        ];

        if ($this->tag) {
            $payload['Tag'] = $this->tag;
        }

        if ($this->messageStream) {
            $payload['MessageStream'] = $this->messageStream;
        }

        try {
            $response = $client->post('https://api.postmarkapp.com/email/withTemplate', [
                'headers' => [
                    'Accept' => 'application/json',
                    'Content-Type' => 'application/json',
                    'X-Postmark-Server-Token' => env('POSTMARK_TOKEN'),
                ],
                'json' => $payload,
            ]);

            $result = json_decode($response->getBody(), true);
            Log::info('Postmark API Response', ['response' => $result]);

            return $result;
        } catch (\Exception $e) {
            Log::error('Postmark API Error', ['error' => $e->getMessage(), 'payload' => $payload]);
            throw $e;
        }
    }

    public function attachments(): array
    {
        return [];
    }
}
