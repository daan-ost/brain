<?php

namespace App\Filament\Resources\UserResource\RelationManagers;

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
                        $meta = is_array($record->meta) ? $record->meta : [];
                        $parts = [];

                        if ($record->reason === 'spend') {
                            $parts[] = ($meta['documents_count'] ?? '?').' docs';
                            if (! empty($meta['workflow_name'])) {
                                $parts[] = $meta['workflow_name'];
                            }
                        }

                        if (str_starts_with((string) $record->reason, 'license_')
                            || str_starts_with((string) $record->reason, 'reset_')
                        ) {
                            if (! empty($meta['license_tier'])) {
                                $parts[] = "tier: {$meta['license_tier']}";
                            }
                            if (! empty($meta['license_id'])) {
                                $parts[] = "license #{$meta['license_id']}";
                            }
                            if (! empty($meta['expired_credits'])) {
                                $parts[] = "{$meta['expired_credits']} lost";
                            }
                        }

                        if (in_array($record->reason, ['purchase', 'subscription'], true)) {
                            if (! empty($meta['order_id'])) {
                                $parts[] = 'order: '.substr((string) $meta['order_id'], 0, 8);
                            }
                            if (! empty($meta['gross_amount'])) {
                                $currency = $meta['currency'] ?? 'EUR';
                                $parts[] = "{$meta['gross_amount']} {$currency}";
                            }
                        }

                        if (! empty($meta['admin_reason'])) {
                            $parts[] = $meta['admin_reason'];
                        }

                        if (! empty($meta['reason']) && $meta['reason'] !== $record->reason) {
                            $parts[] = "[{$meta['reason']}]";
                        }

                        return implode(' · ', $parts);
                    })
                    ->wrap()
                    ->tooltip(fn ($record) => is_array($record->meta) && $record->meta !== []
                        ? json_encode($record->meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
                        : null
                    ),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('reason')
                    ->multiple()
                    ->options([
                        'spend' => 'Spend',
                        'purchase' => 'Purchase',
                        'subscription' => 'Subscription',
                        'license_expired' => 'License expired',
                        'reset_free' => 'Reset (free)',
                        'reset_premium' => 'Reset (premium)',
                        'adjust' => 'Adjust',
                        'admin_adjustment' => 'Admin adjustment',
                        'refund' => 'Refund',
                        'correction' => 'Correction',
                        'bonus' => 'Bonus',
                    ]),

                Tables\Filters\Filter::make('date_range')
                    ->form([
                        Forms\Components\DatePicker::make('from')->label('From'),
                        Forms\Components\DatePicker::make('to')->label('To'),
                    ])
                    ->query(function ($query, array $data) {
                        return $query
                            ->when($data['from'] ?? null, fn ($q, $date) => $q->whereDate('created_at', '>=', $date))
                            ->when($data['to'] ?? null, fn ($q, $date) => $q->whereDate('created_at', '<=', $date));
                    })
                    ->indicateUsing(function (array $data): array {
                        $indicators = [];
                        if ($data['from'] ?? null) {
                            $indicators['from'] = 'From: '.$data['from'];
                        }
                        if ($data['to'] ?? null) {
                            $indicators['to'] = 'To: '.$data['to'];
                        }

                        return $indicators;
                    }),
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
                        $data['balance_after'] = $owner->credits + $data['delta'];
                        $data['meta'] = ['admin_reason' => $data['admin_reason'] ?? null];
                        unset($data['admin_reason']);

                        return $data;
                    })
                    ->after(function () {
                        $owner = $this->getOwnerRecord();
                        $latestLedger = $owner->creditLedger()->latest()->first();
                        if ($latestLedger) {
                            $owner->update([
                                'credits' => $latestLedger->balance_after,
                                'credits_updated_at' => now(),
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
