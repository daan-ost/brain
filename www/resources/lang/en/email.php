<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Email Conversion Language Lines (English)
    |--------------------------------------------------------------------------
    |
    | Translation keys for email-to-PDF conversion features
    |
    */

    // Mail Parts Selection UI
    'mail_parts_title' => 'What to convert',
    'mail_part_both' => 'Email Body + Attachments',
    'mail_part_both_desc' => 'Complete email with all attachments (default)',
    'mail_part_body' => 'Email Body Only',
    'mail_part_body_desc' => 'Only the email message, no attachments',
    'mail_part_attachments' => 'Attachments Only',
    'mail_part_attachments_desc' => 'Only attachments, skip email body',

    // Conversion status
    'converting_email' => 'Converting email to PDF...',
    'analyzing_attachments' => 'Analyzing email attachments...',

    // Error messages
    'no_email_body' => 'Email body could not be converted',
    'no_attachments' => 'No attachments found in email',
    'unsupported_attachment' => 'Some attachments could not be converted',

];
