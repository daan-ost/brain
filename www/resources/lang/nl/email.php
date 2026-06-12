<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Email Conversie Taalregels (Nederlands)
    |--------------------------------------------------------------------------
    |
    | Vertaalsleutels voor email-naar-PDF conversie functies
    |
    */

    // Mail Parts Selectie UI
    'mail_parts_title' => 'Wat te converteren',
    'mail_part_both' => 'Email Body + Bijlagen',
    'mail_part_both_desc' => 'Volledige email met alle bijlagen (standaard)',
    'mail_part_body' => 'Alleen Email Body',
    'mail_part_body_desc' => 'Alleen het emailbericht, geen bijlagen',
    'mail_part_attachments' => 'Alleen Bijlagen',
    'mail_part_attachments_desc' => 'Alleen bijlagen, sla email body over',

    // Conversie status
    'converting_email' => 'Email naar PDF converteren...',
    'analyzing_attachments' => 'Email bijlagen analyseren...',

    // Foutmeldingen
    'no_email_body' => 'Email body kon niet worden geconverteerd',
    'no_attachments' => 'Geen bijlagen gevonden in email',
    'unsupported_attachment' => 'Sommige bijlagen konden niet worden geconverteerd',

];
