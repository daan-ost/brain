<?php

namespace App\Filament\Resources\UserResource\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

class OrganizationsRelationManager extends RelationManager
{
    protected static string $relationship = 'organizations';

    protected static ?string $title = 'Organizations';

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label('Organization')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('pivot.role')
                    ->label('Role')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'admin' => 'danger',
                        'member' => 'info',
                        default => 'gray',
                    }),

                Tables\Columns\TextColumn::make('pivot.joined_at')
                    ->label('Joined')
                    ->date()
                    ->sortable(),

                Tables\Columns\TextColumn::make('creditPool.balance')
                    ->label('Org Credits')
                    ->numeric()
                    ->default(0),
            ])
            ->filters([
                //
            ])
            ->headerActions([
                //
            ])
            ->actions([
                Tables\Actions\Action::make('view')
                    ->label('View Organization')
                    ->icon('heroicon-o-eye')
                    ->url(fn ($record) => route('filament.admin.resources.organizations.view', $record)),
            ])
            ->bulkActions([
                //
            ]);
    }
}
