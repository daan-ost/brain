<?php

namespace App\Filament\Resources;

use App\Filament\Resources\MessageThreadResource\Pages;
use App\Models\MessageThread;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class MessageThreadResource extends Resource
{
    protected static ?string $model = MessageThread::class;

    protected static ?string $navigationIcon = 'heroicon-o-chat-bubble-left-right';

    protected static ?string $navigationGroup = 'Support';

    protected static ?int $navigationSort = 1;

    protected static ?string $navigationLabel = 'Message Threads';

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
        return $form->schema([]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('id')
                    ->sortable(),

                Tables\Columns\TextColumn::make('user.name')
                    ->label('User')
                    ->description(fn ($record) => $record->user?->email)
                    ->searchable(['users.name', 'users.email'])
                    ->sortable(),

                Tables\Columns\TextColumn::make('category.name')
                    ->label('Category')
                    ->placeholder('N/A'),

                Tables\Columns\TextColumn::make('title')
                    ->limit(40)
                    ->searchable(),

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
                    ->label('Thumb')
                    ->formatStateUsing(fn (?string $state): string => match ($state) {
                        'up' => '👍',
                        'down' => '👎',
                        default => '—',
                    }),

                Tables\Columns\TextColumn::make('unread_count_admin')
                    ->label('Inbox')
                    ->badge()
                    ->color(fn (int $state): string => $state > 0 ? 'danger' : 'gray')
                    ->formatStateUsing(fn (int $state): string => $state > 0 ? "{$state} new" : '0'),

                Tables\Columns\TextColumn::make('user_read_status')
                    ->label('User Read')
                    ->state(function (MessageThread $record): string {
                        if ($record->last_message_from === 'admin' || $record->status === 'waiting_for_user') {
                            return $record->unread_count_user > 0 ? 'Not yet' : 'Yes';
                        }

                        return '—';
                    })
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'Yes' => 'success',
                        'Not yet' => 'warning',
                        default => 'gray',
                    }),

                Tables\Columns\TextColumn::make('last_message_at')
                    ->label('Last Message')
                    ->dateTime()
                    ->sortable(),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('Created')
                    ->dateTime()
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
                    ->options([
                        'up' => '👍 Thumbs Up',
                        'down' => '👎 Thumbs Down',
                    ]),

                Tables\Filters\TernaryFilter::make('has_unread')
                    ->label('Has Unread')
                    ->queries(
                        true: fn (Builder $query) => $query->where('unread_count_admin', '>', 0),
                        false: fn (Builder $query) => $query->where('unread_count_admin', '=', 0),
                    ),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),

                Tables\Actions\Action::make('updateStatus')
                    ->label('Status')
                    ->icon('heroicon-o-arrow-path')
                    ->form([
                        Forms\Components\Select::make('status')
                            ->options([
                                'open' => 'Open',
                                'waiting_for_user' => 'Waiting for User',
                                'closed' => 'Closed',
                            ])
                            ->required(),
                    ])
                    ->action(function (MessageThread $record, array $data): void {
                        $record->update(['status' => $data['status']]);

                        Notification::make()
                            ->title('Status updated')
                            ->success()
                            ->send();
                    }),
            ])
            ->bulkActions([])
            ->defaultSort('last_message_at', 'desc')
            ->defaultPaginationPageOption(25)
            ->paginationPageOptions([10, 25, 50])
            ->recordUrl(fn (MessageThread $record): string => static::getUrl('view', ['record' => $record]));
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist
            ->schema([
                Infolists\Components\Section::make('Thread Information')
                    ->schema([
                        Infolists\Components\Grid::make(4)
                            ->schema([
                                Infolists\Components\TextEntry::make('id'),

                                Infolists\Components\TextEntry::make('status')
                                    ->badge()
                                    ->color(fn (string $state): string => match ($state) {
                                        'open' => 'success',
                                        'waiting_for_user' => 'info',
                                        'closed' => 'gray',
                                        default => 'gray',
                                    }),

                                Infolists\Components\TextEntry::make('thumb')
                                    ->formatStateUsing(fn (?string $state): string => match ($state) {
                                        'up' => '👍 Thumbs Up',
                                        'down' => '👎 Thumbs Down',
                                        default => 'None',
                                    }),

                                Infolists\Components\TextEntry::make('source')
                                    ->placeholder('—'),
                            ]),

                        Infolists\Components\Grid::make(2)
                            ->schema([
                                Infolists\Components\TextEntry::make('user.name')
                                    ->label('User')
                                    ->url(fn ($record) => $record->user ? UserResource::getUrl('view', ['record' => $record->user]) : null),

                                Infolists\Components\TextEntry::make('user.email')
                                    ->label('Email'),
                            ]),

                        Infolists\Components\TextEntry::make('title')
                            ->columnSpanFull(),

                        Infolists\Components\TextEntry::make('trustpilot_invite_sent_at')
                            ->label('Trustpilot-uitnodiging verstuurd')
                            ->state(fn ($record): ?string => $record->context_json['trustpilot_invite_sent_at'] ?? null)
                            ->dateTime()
                            ->badge()
                            ->color('success')
                            ->visible(fn ($record): bool => ! empty($record->context_json['trustpilot_invite_sent_at']))
                            ->columnSpanFull(),
                    ]),

                Infolists\Components\Section::make('Messages')
                    ->schema([
                        Infolists\Components\RepeatableEntry::make('messages')
                            ->schema([
                                Infolists\Components\Grid::make(4)
                                    ->schema([
                                        Infolists\Components\TextEntry::make('sender_type')
                                            ->badge()
                                            ->color(fn (string $state): string => match ($state) {
                                                'admin' => 'danger',
                                                'user' => 'info',
                                                'llm' => 'warning',
                                                'system' => 'gray',
                                                default => 'gray',
                                            }),

                                        Infolists\Components\TextEntry::make('created_at')
                                            ->dateTime(),

                                        Infolists\Components\IconEntry::make('is_read')
                                            ->label('Read')
                                            ->boolean(),
                                    ]),

                                Infolists\Components\TextEntry::make('content')
                                    ->markdown()
                                    ->columnSpanFull(),

                                // Attachments section
                                Infolists\Components\TextEntry::make('attachments_display')
                                    ->label('Attachments')
                                    ->state(function ($record): string {
                                        if (empty($record->attachments)) {
                                            return '';
                                        }

                                        $links = [];
                                        foreach ($record->attachments as $attachment) {
                                            $name = $attachment['original_name'] ?? basename($attachment['path']);
                                            $url = \URL::temporarySignedRoute(
                                                'thread.attachment.download',
                                                now()->addMinutes(15),
                                                ['path' => base64_encode($attachment['path'])]
                                            );
                                            $links[] = "[{$name}]({$url})";
                                        }

                                        return implode(' | ', $links);
                                    })
                                    ->markdown()
                                    ->visible(fn ($record): bool => ! empty($record->attachments))
                                    ->columnSpanFull(),
                            ])
                            ->columnSpanFull(),
                    ]),
            ]);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListMessageThreads::route('/'),
            'view' => Pages\ViewMessageThread::route('/{record}'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->with(['user', 'category']);
    }

    public static function getNavigationBadge(): ?string
    {
        $count = static::getModel()::where('unread_count_admin', '>', 0)->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'danger';
    }
}
