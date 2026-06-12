<?php

namespace App\Filament\Resources;

use App\Filament\Resources\UserResource\Pages;
use App\Filament\Resources\UserResource\RelationManagers;
use App\Models\CreditLedger;
use App\Models\License;
use App\Models\User;
use App\Models\UserLicense;
use App\Providers\ProjectServiceProvider;
use App\Services\TwoFactorService;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Symfony\Component\HttpFoundation\StreamedResponse;

class UserResource extends Resource
{
    protected static ?string $model = User::class;

    protected static ?string $navigationIcon = 'heroicon-o-users';

    protected static ?string $navigationGroup = 'Users & Organizations';

    protected static ?int $navigationSort = 1;

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Basic Information')
                    ->schema([
                        Forms\Components\Grid::make(2)
                            ->schema([
                                Forms\Components\TextInput::make('name')
                                    ->required()
                                    ->maxLength(255),

                                Forms\Components\TextInput::make('email')
                                    ->email()
                                    ->required()
                                    ->unique(ignoreRecord: true)
                                    ->maxLength(255),
                            ]),

                        Forms\Components\TextInput::make('password')
                            ->password()
                            ->dehydrateStateUsing(fn ($state) => filled($state) ? bcrypt($state) : null)
                            ->dehydrated(fn ($state) => filled($state))
                            ->required(fn (string $operation): bool => $operation === 'create')
                            ->maxLength(255)
                            ->helperText('Leave empty to keep current password'),
                    ]),

                Forms\Components\Section::make('Credits & Billing')
                    ->schema([
                        Forms\Components\Grid::make(3)
                            ->schema([
                                Forms\Components\TextInput::make('credits')
                                    ->numeric()
                                    ->default(0),

                                Forms\Components\Select::make('billing_country_code')
                                    ->label('Country')
                                    ->options([
                                        'NL' => 'Netherlands',
                                        'DE' => 'Germany',
                                        'FR' => 'France',
                                        'BE' => 'Belgium',
                                        'US' => 'United States',
                                        'GB' => 'United Kingdom',
                                    ])
                                    ->searchable(),

                                Forms\Components\Select::make('currency_preference')
                                    ->label('Currency')
                                    ->options([
                                        'EUR' => 'EUR',
                                        'USD' => 'USD',
                                    ]),
                            ]),

                        Forms\Components\Grid::make(2)
                            ->schema([
                                Forms\Components\TextInput::make('vat_number')
                                    ->label('VAT Number')
                                    ->maxLength(255),

                                Forms\Components\DateTimePicker::make('vat_validated_at')
                                    ->label('VAT Validated At'),
                            ]),
                    ]),

                Forms\Components\Section::make('Email Status')
                    ->schema([
                        Forms\Components\Grid::make(2)
                            ->schema([
                                Forms\Components\DateTimePicker::make('email_verified_at')
                                    ->label('Email Verified At'),

                                Forms\Components\DateTimePicker::make('email_bounced_at')
                                    ->label('Email Bounced At'),
                            ]),

                        Forms\Components\Grid::make(2)
                            ->schema([
                                Forms\Components\TextInput::make('email_bounce_type')
                                    ->label('Bounce Type'),

                                Forms\Components\Textarea::make('email_bounce_reason')
                                    ->label('Bounce Reason')
                                    ->rows(2),
                            ]),
                    ])
                    ->collapsed(),

                Forms\Components\Section::make('Admin')
                    ->schema([
                        Forms\Components\Toggle::make('is_admin')
                            ->label('Admin Access')
                            ->helperText('Grant access to admin panel'),
                    ])
                    ->collapsed(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('id')
                    ->sortable(),

                Tables\Columns\TextColumn::make('name')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('email')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('billing_country_code')
                    ->label('Country')
                    ->sortable(),

                Tables\Columns\TextColumn::make('currency_preference')
                    ->label('Currency'),

                Tables\Columns\TextColumn::make('vat_status')
                    ->label('VAT Status')
                    ->badge()
                    ->state(function (User $record): string {
                        if ($record->vat_validated_at) {
                            return 'Validated';
                        }
                        if ($record->vat_number) {
                            return 'Pending';
                        }

                        return 'None';
                    })
                    ->color(fn (string $state): string => match ($state) {
                        'Validated' => 'success',
                        'Pending' => 'warning',
                        'None' => 'gray',
                        default => 'gray',
                    }),

                Tables\Columns\TextColumn::make('credits')
                    ->numeric()
                    ->sortable(),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('Created')
                    ->dateTime()
                    ->sortable(),

                Tables\Columns\TextColumn::make('last_activity')
                    ->label('Last Activity')
                    ->state(fn (User $record): string => $record->getLastActivityDisplay()),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('billing_country_code')
                    ->label('Country')
                    ->options([
                        'NL' => 'Netherlands',
                        'DE' => 'Germany',
                        'FR' => 'France',
                        'BE' => 'Belgium',
                        'US' => 'United States',
                        'GB' => 'United Kingdom',
                    ]),

                Tables\Filters\SelectFilter::make('currency_preference')
                    ->label('Currency')
                    ->options([
                        'EUR' => 'EUR',
                        'USD' => 'USD',
                    ]),

                Tables\Filters\SelectFilter::make('vat_status')
                    ->label('VAT Status')
                    ->options([
                        'validated' => 'VAT Validated',
                        'pending' => 'VAT Pending',
                        'none' => 'No VAT',
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return match ($data['value']) {
                            'validated' => $query->whereNotNull('vat_validated_at'),
                            'pending' => $query->whereNotNull('vat_number')->whereNull('vat_validated_at'),
                            'none' => $query->whereNull('vat_number'),
                            default => $query,
                        };
                    }),

                Tables\Filters\TernaryFilter::make('has_credits')
                    ->label('Has Credits')
                    ->queries(
                        true: fn (Builder $query) => $query->where('credits', '>', 0),
                        false: fn (Builder $query) => $query->where('credits', '<=', 0),
                    ),

                Tables\Filters\TernaryFilter::make('is_admin')
                    ->label('Admin Users'),

                Tables\Filters\Filter::make('created_at')
                    ->form([
                        Forms\Components\DatePicker::make('created_from'),
                        Forms\Components\DatePicker::make('created_until'),
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
                Tables\Actions\EditAction::make(),

                Tables\Actions\Action::make('addLicense')
                    ->label('Add License')
                    ->icon('heroicon-o-key')
                    ->color('success')
                    ->form([
                        Forms\Components\Select::make('license_id')
                            ->label('License')
                            ->options(License::where('active', true)->pluck('name', 'id'))
                            ->required()
                            ->searchable(),

                        Forms\Components\DateTimePicker::make('starts_at')
                            ->label('Starts At')
                            ->default(now()),

                        Forms\Components\DateTimePicker::make('ends_at')
                            ->label('Ends At'),

                        Forms\Components\Select::make('status')
                            ->options([
                                'active' => 'Active',
                                'inactive' => 'Inactive',
                                'trial' => 'Trial',
                            ])
                            ->default('active')
                            ->required(),
                    ])
                    ->action(function (User $record, array $data): void {
                        $existing = UserLicense::where('user_id', $record->id)
                            ->where('license_id', $data['license_id'])
                            ->first();

                        if ($existing) {
                            Notification::make()
                                ->title('User already has this license')
                                ->danger()
                                ->send();

                            return;
                        }

                        UserLicense::create([
                            'user_id' => $record->id,
                            'license_id' => $data['license_id'],
                            'starts_at' => $data['starts_at'],
                            'ends_at' => $data['ends_at'],
                            'status' => $data['status'],
                            'source' => 'manual',
                        ]);

                        Notification::make()
                            ->title('License added successfully')
                            ->success()
                            ->send();
                    }),

                Tables\Actions\Action::make('grantCredits')
                    ->label('Grant Credits')
                    ->icon('heroicon-o-gift')
                    ->color('warning')
                    ->form([
                        Forms\Components\TextInput::make('amount')
                            ->label('Credits to Add')
                            ->numeric()
                            ->required()
                            ->minValue(1)
                            ->default(100),

                        Forms\Components\Select::make('reason')
                            ->label('Reason')
                            ->options([
                                'bonus' => 'Bonus',
                                'compensation' => 'Compensation',
                                'promotion' => 'Promotion',
                                'refund' => 'Refund',
                                'correction' => 'Correction',
                            ])
                            ->required()
                            ->default('bonus'),

                        Forms\Components\Textarea::make('notes')
                            ->label('Notes (optional)')
                            ->rows(2)
                            ->placeholder('Reason for granting credits...'),
                    ])
                    ->action(function (User $record, array $data): void {
                        $currentBalance = $record->credits ?? 0;
                        $newBalance = $currentBalance + $data['amount'];

                        // Create ledger entry
                        CreditLedger::create([
                            'user_id' => $record->id,
                            'delta' => $data['amount'],
                            'reason' => $data['reason'],
                            'balance_after' => $newBalance,
                            'meta' => [
                                'admin_reason' => $data['notes'] ?? null,
                                'granted_by' => auth()->user()->name ?? 'Admin',
                                'granted_at' => now()->toISOString(),
                            ],
                        ]);

                        // Update user credits
                        $record->update([
                            'credits' => $newBalance,
                            'credits_updated_at' => now(),
                        ]);

                        Notification::make()
                            ->title('Credits granted successfully')
                            ->body("Added {$data['amount']} credits to {$record->name}")
                            ->success()
                            ->send();
                    }),

                Tables\Actions\Action::make('resetTwoFactor')
                    ->label('Reset 2FA')
                    ->icon('heroicon-o-shield-exclamation')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalHeading('Reset Two-Factor Authentication')
                    ->modalDescription('This will disable two-factor authentication for this user. They will need to set it up again.')
                    ->form([
                        Forms\Components\Textarea::make('reason')
                            ->label('Reason for reset')
                            ->required()
                            ->rows(2)
                            ->placeholder('Why is 2FA being reset for this user?'),
                    ])
                    ->visible(fn (User $record): bool => $record->hasTwoFactorEnabled())
                    ->action(function (User $record, array $data): void {
                        $twoFactorService = app(TwoFactorService::class);
                        $twoFactorService->resetTwoFactor($record, auth()->user(), $data['reason']);

                        Notification::make()
                            ->title('Two-Factor Authentication Reset')
                            ->body("2FA has been disabled for {$record->name}")
                            ->success()
                            ->send();
                    }),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),

                    Tables\Actions\BulkAction::make('export')
                        ->label('Export to CSV')
                        ->icon('heroicon-o-arrow-down-tray')
                        ->action(function ($records): StreamedResponse {
                            return response()->streamDownload(function () use ($records) {
                                $handle = fopen('php://output', 'w');

                                fputcsv($handle, [
                                    'ID', 'Name', 'Email', 'Country', 'Currency', 'VAT Number',
                                    'VAT Validated', 'Credits', 'Created At', 'Email Verified',
                                ]);

                                foreach ($records as $user) {
                                    fputcsv($handle, [
                                        $user->id,
                                        $user->name,
                                        $user->email,
                                        $user->billing_country_code,
                                        $user->currency_preference,
                                        $user->vat_number,
                                        $user->vat_validated_at ? 'Yes' : 'No',
                                        $user->credits,
                                        $user->created_at?->format('Y-m-d H:i:s'),
                                        $user->email_verified_at ? 'Yes' : 'No',
                                    ]);
                                }

                                fclose($handle);
                            }, 'users_export_'.date('Y-m-d_H-i-s').'.csv');
                        }),
                ]),
            ])
            ->defaultSort('created_at', 'desc')
            ->defaultPaginationPageOption(25)
            ->paginationPageOptions([10, 25, 50]);
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist
            ->schema([
                Infolists\Components\Section::make('Profile')
                    ->schema([
                        Infolists\Components\Grid::make(3)
                            ->schema([
                                Infolists\Components\TextEntry::make('id')
                                    ->label('ID'),
                                Infolists\Components\TextEntry::make('name'),
                                Infolists\Components\TextEntry::make('email'),
                            ]),

                        Infolists\Components\Grid::make(3)
                            ->schema([
                                Infolists\Components\TextEntry::make('email_status')
                                    ->label('Email Status')
                                    ->badge()
                                    ->state(fn (User $record): string => $record->email_verified_at ? 'Verified' : 'Unverified')
                                    ->color(fn (string $state): string => $state === 'Verified' ? 'success' : 'warning'),

                                Infolists\Components\TextEntry::make('created_at')
                                    ->label('Registered')
                                    ->dateTime(),

                                Infolists\Components\TextEntry::make('last_activity')
                                    ->label('Last Activity')
                                    ->state(fn (User $record): string => $record->getLastActivityDisplay()),
                            ]),
                    ]),

                Infolists\Components\Section::make('Billing & Credits')
                    ->schema([
                        Infolists\Components\Grid::make(4)
                            ->schema([
                                Infolists\Components\TextEntry::make('credits')
                                    ->badge()
                                    ->color(fn (int $state): string => match (true) {
                                        $state > 100 => 'success',
                                        $state > 10 => 'warning',
                                        default => 'danger',
                                    }),

                                Infolists\Components\TextEntry::make('billing_country_code')
                                    ->label('Country'),

                                Infolists\Components\TextEntry::make('currency_preference')
                                    ->label('Currency'),

                                Infolists\Components\TextEntry::make('vat_status')
                                    ->label('VAT Status')
                                    ->badge()
                                    ->state(function (User $record): string {
                                        if ($record->vat_validated_at) {
                                            return 'Validated';
                                        }
                                        if ($record->vat_number) {
                                            return 'Pending';
                                        }

                                        return 'None';
                                    })
                                    ->color(fn (string $state): string => match ($state) {
                                        'Validated' => 'success',
                                        'Pending' => 'warning',
                                        default => 'gray',
                                    }),
                            ]),

                        Infolists\Components\TextEntry::make('vat_number')
                            ->label('VAT Number')
                            ->placeholder('Not provided'),
                    ]),

                Infolists\Components\Section::make('Two-Factor Authentication')
                    ->schema([
                        Infolists\Components\Grid::make(3)
                            ->schema([
                                Infolists\Components\TextEntry::make('two_factor_status')
                                    ->label('Status')
                                    ->badge()
                                    ->state(fn (User $record): string => $record->hasTwoFactorEnabled() ? 'Enabled' : 'Disabled')
                                    ->color(fn (string $state): string => $state === 'Enabled' ? 'success' : 'gray'),

                                Infolists\Components\TextEntry::make('two_factor_confirmed_at')
                                    ->label('Enabled Since')
                                    ->dateTime()
                                    ->placeholder('Never'),

                                Infolists\Components\TextEntry::make('recovery_codes_remaining')
                                    ->label('Recovery Codes Remaining')
                                    ->state(fn (User $record): string => $record->hasTwoFactorEnabled()
                                        ? $record->getTwoFactorRecoveryCodes()->count() . ' codes'
                                        : '-'),
                            ]),
                    ]),
            ]);
    }

    public static function getRelations(): array
    {
        return array_merge([
            // Base relations
            RelationManagers\OrganizationsRelationManager::class,
            RelationManagers\UserLicensesRelationManager::class,
            RelationManagers\OrdersRelationManager::class,
            RelationManagers\CreditLedgerRelationManager::class,
            RelationManagers\AnalyticsEventsRelationManager::class,
            RelationManagers\MessageThreadsRelationManager::class,
        ], ProjectServiceProvider::getExtraRelations('user'));
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListUsers::route('/'),
            'create' => Pages\CreateUser::route('/create'),
            'view' => Pages\ViewUser::route('/{record}'),
            'edit' => Pages\EditUser::route('/{record}/edit'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery();
    }
}
