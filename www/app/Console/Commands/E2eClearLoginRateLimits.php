<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\RateLimiter;

class E2eClearLoginRateLimits extends Command
{
    protected $signature = 'e2e:clear-login-rate-limits {email}';

    protected $description = 'Clear login-code rate-limit buckets for an email (local/testing only).';

    public function handle(): int
    {
        if (! app()->environment(['local', 'testing'])) {
            $this->error('Alleen beschikbaar in local/testing.');
            return self::FAILURE;
        }

        $email = strtolower(trim($this->argument('email')));

        RateLimiter::clear('login-code-send:'.$email);
        RateLimiter::clear('login-code-verify:'.$email);
        RateLimiter::clear('login-code-send-ip:127.0.0.1');
        RateLimiter::clear('login-code-verify-ip:127.0.0.1');

        $this->info("Cleared rate limits for {$email}.");
        return self::SUCCESS;
    }
}
