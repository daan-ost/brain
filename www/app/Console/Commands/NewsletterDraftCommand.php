<?php

namespace App\Console\Commands;

use App\Models\Newsletter;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Auth;

class NewsletterDraftCommand extends Command
{
    protected $signature = 'newsletter:draft
        {--file= : Pad naar JSON-bestand met title_nl, title_en, body_nl, body_en}
        {--title-nl= : Titel in het Nederlands}
        {--title-en= : Titel in het Engels}
        {--body-nl= : HTML body in het Nederlands}
        {--body-en= : HTML body in het Engels}
        {--user-id=1 : ID van de gebruiker als creator}';

    protected $description = 'Maak een nieuwsbrief draft aan (voor gebruik via Claude Code)';

    public function handle(): int
    {
        $data = $this->resolveContent();

        if (! $data) {
            return self::FAILURE;
        }

        $newsletter = Newsletter::create([
            'title_json' => ['nl' => $data['title_nl'], 'en' => $data['title_en']],
            'body_json'  => ['nl' => $data['body_nl'],  'en' => $data['body_en']],
            'status'     => Newsletter::STATUS_DRAFT,
            'batch_size' => 100,
            'created_by' => (int) $this->option('user-id'),
        ]);

        $this->info("Draft aangemaakt: #{$newsletter->id}");
        $this->line("Titel (NL): {$data['title_nl']}");
        $this->line("Titel (EN): {$data['title_en']}");
        $this->line('Bekijk in Filament: /beheer/newsletters/' . $newsletter->id);

        return self::SUCCESS;
    }

    private function resolveContent(): ?array
    {
        if ($file = $this->option('file')) {
            if (! file_exists($file)) {
                $this->error("Bestand niet gevonden: {$file}");
                return null;
            }

            $json = json_decode(file_get_contents($file), true);

            if (! $json) {
                $this->error('Ongeldig JSON-bestand.');
                return null;
            }

            return $json;
        }

        $titleNl = $this->option('title-nl');
        $titleEn = $this->option('title-en');
        $bodyNl  = $this->option('body-nl');
        $bodyEn  = $this->option('body-en');

        if (! $titleNl || ! $titleEn || ! $bodyNl || ! $bodyEn) {
            $this->error('Geef --file op, of alle vier: --title-nl, --title-en, --body-nl, --body-en');
            return null;
        }

        return [
            'title_nl' => $titleNl,
            'title_en' => $titleEn,
            'body_nl'  => $bodyNl,
            'body_en'  => $bodyEn,
        ];
    }
}
