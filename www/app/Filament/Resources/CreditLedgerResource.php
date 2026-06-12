<?php

namespace App\Filament\Resources;

use App\Filament\Resources\CreditLedgerResource\Pages;
use App\Models\CreditLedger;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class CreditLedgerResource extends Resource
{
    protected static ?string $model = CreditLedger::class;

    protected static ?string $navigationIcon = 'heroicon-o-currency-euro';

    protected static ?string $navigationGroup = 'Finance';

    protected static ?int $navigationSort = 3;

    protected static ?string $recordTitleAttribute = 'reason';

    protected static ?string $modelLabel = 'Credit Transaction';

    protected static ?string $pluralModelLabel = 'Credit Ledger';

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit($record): bool
    {
        return false;
    }

    public static function canDelete($record): bool
    {
        return false;
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Select::make('user_id')
                    ->label('User')
                    ->relationship('user', 'name')
                    ->searchable()
                    ->preload()
                    ->required(),

                Forms\Components\Select::make('batch_id')
                    ->label('Batch')
                    ->relationship('batch', 'id')
                    ->searchable(),

                Forms\Components\Select::make('workflow_id')
                    ->label('Workflow')
                    ->relationship('workflow', 'name')
                    ->searchable(),

                Forms\Components\TextInput::make('delta')
                    ->label('Credits Delta')
                    ->numeric()
                    ->required(),

                Forms\Components\TextInput::make('reason')
                    ->label('Reason')
                    ->required(),

                Forms\Components\TextInput::make('balance_after')
                    ->label('Balance After')
                    ->numeric()
                    ->required(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('id')
                    ->label('ID')
                    ->sortable(),

                Tables\Columns\TextColumn::make('user.name')
                    ->label('User')
                    ->searchable()
                    ->url(fn (CreditLedger $record): ?string => $record->user_id
                            ? route('filament.admin.resources.users.view', ['record' => $record->user_id])
                            : null
                    )
                    ->placeholder('-'),

                Tables\Columns\TextColumn::make('batch.id')
                    ->label('Batch ID')
                    ->placeholder('-')
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('workflow.name')
                    ->label('Workflow')
                    ->placeholder('-')
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('delta')
                    ->label('Delta')
                    ->numeric()
                    ->color(fn (CreditLedger $record): string => $record->delta > 0 ? 'success' : ($record->delta < 0 ? 'danger' : 'gray')
                    )
                    ->formatStateUsing(fn (int $state): string => $state > 0 ? '+'.number_format($state) : number_format($state)
                    )
                    ->sortable(),

                Tables\Columns\TextColumn::make('reason')
                    ->label('Reason')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'purchase' => 'success',
                        'refund' => 'warning',
                        'spend' => 'info',
                        'adjust' => 'gray',
                        'bonus' => 'success',
                        'registration' => 'primary',
                        default => 'gray',
                    })
                    ->searchable(),

                Tables\Columns\TextColumn::make('balance_after')
                    ->label('Balance After')
                    ->numeric()
                    ->sortable(),

                Tables\Columns\TextColumn::make('meta')
                    ->label('Metadata')
                    ->formatStateUsing(function ($state) {
                        if (! $state) {
                            return 'No metadata';
                        }
                        if (is_array($state)) {
                            $summary = [];
                            foreach ($state as $key => $value) {
                                $summary[] = $key.': '.(is_string($value) ? $value : json_encode($value));
                            }

                            return implode(', ', array_slice($summary, 0, 2)).(count($summary) > 2 ? '...' : '');
                        }

                        return 'Invalid data';
                    })
                    ->wrap()
                    ->limit(40)
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('Created')
                    ->dateTime()
                    ->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('reason')
                    ->label('Reason')
                    ->options([
                        'purchase' => 'Purchase',
                        'refund' => 'Refund',
                        'spend' => 'Spend',
                        'adjust' => 'Adjustment',
                        'bonus' => 'Bonus',
                        'registration' => 'Registration',
                    ]),

                Tables\Filters\SelectFilter::make('delta_type')
                    ->label('Transaction Type')
                    ->options([
                        'positive' => 'Credits Added (+)',
                        'negative' => 'Credits Spent (-)',
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return match ($data['value']) {
                            'positive' => $query->where('delta', '>', 0),
                            'negative' => $query->where('delta', '<', 0),
                            default => $query,
                        };
                    }),

                Tables\Filters\SelectFilter::make('user_id')
                    ->label('User')
                    ->relationship('user', 'name')
                    ->searchable()
                    ->preload(),

                Tables\Filters\Filter::make('created_at')
                    ->form([
                        Forms\Components\DatePicker::make('created_from')
                            ->label('From'),
                        Forms\Components\DatePicker::make('created_until')
                            ->label('Until'),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when(
                                $data['created_from'],
                                fn (Builder $query, $date): Builder => $query->whereDate('created_at', '>=', $date),
                            )
                            ->when(
                                $data['created_until'],
                                fn (Builder $query, $date): Builder => $query->whereDate('created_at', '<=', $date),
                            );
                    }),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
            ])
            ->bulkActions([])
            ->paginated([10, 25, 50, 100])
            ->defaultPaginationPageOption(25);
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist
            ->schema([
                Infolists\Components\Section::make('Transaction Details')
                    ->schema([
                        Infolists\Components\TextEntry::make('id')
                            ->label('ID'),

                        Infolists\Components\TextEntry::make('delta')
                            ->label('Credits Delta')
                            ->formatStateUsing(fn (int $state): string => $state > 0 ? '+'.number_format($state) : number_format($state)
                            )
                            ->color(fn (CreditLedger $record): string => $record->delta > 0 ? 'success' : ($record->delta < 0 ? 'danger' : 'gray')
                            ),

                        Infolists\Components\TextEntry::make('reason')
                            ->label('Reason')
                            ->badge()
                            ->color(fn (string $state): string => match ($state) {
                                'purchase' => 'success',
                                'refund' => 'warning',
                                'spend' => 'info',
                                'adjust' => 'gray',
                                'bonus' => 'success',
                                'registration' => 'primary',
                                default => 'gray',
                            }),

                        Infolists\Components\TextEntry::make('balance_after')
                            ->label('Balance After')
                            ->numeric(),

                        Infolists\Components\TextEntry::make('created_at')
                            ->label('Created At')
                            ->dateTime(),
                    ])
                    ->columns(3),

                Infolists\Components\Section::make('Related Records')
                    ->schema([
                        Infolists\Components\TextEntry::make('user.name')
                            ->label('User')
                            ->url(fn (CreditLedger $record): ?string => $record->user_id
                                    ? route('filament.admin.resources.users.view', ['record' => $record->user_id])
                                    : null
                            )
                            ->placeholder('-'),

                        Infolists\Components\TextEntry::make('user.email')
                            ->label('Email')
                            ->placeholder('-'),

                        Infolists\Components\TextEntry::make('batch.id')
                            ->label('Batch ID')
                            ->placeholder('-'),

                        Infolists\Components\TextEntry::make('workflow.name')
                            ->label('Workflow')
                            ->placeholder('-'),
                    ])
                    ->columns(2),

                Infolists\Components\Section::make('Metadata')
                    ->schema([
                        Infolists\Components\KeyValueEntry::make('meta')
                            ->label('')
                            ->columnSpanFull(),
                    ])
                    ->collapsed(fn (CreditLedger $record): bool => empty($record->meta)),
            ]);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListCreditLedgers::route('/'),
            'view' => Pages\ViewCreditLedger::route('/{record}'),
        ];
    }
}
