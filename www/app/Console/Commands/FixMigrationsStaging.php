<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

class FixMigrationsStaging extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'fix:migrations-staging';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Fix missing migration records in migrations table for staging';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('Checking migration files against migrations table...');

        $migrationsPath = database_path('migrations');

        if (! File::exists($migrationsPath)) {
            $this->error("Migrations directory not found: {$migrationsPath}");

            return Command::FAILURE;
        }

        // Get all migration files
        $migrationFiles = File::glob($migrationsPath.'/*.php');

        if (empty($migrationFiles)) {
            $this->warn('No migration files found.');

            return Command::SUCCESS;
        }

        $inserted = [];
        $skipped = [];

        foreach ($migrationFiles as $filePath) {
            // Extract migration name (filename without .php)
            $filename = basename($filePath);
            $migrationName = str_replace('.php', '', $filename);

            // Check if migration exists in database
            $exists = DB::table('migrations')
                ->where('migration', $migrationName)
                ->exists();

            if (! $exists) {
                // Insert new row
                DB::table('migrations')->insert([
                    'migration' => $migrationName,
                    'batch' => 99,
                ]);

                $inserted[] = $migrationName;
                $this->line("  ✓ Inserted: {$migrationName}");
            } else {
                $skipped[] = $migrationName;
            }
        }

        // Print summary
        $this->newLine();
        $this->info('Summary:');
        $this->info('  Total migration files: '.count($migrationFiles));
        $this->info('  Inserted: '.count($inserted));
        $this->info('  Already exists: '.count($skipped));

        if (! empty($inserted)) {
            $this->newLine();
            $this->info('Inserted migrations:');
            foreach ($inserted as $migration) {
                $this->line("  - {$migration}");
            }
        }

        return Command::SUCCESS;
    }
}
