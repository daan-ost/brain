<?php

namespace App\Filament\Resources\CreditLedgerResource\Pages;

use App\Filament\Resources\CreditLedgerResource;
use Filament\Resources\Pages\ListRecords;

class ListCreditLedgers extends ListRecords
{
    protected static string $resource = CreditLedgerResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
