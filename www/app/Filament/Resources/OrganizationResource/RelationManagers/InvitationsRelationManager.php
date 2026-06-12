<?php

namespace App\Filament\Resources\OrganizationResource\RelationManagers;

use App\Filament\Resources\UserResource;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class InvitationsRelationManager extends RelationManager
{
    protected static string $relationship = 'invitations';

    protected static ?string $title = 'Invitations';

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('email')
            ->columns([
                Tables\Columns\TextColumn::make('email')
                    ->label('Invited Email')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('invitedBy.name')
                    ->label('Invited By')
                    ->url(fn ($record) => $record->invited_by
                        ? UserResource::getUrl('view', ['record' => $record->invited_by])
                        : null
                    )
                    ->placeholder('—'),

                Tables\Columns\TextColumn::make('role')
                    ->label('Role')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'admin' => 'danger',
                        'member' => 'info',
                        default => 'gray',
                    })
                    ->formatStateUsing(fn (string $state): string => ucfirst($state)),

                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'pending' => 'warning',
                        'accepted' => 'success',
                        'expired' => 'gray',
                        'revoked' => 'danger',
                        'rejected' => 'danger',
                        default => 'gray',
                    })
                    ->formatStateUsing(fn (string $state): string => ucfirst($state)),

                Tables\Columns\TextColumn::make('expires_at')
                    ->label('Expires')
                    ->dateTime('d-m-Y H:i')
                    ->sortable()
                    ->color(function ($record): string {
                        if ($record->status !== 'pending') {
                            return 'gray';
                        }
                        if ($record->expires_at->isPast()) {
                            return 'danger';
                        }
                        if ($record->expires_at->diffInDays(now()) <= 2) {
                            return 'warning';
                        }

                        return 'success';
                    }),

                Tables\Columns\TextColumn::make('accepted_at')
                    ->label('Accepted')
                    ->dateTime('d-m-Y H:i')
                    ->placeholder('—')
                    ->sortable(),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('Sent')
                    ->dateTime('d-m-Y H:i')
                    ->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->options([
                        'pending' => 'Pending',
                        'accepted' => 'Accepted',
                        'expired' => 'Expired',
                        'revoked' => 'Revoked',
                        'rejected' => 'Rejected',
                    ]),

                Tables\Filters\SelectFilter::make('role')
                    ->options([
                        'admin' => 'Admin',
                        'member' => 'Member',
                    ]),

                Tables\Filters\Filter::make('is_pending_valid')
                    ->label('Pending & Valid')
                    ->query(fn (Builder $query): Builder => $query
                        ->where('status', 'pending')
                        ->where('expires_at', '>', now())
                    )
                    ->toggle(),
            ])
            ->headerActions([
                Tables\Actions\CreateAction::make()
                    ->label('Send Invitation')
                    ->form([
                        Forms\Components\TextInput::make('email')
                            ->label('Email Address')
                            ->email()
                            ->required()
                            ->maxLength(255),

                        Forms\Components\Select::make('role')
                            ->label('Role')
                            ->options([
                                'member' => 'Member',
                                'admin' => 'Admin',
                            ])
                            ->default('member')
                            ->required(),
                    ])
                    ->mutateFormDataUsing(function (array $data): array {
                        $data['invited_by'] = auth()->id();
                        $data['status'] = 'pending';
                        $data['expires_at'] = now()->addDays(7);

                        return $data;
                    })
                    ->after(function () {
                        Notification::make()
                            ->title('Invitation created')
                            ->body('Note: This creates the invitation record but does not send an email automatically.')
                            ->warning()
                            ->send();
                    }),
            ])
            ->actions([
                Tables\Actions\Action::make('extend')
                    ->label('Extend')
                    ->icon('heroicon-o-clock')
                    ->color('info')
                    ->visible(fn ($record) => $record->status === 'pending')
                    ->requiresConfirmation()
                    ->modalHeading('Extend Invitation')
                    ->modalDescription('This will extend the invitation by 7 days from now.')
                    ->action(function ($record): void {
                        $newExpiry = $record->extendExpiration(7);

                        Notification::make()
                            ->title('Invitation extended')
                            ->body('New expiry: '.$newExpiry->format('d-m-Y H:i'))
                            ->success()
                            ->send();
                    }),

                Tables\Actions\Action::make('revoke')
                    ->label('Revoke')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->visible(fn ($record) => $record->status === 'pending')
                    ->requiresConfirmation()
                    ->modalHeading('Revoke Invitation')
                    ->modalDescription('This will permanently revoke this invitation.')
                    ->action(function ($record): void {
                        $record->markAsRevoked();

                        Notification::make()
                            ->title('Invitation revoked')
                            ->success()
                            ->send();
                    }),

                Tables\Actions\Action::make('copyLink')
                    ->label('Copy Link')
                    ->icon('heroicon-o-clipboard-document')
                    ->color('gray')
                    ->visible(fn ($record) => $record->status === 'pending' && ! $record->isExpired())
                    ->action(function ($record): void {
                        $url = route('invitation.accept', ['token' => $record->token]);

                        Notification::make()
                            ->title('Invitation Link')
                            ->body($url)
                            ->success()
                            ->send();
                    }),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ])
            ->defaultSort('created_at', 'desc')
            ->paginated([10, 25]);
    }
}
