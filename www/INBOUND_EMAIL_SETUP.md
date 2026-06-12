# Inbound Email Processing System - Setup Guide

This document describes how to set up and configure the Inbound Email Processing System for your Laravel application.

## Overview

The Inbound Email Processing System allows users to send emails to unique addresses that automatically process attachments. Users can enable this feature in their profile settings and get unique email addresses for different actions (merge, convert, etc.).

## Features Implemented

### Core Features
- ✅ User preferences for enabling/disabling inbound email
- ✅ Unique tokenized email addresses per user per action
- ✅ Sender email verification (optional, configurable per user)
- ✅ Webhook endpoint for receiving emails from Postmark
- ✅ Asynchronous email processing via jobs
- ✅ Encryption of sensitive email data (content, attachments)
- ✅ Admin interface for viewing email metadata (content is hidden for security)
- ✅ Rate limiting on webhook endpoint (30 requests/IP/minute)
- ✅ Support for nested emails (email-in-email) up to 1 level deep
- ✅ Virus scanning infrastructure (prepared for ClamAV, not yet active)

### Database Tables
- `inbound_emails` - Stores email metadata and processing status
- `inbound_email_attachments` - Stores attachment metadata and encrypted files
- `inbound_email_rules` - Future: Processing rules (not yet used)
- `user_inbound_email_preferences` - User preferences and unique tokens

## Installation Steps

### 1. Run Database Migrations

```bash
php artisan migrate
```

This will create the following tables:
- `inbound_emails`
- `inbound_email_attachments`
- `inbound_email_rules`
- `user_inbound_email_preferences`

### 2. Configure Environment Variables

Add the following to your `.env` file:

```env
# Enable inbound email feature globally
INBOUND_EMAIL_ENABLED=true

# The inbound email domain (must match your Postmark setup)
INBOUND_EMAIL_DOMAIN=inbound.yourdomain.com

# Postmark webhook authentication token (optional but recommended for production)
POSTMARK_INBOUND_WEBHOOK_TOKEN=your-secret-token-here

# Optional: Configure limits
INBOUND_MAX_ATTACHMENT_SIZE_MB=25
INBOUND_MAX_ATTACHMENTS=20
INBOUND_MAX_EMAIL_SIZE_MB=50
INBOUND_RETENTION_DAYS=90
INBOUND_RATE_LIMIT=30
```

### 3. Configure Postmark Inbound Server

**IMPORTANT**: The website administrator must configure Postmark before this feature will work.

1. Log into your Postmark account
2. Go to **Servers** → Select your server → **Settings** → **Inbound**
3. Create an inbound domain (e.g., `inbound.yourdomain.com`)
4. Add MX records to your DNS as specified by Postmark
5. Configure the webhook URL: `https://yourdomain.com/webhooks/postmark/inbound`
6. Add the webhook authentication token (if using `POSTMARK_INBOUND_WEBHOOK_TOKEN`)
7. Enable webhook signature validation

For detailed instructions, see: https://postmarkapp.com/developer/webhooks/inbound-webhook

### 4. Configure Queue Worker

The inbound email processing runs asynchronously via Laravel queues. Make sure you have a queue worker running:

```bash
php artisan queue:work
```

For production, use Supervisor or similar process manager to keep the queue worker running.

## User Guide

### For End Users

1. Navigate to `/profile/email-preferences`
2. Scroll down to the "Inbound Email" section
3. Click the toggle to enable inbound email processing
4. Your unique email addresses will be displayed (one per action)
5. Copy the email address you want to use
6. Send an email with attachments to that address
7. The system will process your email automatically

### Advanced Options

Users can:
- **Disable sender verification**: By default, only emails from the user's registered email address are accepted. This can be disabled in advanced options, but comes with security warnings.
- **View multiple action addresses**: Each action (merge, convert) has its own unique email address

## Admin Guide

### Viewing Inbound Emails

1. Log into the admin panel at `/beheer`
2. Navigate to **Messages** → **Inbound Emails**
3. View email metadata, processing status, and logs

**Security Note**: For privacy and security, the admin interface only displays:
- Recipient email (to_email)
- Action type
- Processing status
- Timestamps
- Processing notes/errors
- Virus scan status
- Spam score

The following fields are encrypted and NOT visible to admins:
- Sender email (from_email)
- Sender name (from_name)
- Email subject
- Email body (text and HTML)
- Email headers
- Attachment filenames and content

This protects user privacy in case of unauthorized admin access.

### Filtering and Searching

You can filter inbound emails by:
- Status (received, processing, processed, bounced, failed)
- Action type (merge, convert)
- Virus scan status
- Processing notes (has/hasn't notes)
- Failed emails only

## Architecture

### Email Flow

1. User enables inbound email → Unique tokens generated
2. User sends email to `action+token@inbound.domain.com`
3. Postmark receives email → Calls webhook
4. Webhook validates:
   - Rate limiting
   - Webhook signature
   - Token validity
   - User preferences
   - Sender verification (if enabled)
5. Email record created → Job dispatched
6. Job processes:
   - Stores encrypted email data
   - Saves encrypted attachments
   - Handles nested emails
   - Updates processing status
7. Future: Action-specific processors (merge PDFs, convert files, etc.)

### Security Features

- **Encryption**: All email content and attachments encrypted at rest using Laravel's encryption
- **Admin restrictions**: Admins cannot view email content, only metadata
- **Rate limiting**: 30 requests per IP per minute on webhook endpoint
- **Webhook authentication**: Optional signature validation
- **Sender verification**: Optional per-user email sender checking
- **Virus scanning**: Infrastructure ready for ClamAV (not yet active)

### Token System

Each user gets unique tokens per action:
- `merge+abc123xyz@inbound.domain.com` - For merge action
- `convert+def456uvw@inbound.domain.com` - For convert action

Tokens are:
- 12 characters long
- Randomly generated
- Stored in `user_inbound_email_preferences.available_actions` (JSON)
- Unique per user per action

## Testing

### E2E Tests

Run the Playwright E2E tests:

```bash
npx playwright test tests/e2e/inbound-email-preferences.spec.ts
```

The tests verify:
- Page navigation
- Enable/disable toggle
- Email address display
- Copy to clipboard
- Advanced options toggle
- Sender verification toggle

### Manual Testing

1. Enable inbound email in profile settings
2. Copy one of the email addresses
3. Send a test email with attachments to that address
4. Check the admin panel to see the email was received
5. Check the job queue processed successfully

## Troubleshooting

### Emails not being received

1. Check Postmark inbound configuration
2. Verify MX records are set correctly
3. Check webhook URL is accessible
4. Verify `INBOUND_EMAIL_ENABLED=true` in `.env`
5. Check webhook signature token matches

### Emails not processing

1. Check queue worker is running: `php artisan queue:work`
2. Check failed jobs: `php artisan queue:failed`
3. View processing notes in admin panel
4. Check Laravel logs: `storage/logs/laravel.log`

### Rate limiting issues

Adjust the rate limit in `.env`:
```env
INBOUND_RATE_LIMIT=60
```

### Sender verification issues

Users can disable sender verification in advanced options, but warn them about security implications.

## Future Enhancements

The following are prepared but not yet implemented:

1. **Virus Scanning**: ClamAV integration infrastructure is in place
   - Models have `virus_scan_status` fields
   - Admin interface shows virus scan status
   - Need to install ClamAV and implement scanning job

2. **Email Rules**: Database table exists but not yet used
   - Could implement automatic routing based on conditions
   - Action triggers based on email content
   - Notification rules

3. **Action Processors**: Currently emails are just stored
   - Need to implement action-specific processing
   - Example: Merge multiple PDFs into one
   - Example: Convert documents to PDF format

4. **Email-in-Email Processing**: Infrastructure exists but not fully implemented
   - Can detect nested emails
   - Need to implement extraction and processing logic

## Support

For issues or questions:
1. Check the Laravel logs
2. Check Postmark dashboard for delivery issues
3. Review admin panel processing notes
4. Check this documentation

## Version

Version: 1.0.0 (Initial Release)
Date: January 11, 2026
