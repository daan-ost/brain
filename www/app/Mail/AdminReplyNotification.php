<?php

namespace App\Mail;

use App\Models\MessageThread;
use App\Models\ThreadMessage;
use App\Services\DevMailboxService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class AdminReplyNotification extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    /**
     * Create a new message instance.
     */
    public function __construct(
        private MessageThread $thread,
        private ThreadMessage $adminMessage
    ) {
        $this->onQueue('default');

        Log::info('AdminReplyNotification mail created', [
            'thread_id' => $this->thread->id,
            'message_id' => $this->adminMessage->id,
            'user_id' => $this->thread->user_id,
            'category' => $this->thread->category?->name,
        ]);
    }

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        $locale = app()->getLocale();
        $subject = $locale === 'nl'
            ? 'Reactie op je bericht - ' . config('app.name')
            : 'Reply to your message - ' . config('app.name');

        return new Envelope(
            subject: $subject,
        );
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        // Empty content - Postmark will override with template
        return new Content(
            htmlString: ' '
        );
    }

    /**
     * Build the message with Postmark template headers
     */
    public function build()
    {
        $locale = app()->getLocale();
        $templateAlias = "admin-reply-notification__{$locale}";

        // Prepare template variables
        $templateModel = [
            'user_name' => $this->thread->user->name ?? 'User',
            'thread_title' => $this->thread->title,
            'admin_message_preview' => Str::limit($this->adminMessage->content, 200, '...'),
            'conversation_url' => $this->getConversationUrl(),
            'category_name' => $this->thread->category?->name ?? ($locale === 'nl' ? 'Algemeen' : 'General'),
        ];

        Log::info('AdminReplyNotification build', [
            'template_alias' => $templateAlias,
            'template_model' => $templateModel,
            'dev_mailbox_enabled' => DevMailboxService::isEnabled(),
        ]);

        // Check if dev mailbox is enabled (development mode with mocking)
        if (DevMailboxService::isEnabled()) {
            $this->handleDevMailbox($templateAlias, $templateModel);

            return $this;
        }

        // Set Postmark template headers (production mode)
        $this->withSymfonyMessage(function ($message) use ($templateAlias, $templateModel) {
            $headers = $message->getHeaders();

            $headers->addTextHeader('X-PM-TemplateAlias', $templateAlias);
            $headers->addTextHeader('X-PM-TemplateModel', json_encode($templateModel));
            $headers->addTextHeader('X-PM-Tag', 'admin-reply-notification');
            $headers->addTextHeader('X-PM-MessageStream', 'outbound');

            Log::info('AdminReplyNotification headers set', [
                'X-PM-TemplateAlias' => $templateAlias,
                'X-PM-Tag' => 'admin-reply-notification',
            ]);
        });

        return $this;
    }

    /**
     * Handle email in development mode (store in dev mailbox)
     */
    private function handleDevMailbox(string $templateAlias, array $templateModel): void
    {
        $mailbox = app(DevMailboxService::class);

        // Build email data for dev mailbox
        $locale = app()->getLocale();
        $subject = $locale === 'nl'
            ? 'Reactie op je bericht - ' . config('app.name')
            : 'Reply to your message - ' . config('app.name');

        $previewText = $locale === 'nl'
            ? "We hebben gereageerd op je bericht in {$templateModel['category_name']}"
            : "We've replied to your message in {$templateModel['category_name']}";

        $emailData = [
            'template_alias' => $templateAlias,
            'template_model' => $templateModel,
            'to' => $this->thread->user->email,
            'subject' => $subject,
            'preview_text' => $previewText,
            'thread_id' => $this->thread->id,
            'message_id' => $this->adminMessage->id,
        ];

        // Store in dev mailbox
        $emailId = $mailbox->store(
            to: $this->thread->user->email,
            subject: $subject,
            data: $emailData,
            sensitive: false
        );

        Log::info('Email stored in dev mailbox', [
            'email_id' => $emailId,
            'to' => $this->thread->user->email,
            'template' => $templateAlias,
        ]);
    }

    /**
     * Get the conversation URL
     */
    private function getConversationUrl(): string
    {
        return route('profile.messages.show', $this->thread->uuid);
    }

    /**
     * Get the attachments for the message.
     *
     * @return array<int, \Illuminate\Mail\Mailables\Attachment>
     */
    public function attachments(): array
    {
        return [];
    }
}
