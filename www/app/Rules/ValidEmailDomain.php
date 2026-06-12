<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class ValidEmailDomain implements ValidationRule
{
    private int $timeoutSeconds = 2;

    private array $knownDomains = [
        'gmail.com', 'googlemail.com', 'outlook.com', 'hotmail.com',
        'live.com', 'yahoo.com', 'icloud.com', 'protonmail.com',
    ];

    private array $typoCorrections = [
        'gamil.com' => 'gmail.com',
        'gnail.com' => 'gmail.com',
        'gmail.con' => 'gmail.com',
        'gmail.cm' => 'gmail.com',
        'hotmail.con' => 'hotmail.com',
        'yahoo.con' => 'yahoo.com',
        'outlook.con' => 'outlook.com',
    ];

    private array $blockedUsernames = [
        'no', 'noreply', 'no-reply', 'test', 'testing', 'admin', 'administrator',
        'info', 'support', 'hello', 'contact', 'sales', 'asdf', 'qwerty',
        'aaa', 'bbb', 'xxx', 'zzz', '123', '1234', '12345', 'abc', 'abcd',
        'user', 'demo', 'sample', 'example', 'fake', 'temp', 'temporary',
        'null', 'void', 'none', 'nobody', 'anonymous', 'anon',
    ];

    private array $blockedDomains = [
        // Fake/placeholder domains
        'noreply.com', 'example.com', 'example.org', 'example.net',
        'test.com', 'test.org', 'localhost', 'localht.com', 'invalid.com', 'fake.com',
        // Disposable email services
        'mailinator.com', 'guerrillamail.com', 'guerrillamail.org',
        'tempmail.com', 'tempmail.org', 'temp-mail.org',
        'throwaway.email', 'throwawaymail.com', 'fakeinbox.com',
        'sharklasers.com', 'grr.la', 'guerrillamail.info', 'pokemail.net', 'spam4.me',
        'yopmail.com', 'yopmail.fr', 'cool.fr.nf', 'jetable.fr.nf',
        '10minutemail.com', '10minutemail.net', '10minmail.com',
        'tempinbox.com', 'tempr.email', 'discard.email', 'discardmail.com',
        'disposablemail.com', 'disposable.com', 'getairmail.com', 'getnada.com',
        'mailnesia.com', 'maildrop.cc', 'mintemail.com', 'mohmal.com',
        'spamgourmet.com', 'trashmail.com', 'trashmail.net',
        'wegwerfmail.de', 'wegwerfmail.net', 'emailondeck.com',
        'anonymbox.com', 'tempail.com', 'burnermail.io', 'mailcatch.com', 'inboxalias.com',
    ];

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || empty($value)) {
            return;
        }

        $parts = explode('@', $value);
        if (count($parts) !== 2) {
            return;
        }

        [$username, $domain] = $parts;
        $domain = strtolower(trim($domain));
        $username = strtolower(trim($username));

        // 1. Check blocked usernames
        if (in_array($username, $this->blockedUsernames)) {
            $fail(__('validation.email_username_not_allowed'));

            return;
        }

        // 2. Check blocked domains
        if (in_array($domain, $this->blockedDomains)) {
            $fail(__('validation.email_domain_not_allowed'));

            return;
        }

        // 3. Typo check
        if (isset($this->typoCorrections[$domain])) {
            $suggestion = $this->typoCorrections[$domain];
            $fail(__('validation.email_domain_typo', [
                'suggestion' => "{$username}@{$suggestion}",
            ]));

            return;
        }

        // 4. Gmail username length
        if ($domain === 'gmail.com' && strlen($username) < 6) {
            $fail(__('validation.gmail_username_too_short'));

            return;
        }

        // 5. Skip MX voor bekende domeinen
        if (in_array($domain, $this->knownDomains)) {
            return;
        }

        // 6. MX record check
        if (! $this->hasMxRecords($domain)) {
            $fail(__('validation.email_domain_no_mx'));

            return;
        }
    }

    private function hasMxRecords(string $domain): bool
    {
        if (! preg_match('/^[a-z0-9][a-z0-9\-\.]*[a-z0-9]$/i', $domain)) {
            return false;
        }

        // Probeer dig met timeout
        $result = $this->checkMxWithDig($domain);
        if ($result !== null) {
            return $result;
        }

        // Fallback naar PHP native
        return @checkdnsrr($domain, 'MX') || @checkdnsrr($domain, 'A');
    }

    private function checkMxWithDig(string $domain): ?bool
    {
        $domain = escapeshellarg($domain);
        $timeout = $this->timeoutSeconds;

        if (PHP_OS_FAMILY === 'Darwin') {
            $cmd = "dig +short +time={$timeout} +tries=1 MX {$domain} 2>/dev/null";
        } else {
            $cmd = "timeout {$timeout}s dig +short +tries=1 MX {$domain} 2>/dev/null";
        }

        exec($cmd, $output, $returnCode);
        $result = implode("\n", $output);

        if (! empty(trim($result)) && preg_match('/^\d+\s+\S+/m', $result)) {
            return true;
        }

        if ($returnCode === 124) { // timeout
            return true; // Fail open
        }

        return $returnCode === 0 ? false : null;
    }
}
