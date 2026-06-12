<?php

namespace App\Filament\Resources\InboundEmailResource\Pages;

use App\Filament\Resources\InboundEmailResource;
use Filament\Actions;
use Filament\Resources\Pages\ViewRecord;

class ViewInboundEmail extends ViewRecord
{
    protected static string $resource = InboundEmailResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
