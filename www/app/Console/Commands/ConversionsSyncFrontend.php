<?php

namespace App\Console\Commands;

use App\Services\PageConfigProvider;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class ConversionsSyncFrontend extends Command
{
    protected $signature = 'conversions:sync-frontend {--dry-run : Show changes without applying them}';

    protected $description = 'Auto-generate frontend type mappings from PageConfigProvider';

    protected PageConfigProvider $provider;

    public function handle()
    {
        $this->provider = app(PageConfigProvider::class);
        $dryRun = $this->option('dry-run');

        $this->info('Syncing frontend type mappings from backend configuration...');
        $this->newLine();

        // Generate type maps and MIME maps
        $typeMaps = $this->generateTypeMaps();
        $mimeMaps = $this->generateMimeMaps();

        // Update the vanilla-upload.blade.php file
        $frontendFile = resource_path('views/components/vanilla-upload.blade.php');

        if (! File::exists($frontendFile)) {
            $this->error("Frontend file not found: {$frontendFile}");

            return Command::FAILURE;
        }

        $content = File::get($frontendFile);

        // Replace typeMap
        $content = $this->replaceTypeMap($content, $typeMaps);

        // Replace mimeMap
        $content = $this->replaceMimeMap($content, $mimeMaps);

        if ($dryRun) {
            $this->info('DRY RUN - Changes not applied');
            $this->newLine();
            $this->info('Type maps that would be generated:');
            $this->line(json_encode($typeMaps, JSON_PRETTY_PRINT));
            $this->newLine();
            $this->info('MIME maps that would be generated:');
            $this->line(json_encode($mimeMaps, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            File::put($frontendFile, $content);
            $this->info('✓ Frontend type mappings synchronized successfully');
            $this->newLine();
            $this->info("Updated file: {$frontendFile}");
        }

        return Command::SUCCESS;
    }

    protected function generateTypeMaps(): array
    {
        $typeMaps = [];

        // Get all conversion families from landing_pages config
        $families = config('landing_pages.conversion_families', []);

        foreach ($families as $family => $extensions) {
            $typeMaps[$family] = $extensions;
        }

        // Always include zips
        $typeMaps['zips'] = ['zip'];

        return $typeMaps;
    }

    protected function generateMimeMaps(): array
    {
        $mimeMaps = [];

        // Get all families and generate MIME mappings
        $families = config('landing_pages.conversion_families', []);

        foreach ($families as $family => $extensions) {
            $mimeTypes = $this->provider->getMimeTypesForGroup($family);

            foreach ($extensions as $ext) {
                // Try to find matching MIME type for this extension
                $mime = $this->guessMimeType($ext, $mimeTypes);
                if ($mime) {
                    $mimeMaps[$ext] = $mime;
                }
            }
        }

        // Add standard mappings
        $mimeMaps['zip'] = 'application/zip';

        return $mimeMaps;
    }

    protected function guessMimeType(string $extension, array $mimeTypes): ?string
    {
        // Common MIME type patterns
        $patterns = [
            'dwf' => 'application/x-dwf',
            'dwfx' => 'application/x-dwfx',
            'dwg' => 'image/vnd.dwg',
            'dxf' => 'image/vnd.dxf',
            'jpg' => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'webp' => 'image/webp',
            'pdf' => 'application/pdf',
            'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
            'ppt' => 'application/vnd.ms-powerpoint',
            'txt' => 'text/plain',
            'log' => 'text/plain',
        ];

        // Check if we have a known pattern
        if (isset($patterns[$extension])) {
            return $patterns[$extension];
        }

        // Try to find from mimeTypes array
        foreach ($mimeTypes as $mime) {
            if (str_contains($mime, $extension)) {
                return $mime;
            }
        }

        return null;
    }

    protected function replaceTypeMap(string $content, array $typeMaps): string
    {
        // Generate the typeMap JavaScript code
        $typeMapCode = $this->generateTypeMapCode($typeMaps);

        // Find and replace the typeMap
        $pattern = '/const\s+typeMap\s*=\s*\{[^}]+\};/s';

        if (! preg_match($pattern, $content)) {
            $this->warn('Could not find typeMap in frontend file');

            return $content;
        }

        return preg_replace($pattern, "const typeMap = {$typeMapCode};", $content);
    }

    protected function replaceMimeMap(string $content, array $mimeMaps): string
    {
        // Generate the mimeMap JavaScript code
        $mimeMapCode = $this->generateMimeMapCode($mimeMaps);

        // Find and replace the mimeMap
        $pattern = '/const\s+mimeMap\s*=\s*\{[^}]+\};/s';

        if (! preg_match($pattern, $content)) {
            $this->warn('Could not find mimeMap in frontend file');

            return $content;
        }

        return preg_replace($pattern, "const mimeMap = {$mimeMapCode};", $content);
    }

    protected function generateTypeMapCode(array $typeMaps): string
    {
        $lines = ['{'];

        foreach ($typeMaps as $family => $extensions) {
            $extJson = json_encode($extensions);
            $lines[] = "                '{$family}': {$extJson},";
        }

        $lines[] = '            }';

        return implode("\n", $lines);
    }

    protected function generateMimeMapCode(array $mimeMaps): string
    {
        $lines = ['{'];

        foreach ($mimeMaps as $ext => $mime) {
            $lines[] = "                    '{$ext}': '{$mime}',";
        }

        $lines[] = '                }';

        return implode("\n", $lines);
    }
}
