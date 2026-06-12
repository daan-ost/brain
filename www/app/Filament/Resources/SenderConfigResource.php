<?php

namespace App\Filament\Resources;

use App\Enums\SenderConfigStatus;
use App\Enums\SenderLevel;
use App\Filament\Resources\SenderConfigResource\Pages;
use App\Models\OrganizationSenderConfig;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class SenderConfigResource extends Resource
{
    protected static ?string $model = OrganizationSenderConfig::class;

    protected static ?string $navigationIcon = 'heroicon-o-envelope';

    protected static ?string $navigationGroup = 'Users & Organizations';

    protected static ?string $navigationLabel = 'Sender Configs';

    protected static ?int $navigationSort = 5;

    public static function canCreate(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('id')
                    ->sortable(),

                Tables\Columns\TextColumn::make('organization.name')
                    ->label('Organization')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('sender_level')
                    ->badge()
                    ->formatStateUsing(fn (SenderLevel $state): string => $state->label())
                    ->color(fn (SenderLevel $state): string => $state->badgeColor()),

                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (SenderConfigStatus $state): string => $state->label())
                    ->color(fn (SenderConfigStatus $state): string => $state->badgeColor()),

                Tables\Columns\TextColumn::make('from_email')
                    ->label('From Email')
                    ->searchable(),

                Tables\Columns\TextColumn::make('domain')
                    ->searchable(),

                Tables\Columns\TextColumn::make('verified_at')
                    ->label('Verified')
                    ->dateTime()
                    ->sortable(),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('Created')
                    ->dateTime()
                    ->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('sender_level')
                    ->options(collect(SenderLevel::cases())->mapWithKeys(
                        fn (SenderLevel $level) => [$level->value => $level->label()]
                    )),

                Tables\Filters\SelectFilter::make('status')
                    ->options(collect(SenderConfigStatus::cases())->mapWithKeys(
                        fn (SenderConfigStatus $status) => [$status->value => $status->label()]
                    )),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
            ])
            ->defaultSort('created_at', 'desc');
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist
            ->schema([
                Infolists\Components\Section::make('Sender Configuration')
                    ->schema([
                        Infolists\Components\Grid::make(3)
                            ->schema([
                                Infolists\Components\TextEntry::make('organization.name')
                                    ->label('Organization'),

                                Infolists\Components\TextEntry::make('sender_level')
                                    ->badge()
                                    ->formatStateUsing(fn (SenderLevel $state): string => $state->label())
                                    ->color(fn (SenderLevel $state): string => $state->badgeColor()),

                                Infolists\Components\TextEntry::make('status')
                                    ->badge()
                                    ->formatStateUsing(fn (SenderConfigStatus $state): string => $state->label())
                                    ->color(fn (SenderConfigStatus $state): string => $state->badgeColor()),
                            ]),

                        Infolists\Components\Grid::make(3)
                            ->schema([
                                Infolists\Components\TextEntry::make('from_email')
                                    ->label('From Email'),

                                Infolists\Components\TextEntry::make('from_name')
                                    ->label('From Name'),

                                Infolists\Components\TextEntry::make('reply_to_email')
                                    ->label('Reply-To'),
                            ]),

                        Infolists\Components\Grid::make(3)
                            ->schema([
                                Infolists\Components\TextEntry::make('domain'),

                                Infolists\Components\TextEntry::make('postmark_signature_id')
                                    ->label('Postmark Signature ID'),

                                Infolists\Components\TextEntry::make('postmark_domain_id')
                                    ->label('Postmark Domain ID'),
                            ]),
                    ]),

                Infolists\Components\Section::make('Verification')
                    ->schema([
                        Infolists\Components\Grid::make(2)
                            ->schema([
                                Infolists\Components\TextEntry::make('verified_at')
                                    ->label('Verified At')
                                    ->dateTime()
                                    ->placeholder('Not verified'),

                                Infolists\Components\TextEntry::make('failure_reason')
                                    ->label('Failure Reason')
                                    ->placeholder('None'),
                            ]),
                    ]),

                Infolists\Components\Section::make('DNS Records')
                    ->schema([
                        Infolists\Components\TextEntry::make('dns_records')
                            ->label('DNS Records (JSON)')
                            ->formatStateUsing(fn ($state) => $state ? json_encode($state, JSON_PRETTY_PRINT) : 'None')
                            ->markdown(),
                    ])
                    ->visible(fn (OrganizationSenderConfig $record): bool => $record->sender_level === SenderLevel::DomainAuth),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListSenderConfigs::route('/'),
            'view' => Pages\ViewSenderConfig::route('/{record}'),
        ];
    }
}
