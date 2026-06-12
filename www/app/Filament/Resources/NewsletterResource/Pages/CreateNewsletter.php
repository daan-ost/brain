<?php

namespace App\Filament\Resources\NewsletterResource\Pages;

use App\Filament\Resources\NewsletterResource;
use Filament\Resources\Pages\CreateRecord;

class CreateNewsletter extends CreateRecord
{
    protected static string $resource = NewsletterResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['created_by'] = auth()->id();
        $data['status'] = 'draft';

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
