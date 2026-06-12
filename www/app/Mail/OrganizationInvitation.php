<?php

namespace App\Mail;

use App\Models\Invitation;
use App\Services\DevMailboxService;
use App\Services\LocaleService;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class OrganizationInvitation extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    /**
     * Create a new message instance.
     */
    public function __construct(
        private Invitation $invitation
    ) {
        $this->onQueue('default');

        Log::info('OrganizationInvitation mail created', [
            'invitation_id' => $this->invitation->id,
            'organization_id' => $this->invitation->organization_id,
            'email' => $this->invitation->email,
        ]);
    }

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Organization Invitation',
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
        $templateAlias = "organization-invitation__{$locale}";

        // Prepare template variables
        $templateModel = [
            'organization_name' => $this->invitation->organization->name,
            'invited_by_name' => $this->invitation->invitedBy->name,
            'invitation_link' => route('invitations.accept.show', ['token' => $this->invitation->token]),
            'expires_at' => app(LocaleService::class)->formatDate(Carbon::parse($this->invitation->expires_at), $this->invitation->invitedBy),
        ];

        Log::info('OrganizationInvitation build', [
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
            $headers->addTextHeader('X-PM-Tag', 'organization-invitation');
            $headers->addTextHeader('X-PM-MessageStream', 'outbound');

            Log::info('OrganizationInvitation headers set', [
                'X-PM-TemplateAlias' => $templateAlias,
                'X-PM-Tag' => 'organization-invitation',
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
        $subject = $templateAlias === 'organization-invitation__en'
            ? "You've been invited to join {$templateModel['organization_name']}"
            : "Je bent uitgenodigd voor {$templateModel['organization_name']}";

        $emailData = [
            'template_alias' => $templateAlias,
            'template_model' => $templateModel,
            'to' => $this->invitation->email,
            'subject' => $subject,
            'preview_text' => $templateModel['invited_by_name'].' has invited you',
        ];

        // Store in dev mailbox
        $emailId = $mailbox->store(
            to: $this->invitation->email,
            subject: $subject,
            data: $emailData,
            sensitive: true // Contains invitation token
        );

        Log::info('Email stored in dev mailbox', [
            'email_id' => $emailId,
            'to' => $this->invitation->email,
            'template' => $templateAlias,
        ]);
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
