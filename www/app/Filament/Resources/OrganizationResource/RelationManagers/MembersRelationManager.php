<?php

namespace App\Filament\Resources\OrganizationResource\RelationManagers;

use App\Models\User;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

class MembersRelationManager extends RelationManager
{
    protected static string $relationship = 'users';

    protected static ?string $title = 'Members';

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Select::make('user_id')
                    ->label('User')
                    ->options(User::pluck('email', 'id'))
                    ->searchable()
                    ->required(),

                Forms\Components\Select::make('role')
                    ->options([
                        'admin' => 'Admin',
                        'member' => 'Member',
                    ])
                    ->default('member')
                    ->required(),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('email')
                    ->searchable(),

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
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('role')
                    ->options([
                        'admin' => 'Admin',
                        'member' => 'Member',
                    ])
                    ->query(function ($query, array $data) {
                        if ($data['value']) {
                            return $query->wherePivot('role', $data['value']);
                        }

                        return $query;
                    }),
            ])
            ->headerActions([
                Tables\Actions\Action::make('addMember')
                    ->label('Add Member')
                    ->form([
                        Forms\Components\TextInput::make('email')
                            ->label('User Email')
                            ->email()
                            ->required(),

                        Forms\Components\Select::make('role')
                            ->options([
                                'admin' => 'Admin',
                                'member' => 'Member',
                            ])
                            ->default('member')
                            ->required(),
                    ])
                    ->action(function (array $data): void {
                        $user = User::where('email', $data['email'])->first();

                        if (! $user) {
                            Notification::make()
                                ->title('User not found')
                                ->body('No user exists with that email address.')
                                ->danger()
                                ->send();

                            return;
                        }

                        $organization = $this->getOwnerRecord();

                        if ($organization->users()->where('user_id', $user->id)->exists()) {
                            Notification::make()
                                ->title('User already a member')
                                ->danger()
                                ->send();

                            return;
                        }

                        $organization->users()->attach($user->id, [
                            'role' => $data['role'],
                            'joined_at' => now(),
                        ]);

                        Notification::make()
                            ->title('Member added successfully')
                            ->success()
                            ->send();
                    }),
            ])
            ->actions([
                Tables\Actions\Action::make('changeRole')
                    ->label('Change Role')
                    ->icon('heroicon-o-arrow-path')
                    ->form([
                        Forms\Components\Select::make('role')
                            ->options([
                                'admin' => 'Admin',
                                'member' => 'Member',
                            ])
                            ->required(),
                    ])
                    ->action(function ($record, array $data): void {
                        $this->getOwnerRecord()->users()->updateExistingPivot($record->id, [
                            'role' => $data['role'],
                        ]);

                        Notification::make()
                            ->title('Role updated')
                            ->success()
                            ->send();
                    }),

                Tables\Actions\Action::make('removeMember')
                    ->label('Remove')
                    ->icon('heroicon-o-trash')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->action(function ($record): void {
                        $this->getOwnerRecord()->users()->detach($record->id);

                        Notification::make()
                            ->title('Member removed')
                            ->success()
                            ->send();
                    }),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\BulkAction::make('removeMembers')
                        ->label('Remove Selected')
                        ->icon('heroicon-o-trash')
                        ->color('danger')
                        ->requiresConfirmation()
                        ->action(function ($records): void {
                            $organization = $this->getOwnerRecord();
                            foreach ($records as $record) {
                                $organization->users()->detach($record->id);
                            }

                            Notification::make()
                                ->title('Members removed')
                                ->success()
                                ->send();
                        }),
                ]),
            ]);
    }
}
