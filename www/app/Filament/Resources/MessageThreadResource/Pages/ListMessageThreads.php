<?php

namespace App\Filament\Resources\MessageThreadResource\Pages;

use App\Filament\Resources\MessageThreadResource;
use Filament\Resources\Pages\ListRecords;

class ListMessageThreads extends ListRecords
{
    protected static string $resource = MessageThreadResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
