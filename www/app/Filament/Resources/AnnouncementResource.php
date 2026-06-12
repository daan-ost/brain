<?php

namespace App\Filament\Resources;

use App\Filament\Resources\AnnouncementResource\Pages;
use App\Models\Announcement;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;

class AnnouncementResource extends Resource
{
    protected static ?string $model = Announcement::class;

    protected static ?string $navigationIcon = 'heroicon-o-megaphone';

    protected static ?string $navigationGroup = 'Content';

    protected static ?int $navigationSort = 1;

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Tabs::make('Announcement')
                    ->tabs([
                        Forms\Components\Tabs\Tab::make('Content')
                            ->schema([
                                Forms\Components\Grid::make(2)
                                    ->schema([
                                        Forms\Components\TextInput::make('title_en')
                                            ->label('Title (English)')
                                            ->required()
                                            ->maxLength(255)
                                            ->afterStateHydrated(fn ($component, $record) => $component->state($record?->title_json['en'] ?? '')),

                                        Forms\Components\TextInput::make('title_nl')
                                            ->label('Title (Dutch)')
                                            ->maxLength(255)
                                            ->afterStateHydrated(fn ($component, $record) => $component->state($record?->title_json['nl'] ?? '')),
                                    ]),

                                Forms\Components\RichEditor::make('body_en')
                                    ->label('Body (English)')
                                    ->required()
                                    ->toolbarButtons([
                                        'bold',
                                        'italic',
                                        'underline',
                                        'bulletList',
                                        'orderedList',
                                        'link',
                                    ])
                                    ->afterStateHydrated(fn ($component, $record) => $component->state($record?->body_json['en'] ?? '')),

                                Forms\Components\RichEditor::make('body_nl')
                                    ->label('Body (Dutch)')
                                    ->toolbarButtons([
                                        'bold',
                                        'italic',
                                        'underline',
                                        'bulletList',
                                        'orderedList',
                                        'link',
                                    ])
                                    ->afterStateHydrated(fn ($component, $record) => $component->state($record?->body_json['nl'] ?? '')),
                            ]),

                        Forms\Components\Tabs\Tab::make('Settings')
                            ->schema([
                                Forms\Components\Grid::make(3)
                                    ->schema([
                                        Forms\Components\Select::make('urgency')
                                            ->label('Urgency Level')
                                            ->options([
                                                'info' => 'Info (Blue)',
                                                'warning' => 'Warning (Orange)',
                                                'update' => 'Update (Green)',
                                            ])
                                            ->default('info')
                                            ->required(),

                                        Forms\Components\DateTimePicker::make('starts_at')
                                            ->label('Start Date')
                                            ->required()
                                            ->default(now()),

                                        Forms\Components\DateTimePicker::make('ends_at')
                                            ->label('End Date')
                                            ->required()
                                            ->default(now()->addWeek()),
                                    ]),

                                Forms\Components\Toggle::make('active')
                                    ->label('Active')
                                    ->default(true),

                                Forms\Components\TextInput::make('total_views')
                                    ->label('Total Views')
                                    ->numeric()
                                    ->disabled()
                                    ->dehydrated(false)
                                    ->visibleOn('edit'),
                            ]),

                        Forms\Components\Tabs\Tab::make('Call to Action')
                            ->schema([
                                Forms\Components\Grid::make(2)
                                    ->schema([
                                        Forms\Components\TextInput::make('cta_label_en')
                                            ->label('CTA Button Label (English)')
                                            ->helperText('Leave empty to hide button')
                                            ->maxLength(100)
                                            ->afterStateHydrated(fn ($component, $record) => $component->state($record?->cta_label_json['en'] ?? '')),

                                        Forms\Components\TextInput::make('cta_label_nl')
                                            ->label('CTA Button Label (Dutch)')
                                            ->maxLength(100)
                                            ->afterStateHydrated(fn ($component, $record) => $component->state($record?->cta_label_json['nl'] ?? '')),
                                    ]),

                                Forms\Components\TextInput::make('cta_url')
                                    ->label('CTA URL')
                                    ->url()
                                    ->helperText('Full URL including https://'),
                            ]),
                    ])
                    ->columnSpanFull(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('id')
                    ->label('ID')
                    ->sortable(),

                Tables\Columns\TextColumn::make('title')
                    ->label('Title')
                    ->state(fn (Announcement $record): string => $record->title_en ?: $record->title_nl ?: '—')
                    ->limit(40)
                    ->searchable(query: function (Builder $query, string $search): Builder {
                        return $query->where('title_json', 'like', "%{$search}%");
                    }),

                Tables\Columns\TextColumn::make('urgency')
                    ->label('Urgency')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'info' => 'info',
                        'warning' => 'warning',
                        'update' => 'success',
                        default => 'gray',
                    })
                    ->formatStateUsing(fn (string $state): string => ucfirst($state)),

                Tables\Columns\TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->state(function (Announcement $record): string {
                        $now = now();
                        if (! $record->active) {
                            return 'Inactive';
                        }
                        if ($record->starts_at > $now) {
                            return 'Upcoming';
                        }
                        if ($record->ends_at < $now) {
                            return 'Expired';
                        }

                        return 'Active';
                    })
                    ->color(fn (string $state): string => match ($state) {
                        'Active' => 'success',
                        'Upcoming' => 'info',
                        'Expired' => 'gray',
                        'Inactive' => 'gray',
                        default => 'gray',
                    }),

                Tables\Columns\TextColumn::make('date_range')
                    ->label('Date Range')
                    ->state(fn (Announcement $record): string => $record->starts_at->format('d M Y H:i').' → '.$record->ends_at->format('d M Y H:i')
                    ),

                Tables\Columns\TextColumn::make('total_views')
                    ->label('Views')
                    ->numeric()
                    ->sortable(),

                Tables\Columns\IconColumn::make('has_cta')
                    ->label('CTA')
                    ->state(fn (Announcement $record): bool => $record->hasCta())
                    ->boolean(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->label('Status')
                    ->options([
                        'active' => 'Active',
                        'upcoming' => 'Upcoming',
                        'expired' => 'Expired',
                        'inactive' => 'Inactive',
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        $now = now();

                        return match ($data['value']) {
                            'active' => $query->where('active', true)
                                ->where('starts_at', '<=', $now)
                                ->where('ends_at', '>=', $now),
                            'upcoming' => $query->where('active', true)
                                ->where('starts_at', '>', $now),
                            'expired' => $query->where('ends_at', '<', $now),
                            'inactive' => $query->where('active', false),
                            default => $query,
                        };
                    }),

                Tables\Filters\SelectFilter::make('urgency')
                    ->options([
                        'info' => 'Info',
                        'warning' => 'Warning',
                        'update' => 'Update',
                    ]),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make()
                    ->after(fn () => Cache::forget('announcement.active')),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make()
                        ->after(fn () => Cache::forget('announcement.active')),
                ]),
            ])
            ->defaultSort('created_at', 'desc')
            ->defaultPaginationPageOption(25)
            ->paginationPageOptions([10, 25, 50]);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListAnnouncements::route('/'),
            'create' => Pages\CreateAnnouncement::route('/create'),
            'view' => Pages\ViewAnnouncement::route('/{record}'),
            'edit' => Pages\EditAnnouncement::route('/{record}/edit'),
        ];
    }
}
