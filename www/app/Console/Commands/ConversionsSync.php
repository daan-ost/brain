<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class ConversionsSync extends Command
{
    protected $signature = 'conversions:sync
                            {--all : Sync all targets (header-nav, other-conversions, footer-nav)}
                            {--header-nav : Sync header navigation}
                            {--other-conversions : Sync other conversions}
                            {--footer-nav : Sync footer navigation}
                            {--locale=all : Locale to sync (en, nl, or all)}
                            {--dry-run : Show changes without writing files}
                            {--validate : Validate conversions before syncing}';

    protected $description = 'Master command to sync all conversion configurations';

    public function handle(): int
    {
        $locale = $this->option('locale');
        $dryRun = $this->option('dry-run');
        $validate = $this->option('validate');

        // Validation check
        if ($validate) {
            $this->info('Validating conversions...');
            $exitCode = $this->call('conversions:validate');
            if ($exitCode !== 0) {
                $this->error('Validation failed. Please fix errors before syncing.');

                return 1;
            }
            $this->info('✓ Validation passed');
            $this->newLine();
        }

        $all = $this->option('all');
        $headerNav = $this->option('header-nav');
        $otherConversions = $this->option('other-conversions');
        $footerNav = $this->option('footer-nav');

        // If no specific options, default to all
        if (! $headerNav && ! $otherConversions && ! $footerNav) {
            $all = true;
        }

        $commands = [];

        if ($all || $headerNav) {
            $commands[] = 'conversions:sync-header-nav';
        }

        if ($all || $otherConversions) {
            $commands[] = 'conversions:sync-other-conversions';
        }

        if ($all || $footerNav) {
            $commands[] = 'conversions:sync-footer-nav';
        }

        foreach ($commands as $command) {
            $this->info("Running: {$command}");
            $options = [
                '--locale' => $locale,
            ];
            if ($dryRun) {
                $options['--dry-run'] = true;
            }

            $this->call($command, $options);
            $this->newLine();
        }

        if ($dryRun) {
            $this->warn('DRY RUN MODE - No files were modified');
            $this->info('Run without --dry-run to apply changes');
        } else {
            $this->info('✓ Sync completed successfully');
        }

        return 0;
    }
}
