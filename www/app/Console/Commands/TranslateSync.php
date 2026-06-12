<?php

namespace App\Console\Commands;

use App\Services\DeepLService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class TranslateSync extends Command
{
    protected $signature = 'translate:sync
                            {--from=en : Source language}
                            {--to=* : Target languages (comma-separated or multiple --to flags)}
                            {--domain=* : Specific translation domains (optional)}
                            {--only-missing : Only translate missing keys}
                            {--dry-run : Preview changes without writing}
                            {--force : Overwrite existing translations}';

    protected $description = 'Sync Laravel translation files using DeepL API';

    protected DeepLService $deepl;

    public function handle(): int
    {
        $this->deepl = app(DeepLService::class);

        $sourceLang = $this->option('from');
        $targetLangs = $this->option('to') ?: explode(',', config('deepl.target_langs')[0] ?? 'nl');
        $domains = $this->option('domain');
        $onlyMissing = $this->option('only-missing');
        $dryRun = $this->option('dry-run');
        $force = $this->option('force');

        $this->info('🌍 Translation Sync');
        $this->info("Source: {$sourceLang}");
        $this->info('Targets: '.implode(', ', $targetLangs));

        if ($dryRun) {
            $this->warn('🔍 DRY RUN MODE - No files will be modified');
        }

        foreach ($targetLangs as $targetLang) {
            $this->newLine();
            $this->line('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
            $this->info("📝 Syncing {$sourceLang} → {$targetLang}");
            $this->line('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');

            $this->syncLanguage($sourceLang, $targetLang, $domains, $onlyMissing, $dryRun, $force);
        }

        $this->newLine();
        $this->info('✅ Translation sync completed!');

        return self::SUCCESS;
    }

    protected function syncLanguage(
        string $sourceLang,
        string $targetLang,
        array $domains,
        bool $onlyMissing,
        bool $dryRun,
        bool $force
    ): void {
        $sourcePath = lang_path($sourceLang);
        $targetPath = lang_path($targetLang);

        if (! File::exists($sourcePath)) {
            $this->error("Source language directory not found: {$sourcePath}");

            return;
        }

        // Create target directory if it doesn't exist
        if (! File::exists($targetPath) && ! $dryRun) {
            File::makeDirectory($targetPath, 0755, true);
            $this->info("Created directory: {$targetPath}");
        }

        // Get all source translation files
        $sourceFiles = File::glob("{$sourcePath}/*.php");

        foreach ($sourceFiles as $sourceFile) {
            $domain = basename($sourceFile, '.php');

            // Skip if domain filter is active and this domain is not included
            if (! empty($domains) && ! in_array($domain, $domains)) {
                continue;
            }

            $this->syncDomain($sourceFile, $targetPath, $domain, $sourceLang, $targetLang, $onlyMissing, $dryRun, $force);
        }
    }

    protected function syncDomain(
        string $sourceFile,
        string $targetPath,
        string $domain,
        string $sourceLang,
        string $targetLang,
        bool $onlyMissing,
        bool $dryRun,
        bool $force
    ): void {
        $this->line("  📄 Processing: {$domain}.php");

        $sourceTranslations = include $sourceFile;
        $targetFile = "{$targetPath}/{$domain}.php";

        $targetTranslations = File::exists($targetFile) ? include $targetFile : [];

        $missing = [];
        $updated = [];

        foreach ($sourceTranslations as $key => $value) {
            // Handle nested arrays
            if (is_array($value)) {
                $missing[$key] = $this->syncNestedArray(
                    $value,
                    $targetTranslations[$key] ?? [],
                    $sourceLang,
                    $targetLang,
                    $onlyMissing,
                    $force
                );

                if (! empty($missing[$key])) {
                    $updated[$key] = true;
                }

                continue;
            }

            // Skip non-translatable values
            if (! is_string($value)) {
                continue;
            }

            // Check if translation exists
            $hasTranslation = isset($targetTranslations[$key]) && ! empty($targetTranslations[$key]);

            if ($hasTranslation && $onlyMissing && ! $force) {
                continue;
            }

            if (! $hasTranslation || $force) {
                $missing[$key] = $value;
            }
        }

        if (empty($missing)) {
            $this->line('    ✓ No missing translations');

            return;
        }

        $count = $this->countKeys($missing);
        $this->warn("    ⚠ Missing keys: {$count}");

        if ($dryRun) {
            $this->displayMissingKeys($missing);

            return;
        }

        // Translate missing keys
        $translated = $this->translateArray($missing, $sourceLang, $targetLang);

        // Merge with existing translations
        $mergedTranslations = array_replace_recursive($targetTranslations, $translated);

        // Write to file
        $this->writeTranslationFile($targetFile, $mergedTranslations, $domain);

        $this->info("    ✅ Translated {$count} keys");
    }

    protected function syncNestedArray(
        array $sourceArray,
        array $targetArray,
        string $sourceLang,
        string $targetLang,
        bool $onlyMissing,
        bool $force
    ): array {
        $missing = [];

        foreach ($sourceArray as $key => $value) {
            if (is_array($value)) {
                $nested = $this->syncNestedArray(
                    $value,
                    $targetArray[$key] ?? [],
                    $sourceLang,
                    $targetLang,
                    $onlyMissing,
                    $force
                );

                if (! empty($nested)) {
                    $missing[$key] = $nested;
                }

                continue;
            }

            if (! is_string($value)) {
                continue;
            }

            $hasTranslation = isset($targetArray[$key]) && ! empty($targetArray[$key]);

            if (! $hasTranslation || $force) {
                $missing[$key] = $value;
            }
        }

        return $missing;
    }

    protected function translateArray(array $data, string $sourceLang, string $targetLang): array
    {
        $translated = [];
        $glossaryId = config("deepl.glossary_{$sourceLang}_{$targetLang}");

        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $translated[$key] = $this->translateArray($value, $sourceLang, $targetLang);

                continue;
            }

            if (! is_string($value)) {
                $translated[$key] = $value;

                continue;
            }

            try {
                $translated[$key] = $this->deepl->translateWithPlaceholders(
                    $value,
                    strtoupper($targetLang),
                    strtoupper($sourceLang),
                    $glossaryId
                );

                // Brief pause to avoid rate limiting
                usleep(50000); // 50ms
            } catch (\Exception $e) {
                $this->error("    ✗ Translation failed for key '{$key}': ".$e->getMessage());
                $translated[$key] = $value; // Keep original on error
            }
        }

        return $translated;
    }

    protected function writeTranslationFile(string $filePath, array $translations, string $domain): void
    {
        $export = var_export($translations, true);
        $content = <<<PHP
<?php

// Auto-generated translation file
// Domain: {$domain}
// Generated: %s
// Last synced with DeepL

return {$export};

PHP;

        $content = sprintf($content, now()->toDateTimeString());

        File::put($filePath, $content);
    }

    protected function displayMissingKeys(array $missing, string $prefix = ''): void
    {
        foreach ($missing as $key => $value) {
            $fullKey = $prefix ? "{$prefix}.{$key}" : $key;

            if (is_array($value)) {
                $this->displayMissingKeys($value, $fullKey);
            } else {
                $preview = strlen($value) > 50 ? substr($value, 0, 50).'...' : $value;
                $this->line("      • {$fullKey}: {$preview}");
            }
        }
    }

    protected function countKeys(array $data): int
    {
        $count = 0;

        foreach ($data as $value) {
            if (is_array($value)) {
                $count += $this->countKeys($value);
            } else {
                $count++;
            }
        }

        return $count;
    }
}
