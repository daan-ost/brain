<?php

namespace App\Filament\Resources\PostmarkTemplateResource\Pages;

use App\Filament\Resources\PostmarkTemplateResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditPostmarkTemplate extends EditRecord
{
    protected static string $resource = PostmarkTemplateResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\ViewAction::make(),
            Actions\DeleteAction::make(),
        ];
    }
}
