<?php

namespace App\Filament\Resources\NewsletterResource\Pages;

use App\Filament\Resources\NewsletterResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditNewsletter extends EditRecord
{
    protected static string $resource = NewsletterResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\ViewAction::make(),
            Actions\DeleteAction::make()
                ->visible(fn () => $this->record->isDraft()),
        ];
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        // Convert separate language fields to JSON
        $data['title_json'] = [
            'en' => $data['title_en'] ?? '',
            'nl' => $data['title_nl'] ?? '',
        ];
        $data['body_json'] = [
            'en' => $data['body_en'] ?? '',
            'nl' => $data['body_nl'] ?? '',
        ];

        // Remove the separate fields
        unset($data['title_en'], $data['title_nl'], $data['body_en'], $data['body_nl']);

        return $data;
    }
}
