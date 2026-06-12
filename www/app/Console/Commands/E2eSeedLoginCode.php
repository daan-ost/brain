<?php

namespace App\Console\Commands;

use App\Models\LoginCode;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;

/**
 * E2E test helper: overschrijft de laatste login_code voor een email met
 * een bekende plaintext waarde (gehashed via bcrypt). Hierdoor kan
 * Playwright deterministische code-login tests doen zonder echte mail.
 *
 * Disabled buiten local/testing om te voorkomen dat dit per ongeluk in
 * productie loopt.
 */
class E2eSeedLoginCode extends Command
{
    protected $signature = 'e2e:seed-login-code {email} {code}';

    protected $description = 'Seed a known login code for E2E tests (local/testing only).';

    public function handle(): int
    {
        if (! app()->environment(['local', 'testing'])) {
            $this->error('e2e:seed-login-code is alleen beschikbaar in local/testing.');
            return self::FAILURE;
        }

        $email = strtolower(trim($this->argument('email')));
        $code = $this->argument('code');

        // Invalideer eerdere unused codes
        LoginCode::where('email', $email)
            ->whereNull('used_at')
            ->update(['used_at' => now()]);

        $row = LoginCode::create([
            'email'      => $email,
            'code'       => Hash::make($code),
            'expires_at' => now()->addMinutes(15),
        ]);

        $this->info("Seeded login_code id={$row->id} for {$email} with code {$code}.");
        return self::SUCCESS;
    }
}
