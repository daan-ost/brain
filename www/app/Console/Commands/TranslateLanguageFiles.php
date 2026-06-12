<?php

namespace App\Console\Commands;

use App\Services\DeepLService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class TranslateLanguageFiles extends Command
{
    protected $signature = 'translate:files {--file=* : Specific files to translate}';

    protected $description = 'Translate English language files to Dutch using DeepL API';

    protected DeepLService $deepl;

    public function __construct(DeepLService $deepl)
    {
        parent::__construct();
        $this->deepl = $deepl;
    }

    public function handle()
    {
        $files = $this->option('file') ?: [
            'auth.php',
            'common.php',
            'features.php',
            'final_cta.php',
            'hero.php',
            'landing.php',
            'legal.php',
            'messages.php',
            'upload.php',
        ];

        $this->info('Starting translation of '.count($files).' files from EN to NL...');
        $this->newLine();

        $glossaryId = config('deepl.glossary_en_nl');

        foreach ($files as $file) {
            $this->translateFile($file, $glossaryId);
        }

        $this->newLine();
        $this->info('✓ All translations completed successfully!');
    }

    protected function translateFile(string $filename, ?string $glossaryId): void
    {
        $sourcePath = lang_path('en/'.$filename);
        $targetPath = lang_path('nl/'.$filename);

        if (! File::exists($sourcePath)) {
            $this->error("✗ Source file not found: {$sourcePath}");

            return;
        }

        $this->info("Translating: {$filename}");

        // Load the source file
        $sourceArray = require $sourcePath;

        // Load existing target file if it exists
        $targetArray = File::exists($targetPath) ? require $targetPath : [];

        // Translate the array
        $translatedArray = $this->translateArray($sourceArray, $glossaryId);

        // Generate the PHP file content
        $content = $this->generatePhpFile($translatedArray);

        // Write to target file
        File::put($targetPath, $content);

        $this->line("  ✓ Saved to: {$targetPath}");
        $this->newLine();
    }

    protected function translateArray(array $array, ?string $glossaryId, string $prefix = ''): array
    {
        $result = [];

        foreach ($array as $key => $value) {
            $fullKey = $prefix ? "{$prefix}.{$key}" : $key;

            if (is_array($value)) {
                // Recursively translate nested arrays
                $result[$key] = $this->translateArray($value, $glossaryId, $fullKey);
            } else {
                // Translate string value
                $result[$key] = $this->translateString($value, $fullKey, $glossaryId);
            }
        }

        return $result;
    }

    protected function translateString(string $text, string $key, ?string $glossaryId): string
    {
        // Skip empty strings
        if (empty(trim($text))) {
            return $text;
        }

        $this->line("  - Translating: {$key}");

        try {
            // Use translateWithPlaceholders to preserve Laravel placeholders like :name, :count, etc.
            $translation = $this->deepl->translateWithPlaceholders(
                $text,
                'NL',
                'EN',
                $glossaryId
            );

            // Also preserve {count} style placeholders
            $translation = $this->preserveCurlyBracePlaceholders($text, $translation);

            return $translation;
        } catch (\Exception $e) {
            $this->warn("    ⚠ Translation failed for '{$key}': {$e->getMessage()}");
            $this->warn("    Using original text: {$text}");

            return $text;
        }
    }

    protected function preserveCurlyBracePlaceholders(string $original, string $translated): string
    {
        // Find all {placeholder} patterns in original
        preg_match_all('/\{(\w+)\}/', $original, $matches);

        if (! empty($matches[0])) {
            foreach ($matches[0] as $placeholder) {
                // If the placeholder got translated or mangled, restore it
                $translated = preg_replace('/\{\s*'.preg_quote(trim($placeholder, '{}')).'\s*\}/', $placeholder, $translated);
            }
        }

        return $translated;
    }

    protected function generatePhpFile(array $data): string
    {
        $export = var_export($data, true);

        // Clean up the var_export output for better formatting
        $export = preg_replace('/=>\s+\n\s+array\s+\(/', '=> [', $export);
        $export = preg_replace('/array\s+\(/', '[', $export);
        $export = str_replace(')', ']', $export);
        $export = preg_replace('/=>\s+\[/', ' => [', $export);

        // Improve indentation
        $lines = explode("\n", $export);
        $formatted = [];
        $indent = 0;

        foreach ($lines as $line) {
            $line = trim($line);

            if (empty($line)) {
                continue;
            }

            // Decrease indent for closing brackets
            if (preg_match('/^\]/', $line)) {
                $indent--;
            }

            $formatted[] = str_repeat('    ', $indent).$line;

            // Increase indent for opening brackets
            if (preg_match('/\[$/', $line)) {
                $indent++;
            }
        }

        return "<?php\n\nreturn ".implode("\n", $formatted).";\n";
    }
}
