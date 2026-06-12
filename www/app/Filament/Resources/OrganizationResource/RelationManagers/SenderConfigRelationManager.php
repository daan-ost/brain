<?php

namespace App\Filament\Resources\OrganizationResource\RelationManagers;

use App\Enums\SenderConfigStatus;
use App\Enums\SenderLevel;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

class SenderConfigRelationManager extends RelationManager
{
    protected static string $relationship = 'senderConfig';

    protected static ?string $title = 'Sender Config';

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('sender_level')
                    ->badge()
                    ->formatStateUsing(fn (SenderLevel $state): string => $state->label())
                    ->color(fn (SenderLevel $state): string => $state->badgeColor()),

                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (SenderConfigStatus $state): string => $state->label())
                    ->color(fn (SenderConfigStatus $state): string => $state->badgeColor()),

                Tables\Columns\TextColumn::make('from_email')
                    ->label('From Email'),

                Tables\Columns\TextColumn::make('domain'),

                Tables\Columns\TextColumn::make('verified_at')
                    ->label('Verified')
                    ->dateTime(),
            ]);
    }
}
