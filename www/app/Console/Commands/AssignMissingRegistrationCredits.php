<?php

namespace App\Console\Commands;

use App\Models\CreditLedger;
use App\Models\License;
use App\Models\User;
use App\Models\UserLicense;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class AssignMissingRegistrationCredits extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'credits:assign-missing-registration {--dry-run : Show what would be done without making changes}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Assign missing registration credits to users who signed up before the credit assignment was implemented';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $dryRun = $this->option('dry-run');

        if ($dryRun) {
            $this->info('DRY RUN MODE - No changes will be made');
        }

        // Get the free_user license using Eloquent
        $freeUserLicense = License::where('slug', 'free_user')
            ->active()
            ->first();

        if (! $freeUserLicense) {
            $this->error('Free user license not found or inactive');

            return 1;
        }

        $creditsToAssign = (int) $freeUserLicense->credits;
        $this->info("Free user license: {$freeUserLicense->name} ({$creditsToAssign} credits)");

        // Find users who don't have the free_user license assigned
        $usersWithoutCredits = User::whereDoesntHave('userLicenses', function ($query) use ($freeUserLicense) {
            $query->where('license_id', $freeUserLicense->id)
                ->where('source', 'system_signup');
        })->where('credits', 0)->get();

        if ($usersWithoutCredits->isEmpty()) {
            $this->info('No users found missing registration credits');

            return 0;
        }

        $this->info("Found {$usersWithoutCredits->count()} users missing registration credits:");

        $bar = $this->output->createProgressBar($usersWithoutCredits->count());

        $processed = 0;
        $errors = 0;

        foreach ($usersWithoutCredits as $user) {
            try {
                $this->line("Processing: {$user->name} ({$user->email}) - ID: {$user->id}");

                if (! $dryRun) {
                    DB::transaction(function () use ($user, $freeUserLicense, $creditsToAssign) {
                        $timestamp = now();

                        // Create user license record in the new system
                        UserLicense::create([
                            'user_id' => $user->id,
                            'license_id' => $freeUserLicense->id,
                            'status' => 'active',
                            'starts_at' => $timestamp,
                            'ends_at' => null, // No expiration for free user license
                            'source' => 'system_signup',
                            'external_ref' => null,
                            'is_current' => true,
                        ]);

                        // Update user's credits
                        $user->update([
                            'credits' => $creditsToAssign,
                            'credits_updated_at' => $timestamp,
                        ]);

                        // Create ledger entry
                        CreditLedger::create([
                            'user_id' => $user->id,
                            'batch_id' => null,
                            'workflow_id' => null,
                            'delta' => $creditsToAssign,
                            'reason' => 'purchase',
                            'balance_after' => $creditsToAssign,
                            'meta' => [
                                'license_id' => $freeUserLicense->id,
                                'license_slug' => $freeUserLicense->slug,
                                'license_name' => $freeUserLicense->name,
                                'source' => 'system_signup',
                                'registration_bonus' => true,
                                'retroactive_fix' => true,
                            ],
                            'created_at' => $timestamp,
                        ]);
                    });
                }

                $processed++;
                $bar->advance();

            } catch (\Exception $e) {
                $this->error("Failed to process user {$user->id}: ".$e->getMessage());
                $errors++;
                $bar->advance();
            }
        }

        $bar->finish();
        $this->newLine();

        if ($dryRun) {
            $this->info("DRY RUN COMPLETE: Would assign {$creditsToAssign} credits to {$processed} users");
        } else {
            $this->info("COMPLETED: Assigned {$creditsToAssign} credits to {$processed} users");
            if ($errors > 0) {
                $this->warn("Errors encountered: {$errors}");
            }
        }

        return 0;
    }
}
