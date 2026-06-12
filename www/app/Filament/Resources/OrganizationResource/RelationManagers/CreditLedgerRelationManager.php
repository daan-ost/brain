<?php

namespace App\Filament\Resources\OrganizationResource\RelationManagers;

use Filament\Forms;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

class CreditLedgerRelationManager extends RelationManager
{
    protected static string $relationship = 'creditLedger';

    protected static ?string $title = 'Credit History';

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('id')
            ->columns([
                Tables\Columns\TextColumn::make('created_at')
                    ->label('Date')
                    ->dateTime()
                    ->sortable(),

                Tables\Columns\TextColumn::make('delta')
                    ->label('Change')
                    ->badge()
                    ->color(fn (int $state): string => $state > 0 ? 'success' : 'danger')
                    ->formatStateUsing(fn (int $state): string => ($state > 0 ? '+' : '').$state),

                Tables\Columns\TextColumn::make('reason')
                    ->label('Reason')
                    ->formatStateUsing(fn (string $state): string => ucwords(str_replace('_', ' ', $state))),

                Tables\Columns\TextColumn::make('balance_after')
                    ->label('Balance After')
                    ->numeric(),

                Tables\Columns\TextColumn::make('expires_at')
                    ->label('Expires')
                    ->dateTime('Y-m-d')
                    ->placeholder('—')
                    ->color(function ($record): string {
                        if (! $record->expires_at) {
                            return 'gray';
                        }
                        if ($record->expires_at->isPast()) {
                            return 'danger';
                        }
                        if ($record->expires_at->diffInDays(now()) <= 30) {
                            return 'warning';
                        }

                        return 'success';
                    }),

                Tables\Columns\TextColumn::make('details')
                    ->label('Details')
                    ->state(function ($record): string {
                        if (! $record->meta) {
                            return '';
                        }

                        return $record->meta['admin_reason'] ?? '';
                    })
                    ->limit(50),
            ])
            ->filters([
                //
            ])
            ->headerActions([
                Tables\Actions\CreateAction::make()
                    ->label('Add Credit Adjustment')
                    ->form([
                        Forms\Components\TextInput::make('delta')
                            ->label('Credit Change')
                            ->numeric()
                            ->required()
                            ->helperText('Positive to add, negative to subtract'),

                        Forms\Components\Select::make('reason')
                            ->options([
                                'admin_adjustment' => 'Admin Adjustment',
                                'bonus' => 'Bonus',
                                'refund' => 'Refund',
                                'correction' => 'Correction',
                            ])
                            ->required(),

                        Forms\Components\Textarea::make('admin_reason')
                            ->label('Notes')
                            ->rows(2),
                    ])
                    ->mutateFormDataUsing(function (array $data): array {
                        $owner = $this->getOwnerRecord();
                        $currentBalance = $owner->creditPool?->balance ?? 0;
                        $data['balance_after'] = $currentBalance + $data['delta'];
                        $data['meta'] = ['admin_reason' => $data['admin_reason'] ?? null];
                        unset($data['admin_reason']);

                        return $data;
                    })
                    ->after(function () {
                        $owner = $this->getOwnerRecord();
                        $latestLedger = $owner->creditLedger()->latest()->first();
                        if ($latestLedger && $owner->creditPool) {
                            $owner->creditPool->update([
                                'balance' => $latestLedger->balance_after,
                            ]);
                        }
                    }),
            ])
            ->actions([
                //
            ])
            ->bulkActions([
                //
            ])
            ->defaultSort('created_at', 'desc')
            ->paginated([10, 25, 50]);
    }
}
