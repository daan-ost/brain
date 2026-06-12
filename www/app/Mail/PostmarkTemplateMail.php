<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class PostmarkTemplateMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        private string $templateAlias,
        private array $templateModel = [],
        public $subject = null,
        private ?string $tag = null,
        private ?string $messageStream = null
    ) {
        $this->onQueue('default');

        Log::info('PostmarkTemplateMail created', [
            'templateAlias' => $this->templateAlias,
            'subject' => $this->subject,
            'tag' => $this->tag,
            'messageStream' => $this->messageStream,
            'templateModel' => $this->templateModel,
        ]);
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: $this->subject ?? 'Email from PDF Engine',
        );
    }

    public function content(): Content
    {
        // Empty content for template emails - Postmark will override
        return new Content(
            htmlString: ' '
        );
    }

    public function build()
    {
        Log::info('PostmarkTemplateMail build method called', [
            'templateAlias' => $this->templateAlias,
            'subject' => $this->subject,
        ]);

        // Use Postmark template headers
        $this->withSymfonyMessage(function ($message) {
            $headers = $message->getHeaders();

            // Set required Postmark template headers
            $headers->addTextHeader('X-PM-TemplateAlias', $this->templateAlias);
            $headers->addTextHeader('X-PM-TemplateModel', json_encode($this->templateModel));

            // Optional headers
            if ($this->tag) {
                $headers->addTextHeader('X-PM-Tag', $this->tag);
            }

            if ($this->messageStream) {
                $headers->addTextHeader('X-PM-MessageStream', $this->messageStream);
            }

            // Keep minimal content - Postmark should override with template

            Log::info('PostmarkTemplateMail headers set', [
                'X-PM-TemplateAlias' => $this->templateAlias,
                'X-PM-TemplateModel' => json_encode($this->templateModel),
                'X-PM-Tag' => $this->tag,
                'X-PM-MessageStream' => $this->messageStream,
            ]);
        });

        return $this;
    }

    public function attachments(): array
    {
        return [];
    }
}
