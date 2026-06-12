<?php

namespace App\Filament\Resources\PostmarkTemplateResource\Pages;

use App\Filament\Resources\PostmarkTemplateResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListPostmarkTemplates extends ListRecords
{
    protected static string $resource = PostmarkTemplateResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
