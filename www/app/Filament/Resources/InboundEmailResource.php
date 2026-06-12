<?php

namespace App\Filament\Resources;

use App\Filament\Resources\InboundEmailResource\Pages;
use App\Models\InboundEmail;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class InboundEmailResource extends Resource
{
    protected static ?string $model = InboundEmail::class;

    protected static ?string $navigationIcon = 'heroicon-o-envelope';

    protected static ?string $navigationGroup = 'Messages';

    protected static ?int $navigationSort = 2;

    protected static ?string $navigationLabel = 'Inbound Emails';

    public static function getNavigationBadge(): ?string
    {
        $count = static::getModel()::where('status', InboundEmail::STATUS_FAILED)->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'danger';
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['user']);
    }

    public static function canCreate(): bool
    {
        return false; // Inbound emails are created via webhook only
    }

    public static function canEdit($record): bool
    {
        return false; // Read-only for security
    }

    private static function getStatusColor(string $state): string
    {
        return match ($state) {
            InboundEmail::STATUS_RECEIVED => 'info',
            InboundEmail::STATUS_PROCESSING => 'warning',
            InboundEmail::STATUS_PROCESSED => 'success',
            InboundEmail::STATUS_BOUNCED, InboundEmail::STATUS_FAILED => 'danger',
            default => 'gray',
        };
    }

    private static function getVirusScanColor(string $state): string
    {
        return match ($state) {
            InboundEmail::VIRUS_SCAN_PENDING => 'warning',
            InboundEmail::VIRUS_SCAN_CLEAN => 'success',
            InboundEmail::VIRUS_SCAN_INFECTED => 'danger',
            default => 'gray',
        };
    }

    private static function getActionTypeColor(?string $state): string
    {
        return match ($state) {
            'merge' => 'info',
            'convert' => 'success',
            default => 'gray',
        };
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('id')
                    ->sortable(),

                Tables\Columns\TextColumn::make('to_email')
                    ->label('Recipient')
                    ->searchable()
                    ->sortable()
                    ->copyable()
                    ->description(fn ($record) => $record->action_type ? "Action: {$record->action_type}" : null),

                Tables\Columns\TextColumn::make('user.name')
                    ->label('User')
                    ->description(fn ($record) => $record->user?->email)
                    ->searchable(['users.name', 'users.email'])
                    ->sortable()
                    ->url(fn ($record) => $record->user ? route('filament.admin.resources.users.view', $record->user) : null),

                Tables\Columns\TextColumn::make('action_type')
                    ->label('Action')
                    ->badge()
                    ->color(fn (?string $state): string => self::getActionTypeColor($state))
                    ->formatStateUsing(fn (?string $state): string => $state ? ucfirst($state) : 'N/A'),

                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state): string => self::getStatusColor($state))
                    ->formatStateUsing(fn (string $state): string => ucfirst($state)),

                Tables\Columns\TextColumn::make('virus_scan_status')
                    ->label('Virus Scan')
                    ->badge()
                    ->color(fn (string $state): string => self::getVirusScanColor($state))
                    ->formatStateUsing(fn (string $state): string => ucfirst($state))
                    ->tooltip('Prepared for ClamAV integration'),

                Tables\Columns\TextColumn::make('spam_score')
                    ->label('Spam Score')
                    ->formatStateUsing(fn (?float $state): string => $state !== null ? number_format($state, 2) : 'N/A')
                    ->badge()
                    ->color(fn (?float $state): string => match (true) {
                        $state === null => 'gray',
                        $state < 0.3 => 'success',
                        $state < 0.7 => 'warning',
                        default => 'danger',
                    }),

                Tables\Columns\TextColumn::make('nested_email_count')
                    ->label('Nested')
                    ->badge()
                    ->color(fn (int $state): string => $state > 0 ? 'info' : 'gray')
                    ->formatStateUsing(fn (int $state): string => $state > 0 ? "{$state} email(s)" : '0'),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('Received')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(),

                Tables\Columns\TextColumn::make('processed_at')
                    ->label('Processed')
                    ->dateTime()
                    ->sortable()
                    ->toggleable()
                    ->placeholder('Not processed'),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->options([
                        'received' => 'Received',
                        'processing' => 'Processing',
                        'processed' => 'Processed',
                        'bounced' => 'Bounced',
                        'failed' => 'Failed',
                    ]),

                Tables\Filters\SelectFilter::make('action_type')
                    ->options([
                        'merge' => 'Merge',
                        'convert' => 'Convert',
                    ])
                    ->label('Action'),

                Tables\Filters\SelectFilter::make('virus_scan_status')
                    ->options([
                        'pending' => 'Pending',
                        'clean' => 'Clean',
                        'infected' => 'Infected',
                        'failed' => 'Failed',
                    ])
                    ->label('Virus Scan'),

                Tables\Filters\Filter::make('has_processing_notes')
                    ->query(fn (Builder $query): Builder => $query->whereNotNull('processing_notes'))
                    ->label('Has Processing Notes'),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist
            ->schema([
                Infolists\Components\Section::make('Email Metadata')
                    ->description('Only metadata is visible to admins for security reasons. Email content is encrypted and not accessible.')
                    ->schema([
                        Infolists\Components\TextEntry::make('id')
                            ->label('ID'),

                        Infolists\Components\TextEntry::make('uuid')
                            ->label('UUID')
                            ->copyable(),

                        Infolists\Components\TextEntry::make('message_id')
                            ->label('Message ID')
                            ->copyable(),

                        Infolists\Components\TextEntry::make('to_email')
                            ->label('Recipient Email')
                            ->copyable()
                            ->badge()
                            ->color('info'),

                        Infolists\Components\TextEntry::make('action_type')
                            ->label('Action Type')
                            ->badge()
                            ->color(fn (?string $state): string => self::getActionTypeColor($state)),

                        Infolists\Components\TextEntry::make('user.name')
                            ->label('User')
                            ->description(fn ($record) => $record->user?->email)
                            ->url(fn ($record) => $record->user ? route('filament.admin.resources.users.view', $record->user) : null),

                        Infolists\Components\TextEntry::make('status')
                            ->badge()
                            ->color(fn (string $state): string => self::getStatusColor($state)),

                        Infolists\Components\TextEntry::make('virus_scan_status')
                            ->label('Virus Scan Status')
                            ->badge()
                            ->color(fn (string $state): string => self::getVirusScanColor($state)),

                        Infolists\Components\TextEntry::make('spam_score')
                            ->label('Spam Score')
                            ->formatStateUsing(fn (?float $state): string => $state !== null ? number_format($state, 2) : 'N/A'),

                        Infolists\Components\TextEntry::make('nested_email_count')
                            ->label('Nested Emails')
                            ->formatStateUsing(fn (int $state): string => $state > 0 ? "{$state} email(s)" : 'None'),

                        Infolists\Components\TextEntry::make('created_at')
                            ->label('Received At')
                            ->dateTime(),

                        Infolists\Components\TextEntry::make('processed_at')
                            ->label('Processed At')
                            ->dateTime()
                            ->placeholder('Not processed yet'),
                    ])
                    ->columns(2),

                Infolists\Components\Section::make('Processing Notes')
                    ->description('Debug information and processing logs')
                    ->schema([
                        Infolists\Components\TextEntry::make('processing_notes')
                            ->label('')
                            ->markdown()
                            ->placeholder('No processing notes available')
                            ->columnSpanFull(),
                    ])
                    ->collapsible()
                    ->collapsed(fn ($record) => empty($record->processing_notes)),

                Infolists\Components\Section::make('Security Notice')
                    ->description('For security reasons, the following fields are encrypted and NOT visible to admins:')
                    ->schema([
                        Infolists\Components\ViewEntry::make('security_notice')
                            ->view('filament.infolists.inbound-email-security-notice')
                            ->columnSpanFull(),
                    ])
                    ->collapsed(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListInboundEmails::route('/'),
            'view' => Pages\ViewInboundEmail::route('/{record}'),
        ];
    }
}
