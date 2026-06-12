<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Development Mailbox Service
 *
 * Captures emails in development mode instead of sending them.
 * Stores emails in cache for viewing via /dev/mailbox.
 *
 * ONLY for development/testing - NEVER use in production.
 */
class DevMailboxService
{
    /**
     * Cache key prefix for mailbox items
     */
    private const CACHE_PREFIX = 'dev_mailbox:';

    /**
     * Cache key for mailbox index (list of email IDs)
     */
    private const INDEX_KEY = 'dev_mailbox:index';

    /**
     * Default retention time for emails (24 hours)
     */
    private const DEFAULT_TTL = 86400; // 24 hours

    /**
     * Retention time for sensitive data (1 hour)
     */
    private const SENSITIVE_TTL = 3600; // 1 hour

    /**
     * Maximum number of emails to keep
     */
    private const MAX_EMAILS = 50;

    /**
     * Store an email in the dev mailbox
     *
     * @param  string  $to  Recipient email address
     * @param  string  $subject  Email subject
     * @param  array  $data  Email data (body, links, etc.)
     * @param  bool  $sensitive  Whether this contains sensitive data (tokens, etc.)
     * @return string Email ID
     */
    public function store(string $to, string $subject, array $data, bool $sensitive = false): string
    {
        $emailId = Str::uuid()->toString();
        $timestamp = now();

        $email = [
            'id' => $emailId,
            'to' => $to,
            'subject' => $subject,
            'data' => $data,
            'timestamp' => $timestamp->toDateTimeString(),
            'timestamp_unix' => $timestamp->timestamp,
            'sensitive' => $sensitive,
        ];

        // Determine TTL based on sensitivity
        $ttl = $sensitive ? self::SENSITIVE_TTL : self::DEFAULT_TTL;

        // Store the email
        Cache::put(
            self::CACHE_PREFIX.$emailId,
            $email,
            $ttl
        );

        // Update index
        $this->addToIndex($emailId, $ttl);

        return $emailId;
    }

    /**
     * Get all emails from mailbox (newest first)
     */
    public function all(): array
    {
        $index = $this->getIndex();

        $emails = [];
        foreach ($index as $emailId) {
            $email = Cache::get(self::CACHE_PREFIX.$emailId);
            if ($email) {
                $emails[] = $email;
            }
        }

        // Sort by timestamp (newest first)
        usort($emails, function ($a, $b) {
            return $b['timestamp_unix'] <=> $a['timestamp_unix'];
        });

        return $emails;
    }

    /**
     * Get a specific email by ID
     */
    public function get(string $emailId): ?array
    {
        return Cache::get(self::CACHE_PREFIX.$emailId);
    }

    /**
     * Clear all emails from mailbox
     *
     * @return int Number of emails cleared
     */
    public function clear(): int
    {
        $index = $this->getIndex();
        $count = count($index);

        // Delete all emails
        foreach ($index as $emailId) {
            Cache::forget(self::CACHE_PREFIX.$emailId);
        }

        // Clear index
        Cache::forget(self::INDEX_KEY);

        return $count;
    }

    /**
     * Get count of emails in mailbox
     */
    public function count(): int
    {
        return count($this->getIndex());
    }

    /**
     * Add email ID to index
     */
    private function addToIndex(string $emailId, int $ttl): void
    {
        $index = $this->getIndex();

        // Add new email to beginning of array
        array_unshift($index, $emailId);

        // Trim to max size
        if (count($index) > self::MAX_EMAILS) {
            $removed = array_splice($index, self::MAX_EMAILS);

            // Clean up removed emails from cache
            foreach ($removed as $removedId) {
                Cache::forget(self::CACHE_PREFIX.$removedId);
            }
        }

        // Store updated index (use longest TTL)
        Cache::put(self::INDEX_KEY, $index, self::DEFAULT_TTL);
    }

    /**
     * Get mailbox index (list of email IDs)
     */
    private function getIndex(): array
    {
        return Cache::get(self::INDEX_KEY, []);
    }

    /**
     * Check if dev mailbox is enabled
     */
    public static function isEnabled(): bool
    {
        // Never enabled in production
        if (config('app.env') === 'production') {
            return false;
        }

        // Check session override first (from dev dashboard)
        if (session('dev_force_real_email', false)) {
            return false; // Dev mailbox disabled, send real emails
        }

        // Check if real emails should be sent (config)
        return ! config('mail.send_real_emails', false);
    }
}
