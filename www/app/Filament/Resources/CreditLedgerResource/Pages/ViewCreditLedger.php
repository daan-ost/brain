<?php

namespace App\Filament\Resources\CreditLedgerResource\Pages;

use App\Filament\Resources\CreditLedgerResource;
use Filament\Resources\Pages\ViewRecord;

class ViewCreditLedger extends ViewRecord
{
    protected static string $resource = CreditLedgerResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
