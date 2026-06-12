<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class OpcacheClear extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'opcache:clear';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Clear OPcache without requiring sudo access';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        if (! function_exists('opcache_reset')) {
            $this->warn('OPcache extension is not enabled or opcache_reset() is not available');

            return 1;
        }

        if (opcache_reset()) {
            $this->info('✅ OPcache successfully cleared');

            return 0;
        }

        $this->error('❌ Failed to clear OPcache');

        return 1;
    }
}
