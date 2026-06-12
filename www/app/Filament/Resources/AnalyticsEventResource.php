<?php

namespace App\Filament\Resources;

use App\Filament\Resources\AnalyticsEventResource\Pages;
use App\Models\AnalyticsEvent;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class AnalyticsEventResource extends Resource
{
    protected static ?string $model = AnalyticsEvent::class;

    protected static ?string $navigationIcon = 'heroicon-o-chart-bar';

    protected static ?string $navigationGroup = 'Analytics';

    protected static ?int $navigationSort = 2;

    protected static ?string $recordTitleAttribute = 'event';

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
                    ->preload(),

                Forms\Components\TextInput::make('guest_sid')
                    ->label('Guest Session ID'),

                Forms\Components\TextInput::make('event')
                    ->label('Event Name')
                    ->required(),

                Forms\Components\TextInput::make('duration_ms')
                    ->label('Duration (ms)')
                    ->numeric(),

                Forms\Components\Toggle::make('success')
                    ->label('Success'),

                Forms\Components\TextInput::make('error_code')
                    ->label('Error Code'),
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
                    ->url(fn (AnalyticsEvent $record): ?string => $record->user_id
                            ? route('filament.admin.resources.users.view', ['record' => $record->user_id])
                            : null
                    )
                    ->placeholder('-'),

                Tables\Columns\TextColumn::make('guest_sid')
                    ->label('Guest Session')
                    ->searchable()
                    ->limit(20)
                    ->tooltip(fn (AnalyticsEvent $record): ?string => $record->guest_sid)
                    ->placeholder('-'),

                Tables\Columns\TextColumn::make('event')
                    ->label('Event')
                    ->searchable()
                    ->badge()
                    ->color('gray'),

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

                            return implode(', ', array_slice($summary, 0, 3)).(count($summary) > 3 ? '...' : '');
                        }

                        return 'Invalid data';
                    })
                    ->wrap()
                    ->limit(50),

                Tables\Columns\TextColumn::make('duration_ms')
                    ->label('Duration')
                    ->formatStateUsing(fn ($state) => $state ? $state.' ms' : '-')
                    ->sortable(),

                Tables\Columns\IconColumn::make('success')
                    ->label('Success')
                    ->boolean()
                    ->trueIcon('heroicon-o-check-circle')
                    ->falseIcon('heroicon-o-x-circle')
                    ->trueColor('success')
                    ->falseColor('danger'),

                Tables\Columns\TextColumn::make('error_code')
                    ->label('Error')
                    ->badge()
                    ->color('danger')
                    ->placeholder('-'),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('Created')
                    ->dateTime()
                    ->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('event')
                    ->label('Event Type')
                    ->options(fn () => AnalyticsEvent::distinct('event')->pluck('event', 'event')->toArray())
                    ->searchable(),

                Tables\Filters\SelectFilter::make('user_type')
                    ->label('User Type')
                    ->options([
                        'registered' => 'Registered User',
                        'guest' => 'Guest User',
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return match ($data['value']) {
                            'registered' => $query->whereNotNull('user_id'),
                            'guest' => $query->whereNull('user_id'),
                            default => $query,
                        };
                    }),

                Tables\Filters\SelectFilter::make('user_id')
                    ->label('User')
                    ->relationship('user', 'name')
                    ->searchable()
                    ->preload(),

                Tables\Filters\TernaryFilter::make('success')
                    ->label('Success Status')
                    ->placeholder('All')
                    ->trueLabel('Successful')
                    ->falseLabel('Failed'),

                Tables\Filters\Filter::make('has_error')
                    ->label('Has Error')
                    ->query(fn (Builder $query): Builder => $query->whereNotNull('error_code')),

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
                Infolists\Components\Section::make('Event Information')
                    ->schema([
                        Infolists\Components\TextEntry::make('id')
                            ->label('ID'),

                        Infolists\Components\TextEntry::make('event')
                            ->label('Event Name')
                            ->badge()
                            ->color('primary'),

                        Infolists\Components\TextEntry::make('created_at')
                            ->label('Created At')
                            ->dateTime(),

                        Infolists\Components\IconEntry::make('success')
                            ->label('Success')
                            ->boolean()
                            ->trueIcon('heroicon-o-check-circle')
                            ->falseIcon('heroicon-o-x-circle')
                            ->trueColor('success')
                            ->falseColor('danger'),

                        Infolists\Components\TextEntry::make('error_code')
                            ->label('Error Code')
                            ->badge()
                            ->color('danger')
                            ->placeholder('-'),

                        Infolists\Components\TextEntry::make('duration_ms')
                            ->label('Duration')
                            ->formatStateUsing(fn ($state) => $state ? $state.' ms' : '-'),
                    ])
                    ->columns(3),

                Infolists\Components\Section::make('User Information')
                    ->schema([
                        Infolists\Components\TextEntry::make('user.name')
                            ->label('User')
                            ->url(fn (AnalyticsEvent $record): ?string => $record->user_id
                                    ? route('filament.admin.resources.users.view', ['record' => $record->user_id])
                                    : null
                            )
                            ->placeholder('Guest User'),

                        Infolists\Components\TextEntry::make('user.email')
                            ->label('Email')
                            ->placeholder('-'),

                        Infolists\Components\TextEntry::make('guest_sid')
                            ->label('Guest Session ID')
                            ->copyable()
                            ->placeholder('-'),

                        Infolists\Components\TextEntry::make('session_id')
                            ->label('Analytics Session ID')
                            ->placeholder('-'),
                    ])
                    ->columns(2),

                Infolists\Components\Section::make('Event Metadata')
                    ->schema([
                        Infolists\Components\KeyValueEntry::make('meta')
                            ->label('')
                            ->columnSpanFull(),
                    ])
                    ->collapsed(fn (AnalyticsEvent $record): bool => empty($record->meta)),
            ]);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListAnalyticsEvents::route('/'),
            'view' => Pages\ViewAnalyticsEvent::route('/{record}'),
        ];
    }
}
