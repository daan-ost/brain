<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Inbound Email Language Lines
    |--------------------------------------------------------------------------
    |
    | The following language lines are used for the inbound email preferences
    | and management interface.
    |
    */

    'title' => 'Inbound Email',
    'description' => 'Receive and process emails sent to your unique email addresses.',

    'feature_disabled' => 'Inbound email is currently disabled. Please contact support for more information.',

    'enable_inbound' => 'Enable Inbound Email',
    'enable_description' => 'Activate inbound email processing to receive emails at your unique addresses.',
    'enable_inbound_description' => 'You can send emails to :domain and they will be processed automatically.',

    'enabled_successfully' => 'Inbound email has been enabled successfully.',
    'disabled_successfully' => 'Inbound email has been disabled.',

    'your_email_addresses' => 'Your Email Addresses',
    'copy' => 'Copy',
    'copied' => 'Copied!',
    'copied_to_clipboard' => 'Email address copied to clipboard.',

    'advanced_options' => 'Advanced Options',

    'verify_sender' => 'Verify Sender',
    'verify_sender_description' => 'Only accept emails from your registered email address. Recommended for security.',
    'verify_sender_warning' => 'Disabling sender verification allows anyone with your unique email address to send emails. This may be a security risk if your email address is shared.',
    'verify_sender_updated' => 'Sender verification setting updated.',
    'security_warning' => 'Warning',

    'admin_setup_required' => 'Administrator Setup Required',
    'admin_setup_description' => 'Your website administrator must configure the Postmark inbound webhook for this feature to work properly.',
    'learn_more' => 'Learn more about Postmark setup',

    // History table
    'recent_emails' => 'Recent Inbound Emails',
    'date' => 'Date',
    'subject' => 'Subject',
    'action' => 'Action',
    'status' => 'Status',
    'status_processed' => 'Processed',
    'status_processing' => 'Processing',
    'status_received' => 'Received',
    'status_failed' => 'Failed',
    'status_bounced' => 'Bounced',
    'status_virus_detected' => 'Virus detected',
    'days_remaining' => '{0} Expired|{1} 1 day left|[2,*] :days days left',
];
