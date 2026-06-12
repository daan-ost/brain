<?php

namespace App\Filament\Resources\PostmarkTemplateResource\Pages;

use App\Filament\Resources\PostmarkTemplateResource;
use Filament\Actions;
use Filament\Resources\Pages\ViewRecord;

class ViewPostmarkTemplate extends ViewRecord
{
    protected static string $resource = PostmarkTemplateResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\EditAction::make(),
        ];
    }
}
