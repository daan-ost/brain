<?php

namespace App\Console\Commands;

use App\Enums\OrganizationRole;
use App\Models\Organization;
use App\Models\OrganizationDomain;
use App\Models\User;
use App\Services\AnalyticsService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Retroactively enroll existing users into organizations
 *
 * This command finds all verified users whose email domain matches
 * a validated organization domain with auto-enrollment enabled,
 * and enrolls them into the organization.
 *
 * Use case:
 * - Domain was verified AFTER users already signed up
 * - Admin wants to batch-enroll all existing users
 *
 * Safety:
 * - Dry-run mode by default (--execute flag required)
 * - Skips users already enrolled
 * - Respects blacklist (public domains)
 * - Logs all enrollments
 *
 * Usage:
 *   php artisan users:retroactive-enroll                      # Dry run
 *   php artisan users:retroactive-enroll --execute            # Execute
 *   php artisan users:retroactive-enroll --domain=interus.nl  # Specific domain
 *
 * Related:
 * - Auto-enrollment listener: app/Listeners/AutoEnrollUserInOrganization.php
 * - Documentation: /docs/todo_0_autoenrollment.md
 */
class RetroactivelyEnrollUsers extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'users:retroactive-enroll
                            {--execute : Actually perform the enrollment (default is dry-run)}
                            {--domain= : Only process this specific domain}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Retroactively enroll existing verified users into organizations based on their email domain';

    /**
     * Public domain blacklist (copied from listener)
     */
    private const PUBLIC_DOMAINS = [
        'gmail.com', 'googlemail.com', 'yahoo.com', 'yahoo.co.uk',
        'hotmail.com', 'hotmail.co.uk', 'outlook.com', 'outlook.co.uk',
        'live.com', 'live.co.uk', 'msn.com', 'icloud.com',
        'me.com', 'mac.com', 'protonmail.com', 'protonmail.ch',
        'aol.com', 'mail.com', 'zoho.com', 'yandex.com', 'yandex.ru',
        'gmx.com', 'gmx.de', 'mail.ru',
    ];

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $isDryRun = ! $this->option('execute');
        $specificDomain = $this->option('domain');

        $this->info('═══════════════════════════════════════════════════════');
        $this->info('  Retroactive User Auto-Enrollment');
        $this->info('═══════════════════════════════════════════════════════');
        $this->newLine();

        if ($isDryRun) {
            $this->warn('⚠️  DRY RUN MODE - No changes will be made');
            $this->info('   Use --execute flag to perform actual enrollment');
            $this->newLine();
        }

        // Get all validated domains with auto-enrollment enabled
        $domainsQuery = OrganizationDomain::where('validated', true)
            ->where('auto_enroll_with_verified_domain', true)
            ->where('active', true)
            ->with('organization');

        if ($specificDomain) {
            $domainsQuery->where('domain', $specificDomain);
            $this->info("🔍 Filtering for domain: {$specificDomain}");
            $this->newLine();
        }

        $domains = $domainsQuery->get();

        if ($domains->isEmpty()) {
            $this->warn('No validated domains with auto-enrollment found.');

            return 0;
        }

        $this->info("Found {$domains->count()} eligible domain(s):");
        foreach ($domains as $domain) {
            $this->line("  • {$domain->domain} → {$domain->organization->name}");
        }
        $this->newLine();

        $totalEnrolled = 0;
        $totalSkipped = 0;
        $totalBlacklisted = 0;

        foreach ($domains as $domain) {
            $this->info("Processing domain: {$domain->domain}");

            // Skip blacklisted domains
            if ($this->isPublicDomain($domain->domain)) {
                $this->error('  ⚠️  Skipped (public domain blacklist)');
                $totalBlacklisted++;

                continue;
            }

            // Find all verified users with matching email domain
            $users = User::whereNotNull('email_verified_at')
                ->where('email', 'LIKE', '%@'.$domain->domain)
                ->get();

            $this->line("  Found {$users->count()} verified user(s) with @{$domain->domain}");

            foreach ($users as $user) {
                $organization = $domain->organization;

                // Check if user is already a member
                if ($organization->users()->where('user_id', $user->id)->exists()) {
                    $this->line("    ↳ {$user->email} - Already enrolled (skipped)");
                    $totalSkipped++;

                    continue;
                }

                // Enroll user (if not dry run)
                if (! $isDryRun) {
                    DB::beginTransaction();
                    try {
                        $organization->users()->attach($user->id, [
                            'role' => OrganizationRole::Editor,
                            'joined_at' => now(),
                        ]);

                        // Log analytics event
                        AnalyticsService::log('user_retroactive_enrolled', [
                            'user_id' => $user->id,
                            'organization_id' => $organization->id,
                            'domain' => $domain->domain,
                            'email' => $user->email,
                        ]);

                        DB::commit();
                        $this->line("    ✓ {$user->email} - Enrolled successfully");
                    } catch (\Exception $e) {
                        DB::rollBack();
                        $this->error("    ✗ {$user->email} - Error: ".$e->getMessage());

                        continue;
                    }
                } else {
                    $this->line("    ⊕ {$user->email} - Would be enrolled (dry run)");
                }

                $totalEnrolled++;
            }

            $this->newLine();
        }

        // Summary
        $this->info('═══════════════════════════════════════════════════════');
        $this->info('  Summary');
        $this->info('═══════════════════════════════════════════════════════');
        $this->info("Total enrolled:      {$totalEnrolled}");
        $this->info("Total skipped:       {$totalSkipped}");
        if ($totalBlacklisted > 0) {
            $this->warn("Blacklisted domains: {$totalBlacklisted}");
        }
        $this->newLine();

        if ($isDryRun && $totalEnrolled > 0) {
            $this->warn('This was a DRY RUN. Run with --execute to perform actual enrollment.');
        }

        return 0;
    }

    /**
     * Check if domain is a public domain (blacklisted)
     */
    private function isPublicDomain(string $domain): bool
    {
        return in_array(strtolower($domain), self::PUBLIC_DOMAINS, true);
    }
}
