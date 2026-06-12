<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class DeepLService
{
    protected ?string $apiKey;

    protected ?string $apiUrl;

    protected string $formality;

    protected bool $cacheEnabled;

    protected int $cacheTtl;

    public function __construct()
    {
        $this->apiKey = config('deepl.api_key');
        $this->apiUrl = config('deepl.api_url');
        $this->formality = config('deepl.formality', 'prefer_more');
        $this->cacheEnabled = config('deepl.cache_enabled', true);
        $this->cacheTtl = config('deepl.cache_ttl', 15552000);
    }

    protected function ensureConfigured(): void
    {
        if (empty($this->apiKey)) {
            throw new \Exception('DeepL API key not configured. Please set DEEPL_API_KEY in .env');
        }
    }

    /**
     * Translate a single text
     *
     * @param  string  $text  Text to translate
     * @param  string  $targetLang  Target language code (e.g., 'NL', 'DE', 'FR')
     * @param  string|null  $sourceLang  Source language code (default: 'EN')
     * @param  string|null  $formality  Formality level ('default', 'prefer_more', 'prefer_less')
     * @param  bool  $preserveFormatting  Preserve line breaks and formatting
     * @param  string|null  $glossaryId  Optional glossary ID for consistent terminology
     * @return string Translated text
     */
    public function translate(
        string $text,
        string $targetLang,
        ?string $sourceLang = null,
        ?string $formality = null,
        bool $preserveFormatting = true,
        ?string $glossaryId = null
    ): string {
        $this->ensureConfigured();



        $sourceLang = $sourceLang ?? strtoupper(config('deepl.source_lang', 'EN'));
        $targetLang = strtoupper($targetLang);
        $formality = $formality ?? $this->formality;

        // Check cache
        if ($this->cacheEnabled) {
            $cacheKey = $this->getCacheKey($text, $targetLang, $sourceLang);
            $cached = Cache::get($cacheKey);

            if ($cached !== null) {
                Log::info('DeepL translation retrieved from cache', [
                    'target_lang' => $targetLang,
                    'text_length' => strlen($text),
                ]);

                return $cached;
            }
        }

        // Prepare API request
        $params = [
            'text' => [$text],
            'source_lang' => $sourceLang,
            'target_lang' => $targetLang,
            'tag_handling' => 'html',
        ];

        // Add formality if target language supports it
        if (in_array($targetLang, ['DE', 'FR', 'IT', 'ES', 'NL', 'PL', 'PT', 'RU'])) {
            $params['formality'] = $formality;
        }

        if ($glossaryId) {
            $params['glossary_id'] = $glossaryId;
        }

        try {
            $response = Http::timeout(config('deepl.timeout', 30))
                ->withHeaders([
                    'Authorization' => 'DeepL-Auth-Key '.$this->apiKey,
                ])
                ->post($this->apiUrl.'/translate', $params);

            if ($response->successful()) {
                $translation = $response->json()['translations'][0]['text'];

                // Cache the result
                if ($this->cacheEnabled) {
                    Cache::put($cacheKey, $translation, $this->cacheTtl);
                }

                Log::info('DeepL translation successful', [
                    'target_lang' => $targetLang,
                    'text_length' => strlen($text),
                    'translation_length' => strlen($translation),
                ]);

                return $translation;
            }

            // Handle API errors
            $errorBody = $response->json();
            $errorMessage = $errorBody['message'] ?? $response->body();

            Log::error('DeepL API error', [
                'status' => $response->status(),
                'error' => $errorMessage,
                'target_lang' => $targetLang,
            ]);

            throw new \Exception("DeepL API error ({$response->status()}): {$errorMessage}");
        } catch (\Exception $e) {
            Log::error('DeepL translation exception', [
                'error' => $e->getMessage(),
                'target_lang' => $targetLang,
            ]);

            throw $e;
        }
    }

    /**
     * Translate multiple texts in a single API call
     *
     * @param  array  $texts  Array of texts to translate
     * @param  string  $targetLang  Target language code
     * @param  string|null  $sourceLang  Source language code
     * @return array Array of translated texts in same order
     */
    public function batchTranslate(
        array $texts,
        string $targetLang,
        ?string $sourceLang = null
    ): array {
        if (empty($texts)) {
            return [];
        }

        $this->ensureConfigured();



        $sourceLang = $sourceLang ?? strtoupper(config('deepl.source_lang', 'EN'));
        $targetLang = strtoupper($targetLang);

        try {
            $response = Http::timeout(config('deepl.timeout', 30))
                ->withHeaders([
                    'Authorization' => 'DeepL-Auth-Key '.$this->apiKey,
                ])
                ->post($this->apiUrl.'/translate', [
                    'text' => $texts,
                    'source_lang' => $sourceLang,
                    'target_lang' => $targetLang,
                    'formality' => $this->formality,
                ]);

            if ($response->successful()) {
                $translations = array_map(
                    fn ($t) => $t['text'],
                    $response->json()['translations']
                );

                Log::info('DeepL batch translation successful', [
                    'target_lang' => $targetLang,
                    'count' => count($texts),
                ]);

                return $translations;
            }

            throw new \Exception('DeepL batch translation failed: '.$response->body());
        } catch (\Exception $e) {
            Log::error('DeepL batch translation exception', [
                'error' => $e->getMessage(),
                'target_lang' => $targetLang,
                'count' => count($texts),
            ]);

            throw $e;
        }
    }

    /**
     * Translate text with Laravel placeholder preservation
     * Wraps :placeholders in HTML tags so DeepL doesn't translate them
     *
     * @param  string  $text  Text with Laravel placeholders (e.g., "Hello :name")
     * @param  string  $targetLang  Target language
     * @return string Translated text with placeholders intact
     */
    public function translateWithPlaceholders(
        string $text,
        string $targetLang,
        ?string $sourceLang = null,
        ?string $glossaryId = null
    ): string {
        // Wrap placeholders in <span> tags with translate="no"
        $wrapped = preg_replace(
            '/:(\w+)/',
            '<span translate="no">:$1</span>',
            $text
        );

        // Translate
        $translated = $this->translate(
            $wrapped,
            $targetLang,
            $sourceLang,
            preserveFormatting: true,
            glossaryId: $glossaryId
        );

        // Remove wrapper tags
        $unwrapped = preg_replace(
            '/<span translate="no">(:(\w+))<\/span>/',
            '$1',
            $translated
        );

        return $unwrapped;
    }

    /**
     * Get usage statistics from DeepL API
     *
     * @return array Usage statistics
     */
    public function getUsage(): array
    {
        $this->ensureConfigured();



        try {
            $response = Http::timeout(10)
                ->withHeaders([
                    'Authorization' => 'DeepL-Auth-Key '.$this->apiKey,
                ])
                ->get($this->apiUrl.'/usage');

            if ($response->successful()) {
                return $response->json();
            }

            throw new \Exception('Failed to fetch DeepL usage: '.$response->body());
        } catch (\Exception $e) {
            Log::error('DeepL usage fetch failed', ['error' => $e->getMessage()]);
            throw $e;
        }
    }

    /**
     * Test API connection
     *
     * @return bool True if connection successful
     */
    public function testConnection(): bool
    {
        try {
            $this->translate('Hello', 'NL');

            return true;
        } catch (\Exception $e) {
            Log::error('DeepL connection test failed', ['error' => $e->getMessage()]);

            return false;
        }
    }

    /**
     * Generate cache key for translation
     */
    protected function getCacheKey(string $text, string $targetLang, string $sourceLang): string
    {
        return sprintf(
            'deepl_translation:%s:%s:%s',
            $sourceLang,
            $targetLang,
            md5($text)
        );
    }
}
