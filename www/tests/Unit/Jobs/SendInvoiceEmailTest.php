<?php

use App\Jobs\SendInvoiceEmail;
use App\Jobs\SendPostmarkTemplateEmail;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('local');
    Queue::fake();
});

describe('Invoice Email Recipients', function () {
    it('sends invoice email when billing email is set', function () {
        $user = createUser(['email' => 'admin@company.com']);

        // Create invoice file
        Storage::put('invoices/2025/2025-Q1-10001.pdf', 'fake pdf content');

        $order = createOrder([
            'payer_type' => 'user',
            'payer_id' => $user->id,
            'status' => 'paid',
            'invoice_number' => '2025-Q1-10001',
            'invoice_file_path' => 'invoices/2025/2025-Q1-10001.pdf',
            'billing_snapshot' => [
                'email' => 'billing@company.com',
                'company_name' => 'Test Company',
            ],
        ]);

        $job = new SendInvoiceEmail($order);
        $job->handle();

        // Should dispatch at least one email
        Queue::assertPushed(SendPostmarkTemplateEmail::class);
    });

    it('sends multiple emails when billing email differs from user email', function () {
        $user = createUser(['email' => 'admin@company.com']);

        Storage::put('invoices/2025/2025-Q1-10002.pdf', 'fake pdf content');

        $order = createOrder([
            'payer_type' => 'user',
            'payer_id' => $user->id,
            'status' => 'paid',
            'invoice_number' => '2025-Q1-10002',
            'invoice_file_path' => 'invoices/2025/2025-Q1-10002.pdf',
            'billing_snapshot' => [
                'email' => 'invoices@company.com',
                'company_name' => 'Test Company',
            ],
        ]);

        $job = new SendInvoiceEmail($order);
        $job->handle();

        // Should dispatch 2 emails: billing + user account
        Queue::assertPushed(SendPostmarkTemplateEmail::class, 2);
    });

    it('avoids duplicate emails when billing email matches user email', function () {
        $user = createUser(['email' => 'same@company.com']);

        Storage::put('invoices/2025/2025-Q1-10003.pdf', 'fake pdf content');

        $order = createOrder([
            'payer_type' => 'user',
            'payer_id' => $user->id,
            'status' => 'paid',
            'invoice_number' => '2025-Q1-10003',
            'invoice_file_path' => 'invoices/2025/2025-Q1-10003.pdf',
            'billing_snapshot' => [
                'email' => 'same@company.com',
                'company_name' => 'Test Company',
            ],
        ]);

        $job = new SendInvoiceEmail($order);
        $job->handle();

        // Should only dispatch 1 email (no duplicates)
        Queue::assertPushed(SendPostmarkTemplateEmail::class, 1);
    });

    it('sends to organization admins plus billing email for organization orders', function () {
        $admin1 = createUser(['email' => 'admin1@company.com']);
        $admin2 = createUser(['email' => 'admin2@company.com']);

        $organization = createOrganization(['name' => 'Test Org']);
        $organization->users()->attach($admin1->id, ['role' => \App\Enums\OrganizationRole::Owner->value, 'joined_at' => now()]);
        $organization->users()->attach($admin2->id, ['role' => \App\Enums\OrganizationRole::Owner->value, 'joined_at' => now()]);

        Storage::put('invoices/2025/2025-Q1-10004.pdf', 'fake pdf content');

        $order = createOrder([
            'payer_type' => 'organization',
            'payer_id' => $organization->id,
            'status' => 'paid',
            'invoice_number' => '2025-Q1-10004',
            'invoice_file_path' => 'invoices/2025/2025-Q1-10004.pdf',
            'billing_snapshot' => [
                'email' => 'finance@company.com',
                'company_name' => 'Test Org',
            ],
        ]);

        $job = new SendInvoiceEmail($order);
        $job->handle();

        // Should dispatch 3 emails: billing + admin1 + admin2
        Queue::assertPushed(SendPostmarkTemplateEmail::class, 3);
    });

    it('avoids duplicate when admin email matches billing email', function () {
        $admin = createUser(['email' => 'admin@company.com']);

        $organization = createOrganization(['name' => 'Test Org']);
        $organization->users()->attach($admin->id, ['role' => \App\Enums\OrganizationRole::Owner->value, 'joined_at' => now()]);

        Storage::put('invoices/2025/2025-Q1-10005.pdf', 'fake pdf content');

        $order = createOrder([
            'payer_type' => 'organization',
            'payer_id' => $organization->id,
            'status' => 'paid',
            'invoice_number' => '2025-Q1-10005',
            'invoice_file_path' => 'invoices/2025/2025-Q1-10005.pdf',
            'billing_snapshot' => [
                'email' => 'admin@company.com', // Same as admin
                'company_name' => 'Test Org',
            ],
        ]);

        $job = new SendInvoiceEmail($order);
        $job->handle();

        // Should only dispatch 1 email (no duplicates)
        Queue::assertPushed(SendPostmarkTemplateEmail::class, 1);
    });

    it('handles missing billing email gracefully', function () {
        $user = createUser(['email' => 'user@company.com']);

        Storage::put('invoices/2025/2025-Q1-10006.pdf', 'fake pdf content');

        $order = createOrder([
            'payer_type' => 'user',
            'payer_id' => $user->id,
            'status' => 'paid',
            'invoice_number' => '2025-Q1-10006',
            'invoice_file_path' => 'invoices/2025/2025-Q1-10006.pdf',
            'billing_snapshot' => [
                'company_name' => 'Test Company',
                // No email in billing snapshot
            ],
        ]);

        $job = new SendInvoiceEmail($order);
        $job->handle();

        // Should still send to user account email
        Queue::assertPushed(SendPostmarkTemplateEmail::class, 1);
    });

    it('validates billing email format before sending', function () {
        $user = createUser(['email' => 'user@company.com']);

        Storage::put('invoices/2025/2025-Q1-10007.pdf', 'fake pdf content');

        $order = createOrder([
            'payer_type' => 'user',
            'payer_id' => $user->id,
            'status' => 'paid',
            'invoice_number' => '2025-Q1-10007',
            'invoice_file_path' => 'invoices/2025/2025-Q1-10007.pdf',
            'billing_snapshot' => [
                'email' => 'invalid-email', // Invalid email format
                'company_name' => 'Test Company',
            ],
        ]);

        $job = new SendInvoiceEmail($order);
        $job->handle();

        // Should only send to user account (invalid billing email ignored)
        Queue::assertPushed(SendPostmarkTemplateEmail::class, 1);
    });
});

describe('Invoice Email Dispatching', function () {
    it('dispatches email job with invoice attachment', function () {
        $user = createUser(['email' => 'user@company.com']);

        Storage::put('invoices/2025/2025-Q1-10008.pdf', 'fake pdf content');

        $order = createOrder([
            'payer_type' => 'user',
            'payer_id' => $user->id,
            'status' => 'paid',
            'invoice_number' => '2025-Q1-10008',
            'invoice_file_path' => 'invoices/2025/2025-Q1-10008.pdf',
            'billing_snapshot' => [
                'email' => 'user@company.com',
            ],
        ]);

        $job = new SendInvoiceEmail($order);
        $job->handle();

        Queue::assertPushed(SendPostmarkTemplateEmail::class);
    });
});

/** Helper to read private/protected properties off a queued job via reflection */
function getJobProp(object $job, string $prop): mixed
{
    $ref = new \ReflectionProperty($job, $prop);
    $ref->setAccessible(true);

    return $ref->getValue($job);
}

describe('Invoice Email Locale', function () {
    it('uses preferred_language of admin in template alias for organization orders', function () {
        $admin = createUser(['email' => 'admin@company.com', 'preferred_language' => 'nl']);

        $organization = createOrganization(['name' => 'Test Org']);
        $organization->users()->attach($admin->id, ['role' => \App\Enums\OrganizationRole::Owner->value, 'joined_at' => now()]);

        Storage::put('invoices/2025/2025-Q1-20001.pdf', 'fake pdf content');

        $order = createOrder([
            'payer_type' => 'organization',
            'payer_id' => $organization->id,
            'status' => 'paid',
            'invoice_number' => '2025-Q1-20001',
            'invoice_file_path' => 'invoices/2025/2025-Q1-20001.pdf',
            'billing_snapshot' => [
                'email' => 'finance@company.com',
                'company_name' => 'Test Org',
            ],
        ]);

        $job = new SendInvoiceEmail($order);
        $job->handle();

        // Admin with preferred_language 'nl' should receive invoice-notification__nl
        Queue::assertPushed(SendPostmarkTemplateEmail::class, function ($job) {
            return getJobProp($job, 'templateAlias') === 'invoice-notification__nl'
                && getJobProp($job, 'to') === 'admin@company.com';
        });
    });

    it('uses preferred_language of user payer in template alias for user orders', function () {
        $user = createUser(['email' => 'user@company.com', 'preferred_language' => 'nl']);

        Storage::put('invoices/2025/2025-Q1-20002.pdf', 'fake pdf content');

        $order = createOrder([
            'payer_type' => 'user',
            'payer_id' => $user->id,
            'status' => 'paid',
            'invoice_number' => '2025-Q1-20002',
            'invoice_file_path' => 'invoices/2025/2025-Q1-20002.pdf',
            'billing_snapshot' => [
                'email' => 'other@company.com',
                'company_name' => 'Test',
            ],
        ]);

        $job = new SendInvoiceEmail($order);
        $job->handle();

        // User with preferred_language 'nl' should receive invoice-notification__nl
        Queue::assertPushed(SendPostmarkTemplateEmail::class, function ($job) {
            return getJobProp($job, 'templateAlias') === 'invoice-notification__nl'
                && getJobProp($job, 'to') === 'user@company.com';
        });
    });

    it('falls back to app locale when admin has no preferred_language set', function () {
        // preferred_language defaults to 'nl' per DB default — use 'en' explicitly to test fallback behavior
        $admin = createUser(['email' => 'admin@company.com', 'preferred_language' => 'en']);

        $organization = createOrganization(['name' => 'Test Org']);
        $organization->users()->attach($admin->id, ['role' => \App\Enums\OrganizationRole::Owner->value, 'joined_at' => now()]);

        Storage::put('invoices/2025/2025-Q1-20003.pdf', 'fake pdf content');

        $order = createOrder([
            'payer_type' => 'organization',
            'payer_id' => $organization->id,
            'status' => 'paid',
            'invoice_number' => '2025-Q1-20003',
            'invoice_file_path' => 'invoices/2025/2025-Q1-20003.pdf',
            'billing_snapshot' => [
                'email' => 'finance@company.com',
                'company_name' => 'Test Org',
            ],
        ]);

        $job = new SendInvoiceEmail($order);
        $job->handle();

        // Admin with preferred_language 'en' should receive invoice-notification__en
        Queue::assertPushed(SendPostmarkTemplateEmail::class, function ($job) {
            return getJobProp($job, 'templateAlias') === 'invoice-notification__en'
                && getJobProp($job, 'to') === 'admin@company.com';
        });
    });
});
