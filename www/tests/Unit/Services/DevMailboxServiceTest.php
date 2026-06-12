<?php

use App\Services\DevMailboxService;
use Illuminate\Support\Facades\Cache;

beforeEach(function () {
    Cache::flush();
    $this->service = new DevMailboxService;
});

describe('store', function () {
    it('stores an email and returns an email id', function () {
        $emailId = $this->service->store(
            to: 'test@example.com',
            subject: 'Test Subject',
            data: ['body' => 'Test body content']
        );

        expect($emailId)->toBeString();
        expect(strlen($emailId))->toBe(36); // UUID format
    });

    it('stores email with all required fields', function () {
        $emailId = $this->service->store(
            to: 'recipient@example.com',
            subject: 'Important Email',
            data: ['template' => 'welcome', 'name' => 'John']
        );

        $email = $this->service->get($emailId);

        expect($email)->not->toBeNull();
        expect($email['to'])->toBe('recipient@example.com');
        expect($email['subject'])->toBe('Important Email');
        expect($email['data'])->toBe(['template' => 'welcome', 'name' => 'John']);
        expect($email['timestamp'])->not->toBeNull();
        expect($email['sensitive'])->toBeFalse();
    });

    it('marks emails as sensitive when specified', function () {
        $emailId = $this->service->store(
            to: 'user@example.com',
            subject: 'Password Reset',
            data: ['reset_url' => 'https://example.com/reset/token123'],
            sensitive: true
        );

        $email = $this->service->get($emailId);

        expect($email['sensitive'])->toBeTrue();
    });

    it('limits stored emails to maximum count', function () {
        // Store more than MAX_EMAILS (50)
        for ($i = 0; $i < 55; $i++) {
            $this->service->store(
                to: "user{$i}@example.com",
                subject: "Email {$i}",
                data: ['index' => $i]
            );
        }

        $allEmails = $this->service->all();

        expect(count($allEmails))->toBeLessThanOrEqual(50);
    });
});

describe('get', function () {
    it('retrieves stored email by id', function () {
        $emailId = $this->service->store(
            to: 'test@example.com',
            subject: 'Test',
            data: []
        );

        $email = $this->service->get($emailId);

        expect($email)->not->toBeNull();
        expect($email['id'])->toBe($emailId);
    });

    it('returns null for non-existent email id', function () {
        $email = $this->service->get('non-existent-id');

        expect($email)->toBeNull();
    });
});

describe('all', function () {
    it('returns empty array when no emails stored', function () {
        $emails = $this->service->all();

        expect($emails)->toBe([]);
    });

    it('returns all stored emails', function () {
        $this->service->store('a@example.com', 'Email A', []);
        $this->service->store('b@example.com', 'Email B', []);
        $this->service->store('c@example.com', 'Email C', []);

        $emails = $this->service->all();

        expect(count($emails))->toBe(3);
    });

    it('returns emails sorted by newest first', function () {
        $this->service->store('first@example.com', 'First', []);
        sleep(1); // Ensure different timestamps
        $this->service->store('second@example.com', 'Second', []);

        $emails = $this->service->all();

        expect($emails[0]['to'])->toBe('second@example.com');
        expect($emails[1]['to'])->toBe('first@example.com');
    });
});

describe('count', function () {
    it('returns zero when no emails stored', function () {
        expect($this->service->count())->toBe(0);
    });

    it('returns correct count of stored emails', function () {
        $this->service->store('a@example.com', 'A', []);
        $this->service->store('b@example.com', 'B', []);

        expect($this->service->count())->toBe(2);
    });
});

describe('clear', function () {
    it('removes all stored emails', function () {
        $this->service->store('a@example.com', 'A', []);
        $this->service->store('b@example.com', 'B', []);

        $cleared = $this->service->clear();

        expect($cleared)->toBe(2);
        expect($this->service->count())->toBe(0);
        expect($this->service->all())->toBe([]);
    });

    it('returns zero when clearing empty mailbox', function () {
        $cleared = $this->service->clear();

        expect($cleared)->toBe(0);
    });
});

describe('isEnabled', function () {
    it('returns false in production environment', function () {
        config(['app.env' => 'production']);

        expect(DevMailboxService::isEnabled())->toBeFalse();
    });

    it('returns true in local environment by default', function () {
        config(['app.env' => 'local']);
        config(['mail.send_real_emails' => false]);
        session()->forget('dev_force_real_email');

        expect(DevMailboxService::isEnabled())->toBeTrue();
    });

    it('returns false when send_real_emails config is true', function () {
        config(['app.env' => 'local']);
        config(['mail.send_real_emails' => true]);

        expect(DevMailboxService::isEnabled())->toBeFalse();
    });

    it('returns false when session force flag is set', function () {
        config(['app.env' => 'local']);
        config(['mail.send_real_emails' => false]);
        session(['dev_force_real_email' => true]);

        expect(DevMailboxService::isEnabled())->toBeFalse();
    });
});
