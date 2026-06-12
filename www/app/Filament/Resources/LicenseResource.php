<?php

namespace App\Filament\Resources;

use App\Filament\Resources\LicenseResource\Pages;
use App\Filament\Resources\LicenseResource\RelationManagers;
use App\Models\License;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Filament Resource for License Management
 *
 * Provides CRUD operations for managing licenses in the admin panel.
 * Includes structured display of JSON restrictions (upload limits, features, content restrictions).
 * Validates JSON input on edit form and displays restrictions in organized sections on view page.
 */
class LicenseResource extends Resource
{
    protected static ?string $model = License::class;

    protected static ?string $navigationIcon = 'heroicon-o-key';

    protected static ?string $navigationGroup = 'Licensing';

    protected static ?int $navigationSort = 1;

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->withCount(['userLicenses', 'organizationLicenses']);
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Basic Information')
                    ->schema([
                        Forms\Components\Grid::make(2)
                            ->schema([
                                Forms\Components\TextInput::make('name')
                                    ->label('License Name')
                                    ->required()
                                    ->maxLength(255),

                                Forms\Components\TextInput::make('slug')
                                    ->label('Slug')
                                    ->required()
                                    ->unique(ignoreRecord: true)
                                    ->maxLength(255)
                                    ->helperText('Unique identifier for this license'),
                            ]),

                        Forms\Components\Select::make('tier')
                            ->label('Tier')
                            ->options([
                                'free' => 'Free',
                                'onetime' => 'One-time',
                                'premium' => 'Premium',
                                'test' => 'Test',
                                'enterprise' => 'Enterprise',
                                'custom' => 'Custom',
                            ])
                            ->required(),
                    ]),

                Forms\Components\Section::make('Pricing')
                    ->schema([
                        Forms\Components\Grid::make(3)
                            ->schema([
                                Forms\Components\TextInput::make('amount')
                                    ->label('Price')
                                    ->numeric()
                                    ->prefix('€')
                                    ->required()
                                    ->minValue(0)
                                    ->step(0.01),

                                Forms\Components\Select::make('currency')
                                    ->label('Currency')
                                    ->options([
                                        'EUR' => 'EUR',
                                        'USD' => 'USD',
                                    ])
                                    ->default('EUR')
                                    ->required(),

                                Forms\Components\Select::make('billing_cycle')
                                    ->label('Billing Cycle')
                                    ->options([
                                        'monthly' => 'Monthly',
                                        'yearly' => 'Yearly',
                                        'one_time' => 'One-time',
                                    ])
                                    ->required(),
                            ]),

                        Forms\Components\Placeholder::make('scheduled_price_change')
                            ->label('Scheduled Price Change')
                            ->content(function ($record) {
                                if (! $record || ! $record->upcoming_amount) {
                                    return null;
                                }

                                return new \Illuminate\Support\HtmlString(
                                    '<div class="p-3 bg-warning-50 border border-warning-200 rounded-lg">'.
                                    '<strong class="text-warning-700">Scheduled change:</strong> '.
                                    $record->currency.' '.number_format($record->upcoming_amount, 2).
                                    ($record->upcoming_credits ? ' ('.$record->upcoming_credits.' credits)' : '').
                                    ' from '.$record->price_effective_from?->format('d-m-Y').
                                    '</div>'
                                );
                            })
                            ->visible(fn ($record) => $record?->upcoming_amount !== null),
                    ]),

                Forms\Components\Section::make('Credits Configuration')
                    ->schema([
                        Forms\Components\Grid::make(3)
                            ->schema([
                                Forms\Components\TextInput::make('credits')
                                    ->label('Credits')
                                    ->numeric()
                                    ->required()
                                    ->minValue(0)
                                    ->helperText('Number of credits included'),

                                Forms\Components\Select::make('credit_reset_interval')
                                    ->label('Credit Reset Interval')
                                    ->options([
                                        'none' => 'None',
                                        'monthly' => 'Monthly',
                                        'yearly' => 'Yearly',
                                    ])
                                    ->required(),

                                Forms\Components\TextInput::make('period')
                                    ->label('Period (days)')
                                    ->numeric()
                                    ->minValue(1)
                                    ->helperText('License validity period in days'),
                            ]),
                    ]),

                Forms\Components\Section::make('Restrictions & Features')
                    ->schema([
                        Forms\Components\Textarea::make('json_restrictions')
                            ->label('Restrictions (JSON)')
                            ->rows(10)
                            ->rules(['nullable', 'json'])
                            ->formatStateUsing(fn ($state) => is_array($state) ? json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) : $state)
                            ->helperText('Enter valid JSON or leave empty'),
                    ])
                    ->collapsed(),

                Forms\Components\Section::make('Status & Validity')
                    ->schema([
                        Forms\Components\Grid::make(3)
                            ->schema([
                                Forms\Components\TextInput::make('ordering')
                                    ->label('Display Order')
                                    ->numeric()
                                    ->default(0)
                                    ->required(),

                                Forms\Components\DatePicker::make('valid_from')
                                    ->label('Valid From')
                                    ->helperText('Leave empty for no start date'),

                                Forms\Components\DatePicker::make('valid_until')
                                    ->label('Valid Until')
                                    ->helperText('Leave empty for no end date'),
                            ]),

                        Forms\Components\Toggle::make('active')
                            ->label('Active')
                            ->default(true),
                    ]),

                Forms\Components\Section::make('Payment Provider')
                    ->schema([
                        Forms\Components\Select::make('payment_provider')
                            ->label('Payment Provider')
                            ->options([
                                'mollie' => 'Mollie',
                                'stripe' => 'Stripe',
                            ])
                            ->placeholder('Default (env)')
                            ->helperText('Leave empty to use PAYMENT_DEFAULT_PROVIDER from .env'),

                        Forms\Components\Grid::make(2)
                            ->schema([
                                Forms\Components\TextInput::make('stripe_product_id')
                                    ->label('Stripe Product ID')
                                    ->disabled()
                                    ->placeholder('Synced via stripe:sync-prices')
                                    ->helperText('Auto-filled when syncing to Stripe'),

                                Forms\Components\TextInput::make('stripe_price_id')
                                    ->label('Stripe Price ID')
                                    ->disabled()
                                    ->placeholder('Synced via stripe:sync-prices')
                                    ->helperText('Auto-filled when syncing to Stripe'),

                                Forms\Components\Placeholder::make('stripe_setup_warning')
                                    ->label('')
                                    ->content('⚠️ Klanten kunnen deze License niet kopen totdat Stripe Price ID is gevuld. Run `php artisan stripe:sync-prices` of klik "Sync naar Stripe" op de Edit-pagina.')
                                    ->visible(fn ($get) => $get('payment_provider') === 'stripe' && empty($get('stripe_price_id')))
                                    ->columnSpanFull(),
                            ])
                            ->visible(fn ($get) => $get('payment_provider') === 'stripe'),
                    ])
                    ->collapsed(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label('License Name')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('tier')
                    ->label('Tier')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'free' => 'gray',
                        'onetime' => 'info',
                        'premium' => 'success',
                        'enterprise' => 'warning',
                        'test' => 'gray',
                        'custom' => 'primary',
                        default => 'gray',
                    }),

                Tables\Columns\TextColumn::make('price')
                    ->label('Price')
                    ->state(fn (License $record): string => $record->currency.' '.number_format($record->amount, 2)
                    )
                    ->sortable(query: fn (Builder $query, string $direction) => $query->orderBy('amount', $direction)
                    ),

                Tables\Columns\TextColumn::make('billing_cycle')
                    ->label('Billing Cycle')
                    ->formatStateUsing(fn (string $state): string => ucfirst(str_replace('_', ' ', $state))),

                Tables\Columns\TextColumn::make('credit_reset_interval')
                    ->label('Credit Reset')
                    ->formatStateUsing(fn (string $state): string => ucfirst($state)),

                Tables\Columns\TextColumn::make('credits')
                    ->label('Credits')
                    ->numeric()
                    ->sortable(),

                Tables\Columns\TextColumn::make('user_licenses_count')
                    ->label('#Users')
                    ->badge()
                    ->color('info')
                    ->sortable(),

                Tables\Columns\TextColumn::make('organization_licenses_count')
                    ->label('#Orgs')
                    ->badge()
                    ->color('warning')
                    ->sortable(),

                Tables\Columns\TextColumn::make('valid_period')
                    ->label('Valid Period')
                    ->state(function (License $record): string {
                        $from = $record->valid_from?->format('Y-m-d') ?? '∞';
                        $until = $record->valid_until?->format('Y-m-d') ?? '∞';

                        return "{$from} → {$until}";
                    }),

                Tables\Columns\IconColumn::make('active')
                    ->label('Active')
                    ->boolean(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('tier')
                    ->options([
                        'free' => 'Free',
                        'onetime' => 'One-time',
                        'premium' => 'Premium',
                        'test' => 'Test',
                        'enterprise' => 'Enterprise',
                        'custom' => 'Custom',
                    ]),

                Tables\Filters\SelectFilter::make('currency')
                    ->options([
                        'EUR' => 'EUR',
                        'USD' => 'USD',
                    ]),

                Tables\Filters\SelectFilter::make('billing_cycle')
                    ->options([
                        'monthly' => 'Monthly',
                        'yearly' => 'Yearly',
                        'one_time' => 'One-time',
                    ]),

                Tables\Filters\TernaryFilter::make('active')
                    ->label('Active Status'),

                Tables\Filters\SelectFilter::make('validity')
                    ->label('Validity Period')
                    ->options([
                        'current' => 'Currently Valid',
                        'future' => 'Future',
                        'expired' => 'Expired',
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        $now = now();

                        return match ($data['value']) {
                            'current' => $query
                                ->where(fn ($q) => $q->whereNull('valid_from')->orWhere('valid_from', '<=', $now))
                                ->where(fn ($q) => $q->whereNull('valid_until')->orWhere('valid_until', '>=', $now)),
                            'future' => $query->where('valid_from', '>', $now),
                            'expired' => $query->where('valid_until', '<', $now),
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
            ->defaultSort('ordering', 'asc')
            ->defaultPaginationPageOption(25)
            ->paginationPageOptions([10, 25, 50]);
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist
            ->schema([
                Infolists\Components\Section::make('License Details')
                    ->schema([
                        Infolists\Components\Grid::make(3)
                            ->schema([
                                Infolists\Components\TextEntry::make('name')
                                    ->label('License Name'),
                                Infolists\Components\TextEntry::make('slug')
                                    ->label('Slug'),
                                Infolists\Components\TextEntry::make('tier')
                                    ->label('Tier')
                                    ->badge()
                                    ->color(fn (string $state): string => match ($state) {
                                        'free' => 'gray',
                                        'onetime' => 'info',
                                        'premium' => 'success',
                                        'enterprise' => 'warning',
                                        default => 'gray',
                                    }),
                            ]),

                        Infolists\Components\Grid::make(4)
                            ->schema([
                                Infolists\Components\TextEntry::make('price_display')
                                    ->label('Price')
                                    ->state(fn (License $record): string => $record->currency.' '.number_format($record->amount, 2)
                                    ),
                                Infolists\Components\TextEntry::make('billing_cycle')
                                    ->label('Billing Cycle')
                                    ->formatStateUsing(fn (string $state): string => ucfirst(str_replace('_', ' ', $state))),
                                Infolists\Components\TextEntry::make('credits')
                                    ->label('Credits'),
                                Infolists\Components\TextEntry::make('credit_reset_interval')
                                    ->label('Credit Reset'),
                            ]),

                        Infolists\Components\Grid::make(3)
                            ->schema([
                                Infolists\Components\TextEntry::make('valid_from')
                                    ->label('Valid From')
                                    ->date()
                                    ->placeholder('No restriction'),
                                Infolists\Components\TextEntry::make('valid_until')
                                    ->label('Valid Until')
                                    ->date()
                                    ->placeholder('No restriction'),
                                Infolists\Components\IconEntry::make('active')
                                    ->label('Active')
                                    ->boolean(),
                            ]),

                        Infolists\Components\Grid::make(3)
                            ->schema([
                                Infolists\Components\TextEntry::make('payment_provider')
                                    ->label('Payment Provider')
                                    ->badge()
                                    ->color(fn ($state) => match ($state) {
                                        'stripe' => 'success',
                                        'mollie' => 'info',
                                        default => 'gray',
                                    })
                                    ->placeholder('Default (env)'),
                                Infolists\Components\TextEntry::make('stripe_product_id')
                                    ->label('Stripe Product ID')
                                    ->placeholder('—')
                                    ->copyable(),
                                Infolists\Components\TextEntry::make('stripe_price_id')
                                    ->label('Stripe Price ID')
                                    ->placeholder('—')
                                    ->copyable(),
                            ]),
                    ]),

                Infolists\Components\Section::make('Statistics')
                    ->schema([
                        Infolists\Components\Grid::make(4)
                            ->schema([
                                Infolists\Components\TextEntry::make('total_user_licenses')
                                    ->label('Total User Licenses')
                                    ->state(fn (License $record): int => $record->userLicenses()->count())
                                    ->badge()
                                    ->color('info'),
                                Infolists\Components\TextEntry::make('active_user_licenses')
                                    ->label('Active User Licenses')
                                    ->state(fn (License $record): int => $record->userLicenses()->where('status', 'active')->count())
                                    ->badge()
                                    ->color('success'),
                                Infolists\Components\TextEntry::make('total_org_licenses')
                                    ->label('Total Org Licenses')
                                    ->state(fn (License $record): int => $record->organizationLicenses()->count())
                                    ->badge()
                                    ->color('warning'),
                                Infolists\Components\TextEntry::make('active_org_licenses')
                                    ->label('Active Org Licenses')
                                    ->state(fn (License $record): int => $record->organizationLicenses()->where('status', 'active')->count())
                                    ->badge()
                                    ->color('success'),
                            ]),
                    ]),

                Infolists\Components\Section::make('Upload Limits')
                    ->schema([
                        Infolists\Components\TextEntry::make('json_restrictions.max_files')
                            ->label('Max Files')
                            ->placeholder('No limit'),
                        Infolists\Components\TextEntry::make('json_restrictions.max_total_size')
                            ->label('Max Total Size (MB)')
                            ->formatStateUsing(fn ($state) => $state ? number_format($state / 1024 / 1024, 2) : null)
                            ->placeholder('No limit'),
                        Infolists\Components\TextEntry::make('json_restrictions.max_pages')
                            ->label('Max Pages')
                            ->placeholder('No limit'),
                        Infolists\Components\TextEntry::make('json_restrictions.max_file_size')
                            ->label('Max File Size (MB)')
                            ->formatStateUsing(fn ($state) => $state ? number_format($state / 1024 / 1024, 2) : null)
                            ->placeholder('No limit'),
                    ])
                    ->columns(2)
                    ->collapsible(),

                Infolists\Components\Section::make('Features')
                    ->schema([
                        Infolists\Components\IconEntry::make('json_restrictions.workflow_builder')
                            ->label('Workflow Builder')
                            ->boolean(),
                        Infolists\Components\IconEntry::make('json_restrictions.email_support')
                            ->label('Email Support')
                            ->boolean(),
                        Infolists\Components\IconEntry::make('json_restrictions.api_access')
                            ->label('API Access')
                            ->boolean(),
                        Infolists\Components\IconEntry::make('json_restrictions.custom_branding')
                            ->label('Custom Branding')
                            ->boolean(),
                        Infolists\Components\IconEntry::make('json_restrictions.priority_support')
                            ->label('Priority Support')
                            ->boolean(),
                        Infolists\Components\IconEntry::make('json_restrictions.advanced_analytics')
                            ->label('Advanced Analytics')
                            ->boolean(),
                    ])
                    ->columns(3)
                    ->collapsible(),

                Infolists\Components\Section::make('Content Restrictions')
                    ->schema([
                        Infolists\Components\TextEntry::make('json_restrictions.allowed_file_types')
                            ->label('Allowed File Types')
                            ->formatStateUsing(fn ($state) => is_array($state) ? implode(', ', $state) : 'All types')
                            ->placeholder('All types allowed'),
                        Infolists\Components\IconEntry::make('json_restrictions.watermark_required')
                            ->label('Watermark Required')
                            ->boolean(),
                    ])
                    ->columns(2)
                    ->collapsible(),

                Infolists\Components\Section::make('Other Restrictions')
                    ->schema([
                        Infolists\Components\TextEntry::make('json_restrictions')
                            ->label(false)
                            ->formatStateUsing(function ($state) {
                                if (! $state) {
                                    return 'None';
                                }
                                // Filter out known keys
                                $known = [
                                    'max_files',
                                    'max_total_size',
                                    'max_pages',
                                    'max_file_size',
                                    'workflow_builder',
                                    'email_support',
                                    'api_access',
                                    'custom_branding',
                                    'priority_support',
                                    'advanced_analytics',
                                    'allowed_file_types',
                                    'watermark_required',
                                ];
                                $other = array_diff_key($state, array_flip($known));

                                return empty($other) ? 'None' : json_encode($other, JSON_PRETTY_PRINT);
                            })
                            ->placeholder('None')
                            ->markdown(),
                    ])
                    ->collapsible(),

                Infolists\Components\Section::make('Restrictions (Full JSON)')
                    ->schema([
                        Infolists\Components\TextEntry::make('json_restrictions')
                            ->label(false)
                            ->formatStateUsing(fn ($state) => $state ? json_encode($state, JSON_PRETTY_PRINT) : 'No restrictions defined')
                            ->markdown(),
                    ])
                    ->collapsed()
                    ->collapsible(),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            RelationManagers\UserLicensesRelationManager::class,
            RelationManagers\OrganizationLicensesRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListLicenses::route('/'),
            'create' => Pages\CreateLicense::route('/create'),
            'view' => Pages\ViewLicense::route('/{record}'),
            'edit' => Pages\EditLicense::route('/{record}/edit'),
        ];
    }
}
