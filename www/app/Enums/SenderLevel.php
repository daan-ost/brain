<?php

namespace App\Enums;

enum SenderLevel: string
{
    case ReplyTo = 'reply_to';
    case SenderSignature = 'sender_signature';
    case DomainAuth = 'domain_auth';

    public function label(): string
    {
        return match ($this) {
            self::ReplyTo => 'Reply-To',
            self::SenderSignature => 'Sender Signature',
            self::DomainAuth => 'Domain Authentication',
        };
    }

    public function badgeColor(): string
    {
        return match ($this) {
            self::ReplyTo => 'secondary',
            self::SenderSignature => 'warning',
            self::DomainAuth => 'success',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::ReplyTo => 'Emails are sent from the platform address with your email as reply-to.',
            self::SenderSignature => 'Emails are sent from your verified business email address.',
            self::DomainAuth => 'Full DNS authentication for your domain with DKIM and Return-Path.',
        };
    }
}
