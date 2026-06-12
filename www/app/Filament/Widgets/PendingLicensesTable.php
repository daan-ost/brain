<?php

namespace App\Filament\Widgets;

use App\Models\OrganizationLicense;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;

class PendingLicensesTable extends BaseWidget
{
    protected static ?string $heading = 'Pending Organization Licenses';

    protected static ?int $sort = 5;

    protected int|string|array $columnSpan = 1;

    public function table(Table $table): Table
    {
        return $table
            ->query(
                OrganizationLicense::query()
                    ->pending()
                    ->with(['organization', 'license'])
                    ->latest()
                    ->limit(10)
            )
            ->columns([
                Tables\Columns\TextColumn::make('organization.name')
                    ->label('Organization')
                    ->limit(20)
                    ->url(fn (OrganizationLicense $record): string => route('filament.admin.resources.organizations.view', ['record' => $record->organization_id])
                    ),

                Tables\Columns\TextColumn::make('license.name')
                    ->label('License')
                    ->badge(),

                Tables\Columns\TextColumn::make('invoice_number')
                    ->label('Invoice')
                    ->placeholder('N/A'),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('Requested')
                    ->since(),
            ])
            ->paginated(false)
            ->actions([
                Tables\Actions\Action::make('view')
                    ->label('View')
                    ->icon('heroicon-m-eye')
                    ->url(fn (OrganizationLicense $record): string => route('filament.admin.resources.organizations.view', ['record' => $record->organization_id])
                    ),
            ]);
    }
}
