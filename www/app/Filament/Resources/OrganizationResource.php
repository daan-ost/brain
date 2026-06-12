<?php

namespace App\Filament\Resources;

use App\Filament\Resources\OrganizationResource\Pages;
use App\Filament\Resources\OrganizationResource\RelationManagers;
use App\Models\Organization;
use App\Providers\ProjectServiceProvider;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class OrganizationResource extends Resource
{
    protected static ?string $model = Organization::class;

    protected static ?string $navigationIcon = 'heroicon-o-building-office';

    protected static ?string $navigationGroup = 'Users & Organizations';

    protected static ?int $navigationSort = 2;

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

                                Forms\Components\TextInput::make('slug')
                                    ->maxLength(255)
                                    ->unique(ignoreRecord: true)
                                    ->helperText('Auto-generated if left empty'),
                            ]),
                    ]),

                Forms\Components\Section::make('Billing')
                    ->schema([
                        Forms\Components\Grid::make(3)
                            ->schema([
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

                                Forms\Components\TextInput::make('vat_number')
                                    ->label('VAT Number')
                                    ->maxLength(255),
                            ]),

                        Forms\Components\DateTimePicker::make('vat_validated_at')
                            ->label('VAT Validated At'),
                    ]),

                Forms\Components\Section::make('Settings')
                    ->schema([
                        Forms\Components\Toggle::make('is_trusted')
                            ->label('Trusted Organization')
                            ->helperText('Trusted organizations get special privileges'),

                        Forms\Components\Textarea::make('settings')
                            ->label('Settings (JSON)')
                            ->rows(4)
                            ->helperText('Optional JSON settings'),
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

                Tables\Columns\TextColumn::make('member_count')
                    ->label('Members')
                    ->state(fn (Organization $record): int => $record->users()->count())
                    ->badge()
                    ->color('info'),

                Tables\Columns\TextColumn::make('domain_count')
                    ->label('Domains')
                    ->state(fn (Organization $record): int => $record->domains()->count())
                    ->badge()
                    ->color('warning'),

                Tables\Columns\TextColumn::make('license_count')
                    ->label('Licenses')
                    ->state(fn (Organization $record): int => $record->organizationLicenses()->count())
                    ->badge()
                    ->color('success'),

                Tables\Columns\TextColumn::make('creditPool.balance')
                    ->label('Credits')
                    ->numeric()
                    ->default(0),

                Tables\Columns\TextColumn::make('vat_status')
                    ->label('VAT')
                    ->badge()
                    ->state(function (Organization $record): string {
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

                Tables\Columns\IconColumn::make('is_trusted')
                    ->label('Trusted')
                    ->boolean(),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('Created')
                    ->dateTime()
                    ->sortable(),
            ])
            ->filters([
                Tables\Filters\TernaryFilter::make('is_trusted')
                    ->label('Trusted'),

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

                Tables\Filters\SelectFilter::make('vat_status')
                    ->label('VAT Status')
                    ->options([
                        'validated' => 'Validated',
                        'pending' => 'Pending',
                        'none' => 'None',
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return match ($data['value']) {
                            'validated' => $query->whereNotNull('vat_validated_at'),
                            'pending' => $query->whereNotNull('vat_number')->whereNull('vat_validated_at'),
                            'none' => $query->whereNull('vat_number'),
                            default => $query,
                        };
                    }),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
                Tables\Actions\EditAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
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
                Infolists\Components\Section::make('Organization Details')
                    ->schema([
                        Infolists\Components\Grid::make(3)
                            ->schema([
                                Infolists\Components\TextEntry::make('id'),
                                Infolists\Components\TextEntry::make('name'),
                                Infolists\Components\TextEntry::make('slug'),
                            ]),

                        Infolists\Components\Grid::make(4)
                            ->schema([
                                Infolists\Components\TextEntry::make('member_count')
                                    ->label('Members')
                                    ->state(fn (Organization $record): int => $record->users()->count())
                                    ->badge()
                                    ->color('info'),

                                Infolists\Components\TextEntry::make('domain_count')
                                    ->label('Domains')
                                    ->state(fn (Organization $record): int => $record->domains()->count())
                                    ->badge()
                                    ->color('warning'),

                                Infolists\Components\TextEntry::make('license_count')
                                    ->label('Licenses')
                                    ->state(fn (Organization $record): int => $record->organizationLicenses()->count())
                                    ->badge()
                                    ->color('success'),

                                Infolists\Components\TextEntry::make('creditPool.balance')
                                    ->label('Credit Balance')
                                    ->numeric()
                                    ->default(0),
                            ]),
                    ]),

                Infolists\Components\Section::make('Billing Information')
                    ->schema([
                        Infolists\Components\Grid::make(4)
                            ->schema([
                                Infolists\Components\TextEntry::make('billing_country_code')
                                    ->label('Country'),

                                Infolists\Components\TextEntry::make('currency_preference')
                                    ->label('Currency'),

                                Infolists\Components\TextEntry::make('vat_number')
                                    ->label('VAT Number')
                                    ->placeholder('Not provided'),

                                Infolists\Components\TextEntry::make('vat_status')
                                    ->label('VAT Status')
                                    ->badge()
                                    ->state(function (Organization $record): string {
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
                    ]),

                Infolists\Components\Section::make('Status')
                    ->schema([
                        Infolists\Components\Grid::make(2)
                            ->schema([
                                Infolists\Components\IconEntry::make('is_trusted')
                                    ->label('Trusted Organization')
                                    ->boolean(),

                                Infolists\Components\TextEntry::make('created_at')
                                    ->label('Created')
                                    ->dateTime(),
                            ]),
                    ]),

                Infolists\Components\Section::make('Sender Email Config')
                    ->schema([
                        Infolists\Components\Grid::make(4)
                            ->schema([
                                Infolists\Components\TextEntry::make('senderConfig.sender_level')
                                    ->label('Level')
                                    ->badge()
                                    ->formatStateUsing(fn ($state): string => $state instanceof \App\Enums\SenderLevel ? $state->label() : '-')
                                    ->color(fn ($state): string => $state instanceof \App\Enums\SenderLevel ? $state->badgeColor() : 'gray'),

                                Infolists\Components\TextEntry::make('senderConfig.status')
                                    ->label('Status')
                                    ->badge()
                                    ->formatStateUsing(fn ($state): string => $state instanceof \App\Enums\SenderConfigStatus ? $state->label() : '-')
                                    ->color(fn ($state): string => $state instanceof \App\Enums\SenderConfigStatus ? $state->badgeColor() : 'gray'),

                                Infolists\Components\TextEntry::make('senderConfig.from_email')
                                    ->label('From Email')
                                    ->placeholder('—'),

                                Infolists\Components\TextEntry::make('senderConfig.domain')
                                    ->label('Domain')
                                    ->placeholder('—'),
                            ]),
                        Infolists\Components\Grid::make(2)
                            ->schema([
                                Infolists\Components\TextEntry::make('senderConfig.verified_at')
                                    ->label('Verified')
                                    ->dateTime()
                                    ->placeholder('Not verified'),

                                Infolists\Components\TextEntry::make('senderConfig.failure_reason')
                                    ->label('Failure Reason')
                                    ->placeholder('—'),
                            ]),
                    ])
                    ->visible(fn (Organization $record): bool => $record->senderConfig !== null),
            ]);
    }

    public static function getRelations(): array
    {
        return array_merge([
            // Base relations
            RelationManagers\MembersRelationManager::class,
            RelationManagers\DomainsRelationManager::class,
            RelationManagers\OrganizationLicensesRelationManager::class,
            RelationManagers\OrdersRelationManager::class,
            RelationManagers\CreditLedgerRelationManager::class,
            RelationManagers\InvitationsRelationManager::class,
            RelationManagers\SenderConfigRelationManager::class,
        ], ProjectServiceProvider::getExtraRelations('organization'));
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListOrganizations::route('/'),
            'create' => Pages\CreateOrganization::route('/create'),
            'view' => Pages\ViewOrganization::route('/{record}'),
            'edit' => Pages\EditOrganization::route('/{record}/edit'),
        ];
    }
}
