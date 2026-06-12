<?php

namespace App\Filament\Resources\UserResource\RelationManagers;

use App\Filament\Resources\MessageThreadResource;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

class MessageThreadsRelationManager extends RelationManager
{
    protected static string $relationship = 'messageThreads';

    protected static ?string $title = 'Support Threads';

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('title')
            ->columns([
                Tables\Columns\TextColumn::make('id')
                    ->label('#')
                    ->sortable(),

                Tables\Columns\TextColumn::make('title')
                    ->label('Subject')
                    ->limit(40)
                    ->searchable()
                    ->tooltip(fn ($record) => $record->title),

                Tables\Columns\TextColumn::make('category.name')
                    ->label('Category')
                    ->placeholder('—'),

                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'open' => 'success',
                        'waiting_for_user' => 'info',
                        'closed' => 'gray',
                        default => 'gray',
                    })
                    ->formatStateUsing(fn (string $state): string => ucfirst(str_replace('_', ' ', $state))),

                Tables\Columns\TextColumn::make('thumb')
                    ->label('Rating')
                    ->formatStateUsing(fn (?string $state): string => match ($state) {
                        'up' => '👍',
                        'down' => '👎',
                        default => '—',
                    }),

                Tables\Columns\TextColumn::make('message_count')
                    ->label('Messages')
                    ->state(fn ($record): int => $record->messages()->count())
                    ->badge()
                    ->color('info'),

                Tables\Columns\TextColumn::make('unread_count_admin')
                    ->label('Unread')
                    ->badge()
                    ->color(fn (int $state): string => $state > 0 ? 'danger' : 'gray'),

                Tables\Columns\TextColumn::make('last_message_at')
                    ->label('Last Message')
                    ->dateTime('d-m-Y H:i')
                    ->sortable(),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('Created')
                    ->dateTime('d-m-Y')
                    ->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->options([
                        'open' => 'Open',
                        'waiting_for_user' => 'Waiting for User',
                        'closed' => 'Closed',
                    ]),

                Tables\Filters\SelectFilter::make('thumb')
                    ->label('Rating')
                    ->options([
                        'up' => '👍 Positive',
                        'down' => '👎 Negative',
                    ]),

                Tables\Filters\TernaryFilter::make('has_unread')
                    ->label('Has Unread')
                    ->queries(
                        true: fn ($query) => $query->where('unread_count_admin', '>', 0),
                        false: fn ($query) => $query->where('unread_count_admin', '=', 0),
                    ),
            ])
            ->actions([
                Tables\Actions\Action::make('view')
                    ->label('View')
                    ->icon('heroicon-o-eye')
                    ->url(fn ($record) => MessageThreadResource::getUrl('view', ['record' => $record])),
            ])
            ->bulkActions([])
            ->defaultSort('last_message_at', 'desc')
            ->paginated([10, 25]);
    }
}
