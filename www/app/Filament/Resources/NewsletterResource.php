<?php

namespace App\Filament\Resources;

use App\Filament\Resources\NewsletterResource\Pages;
use App\Models\Newsletter;
use App\Services\NewsletterService;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class NewsletterResource extends Resource
{
    protected static ?string $model = Newsletter::class;

    protected static ?string $navigationIcon = 'heroicon-o-envelope';

    protected static ?string $navigationGroup = 'Content';

    protected static ?int $navigationSort = 2;

    protected static ?string $navigationLabel = 'Nieuwsbrieven';

    protected static ?string $modelLabel = 'Nieuwsbrief';

    protected static ?string $pluralModelLabel = 'Nieuwsbrieven';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Tabs::make('Newsletter')
                    ->tabs([
                        Forms\Components\Tabs\Tab::make('Inhoud')
                            ->schema([
                                Forms\Components\Grid::make(2)
                                    ->schema([
                                        Forms\Components\TextInput::make('title_en')
                                            ->label('Titel (Engels)')
                                            ->required()
                                            ->maxLength(255)
                                            ->afterStateHydrated(fn ($component, $record) => $component->state($record?->title_json['en'] ?? '')),

                                        Forms\Components\TextInput::make('title_nl')
                                            ->label('Titel (Nederlands)')
                                            ->required()
                                            ->maxLength(255)
                                            ->afterStateHydrated(fn ($component, $record) => $component->state($record?->title_json['nl'] ?? '')),
                                    ]),

                                Forms\Components\RichEditor::make('body_en')
                                    ->label('Inhoud (Engels)')
                                    ->required()
                                    ->toolbarButtons([
                                        'bold',
                                        'italic',
                                        'underline',
                                        'bulletList',
                                        'orderedList',
                                        'link',
                                        'h2',
                                        'h3',
                                    ])
                                    ->afterStateHydrated(fn ($component, $record) => $component->state($record?->body_json['en'] ?? '')),

                                Forms\Components\RichEditor::make('body_nl')
                                    ->label('Inhoud (Nederlands)')
                                    ->required()
                                    ->toolbarButtons([
                                        'bold',
                                        'italic',
                                        'underline',
                                        'bulletList',
                                        'orderedList',
                                        'link',
                                        'h2',
                                        'h3',
                                    ])
                                    ->afterStateHydrated(fn ($component, $record) => $component->state($record?->body_json['nl'] ?? '')),
                            ]),

                        Forms\Components\Tabs\Tab::make('Instellingen')
                            ->schema([
                                Forms\Components\Select::make('batch_size')
                                    ->label('Batch grootte')
                                    ->options([
                                        50 => '50 per batch',
                                        100 => '100 per batch (standaard)',
                                        200 => '200 per batch',
                                    ])
                                    ->default(100)
                                    ->helperText('Aantal emails per verzend batch'),

                                Forms\Components\Placeholder::make('status_info')
                                    ->label('Status')
                                    ->content(fn (?Newsletter $record): string => $record ? ucfirst($record->status) : 'Draft')
                                    ->visibleOn('edit'),

                                Forms\Components\Placeholder::make('created_by_info')
                                    ->label('Aangemaakt door')
                                    ->content(fn (?Newsletter $record): string => $record?->creator?->name ?? 'Onbekend')
                                    ->visibleOn('edit'),
                            ]),

                        Forms\Components\Tabs\Tab::make('Statistieken')
                            ->schema([
                                Forms\Components\Grid::make(3)
                                    ->schema([
                                        Forms\Components\Placeholder::make('total_recipients')
                                            ->label('Totaal ontvangers')
                                            ->content(fn (?Newsletter $record): string => number_format($record?->total_recipients ?? 0)),

                                        Forms\Components\Placeholder::make('total_sent')
                                            ->label('Verzonden')
                                            ->content(fn (?Newsletter $record): string => number_format($record?->total_sent ?? 0)),

                                        Forms\Components\Placeholder::make('total_failed')
                                            ->label('Mislukt')
                                            ->content(fn (?Newsletter $record): string => number_format($record?->total_failed ?? 0)),
                                    ]),

                                Forms\Components\Grid::make(3)
                                    ->schema([
                                        Forms\Components\Placeholder::make('total_opened')
                                            ->label('Geopend')
                                            ->content(fn (?Newsletter $record): string => number_format($record?->total_opened ?? 0).' ('.($record?->getOpenRate() ?? 0).'%)'),

                                        Forms\Components\Placeholder::make('total_clicked')
                                            ->label('Geklikt')
                                            ->content(fn (?Newsletter $record): string => number_format($record?->total_clicked ?? 0).' ('.($record?->getClickRate() ?? 0).'%)'),

                                        Forms\Components\Placeholder::make('total_bounced')
                                            ->label('Bounced')
                                            ->content(fn (?Newsletter $record): string => number_format($record?->total_bounced ?? 0).' ('.($record?->getBounceRate() ?? 0).'%)'),
                                    ]),

                                Forms\Components\Placeholder::make('progress')
                                    ->label('Voortgang')
                                    ->content(fn (?Newsletter $record): string => ($record?->getProgress() ?? 0).'%')
                                    ->visibleOn('edit'),

                                Forms\Components\Placeholder::make('segment_key')
                                    ->label('Doelgroep')
                                    ->content(fn (?Newsletter $record): string => $record?->segment_key
                                        ? app(\App\Services\NewsletterSegmentService::class)->label($record->segment_key)
                                        : 'Niet gezet')
                                    ->visibleOn('edit'),
                            ])
                            ->visibleOn('edit'),
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
                    ->label('Titel')
                    ->state(fn (Newsletter $record): string => $record->title_nl ?: $record->title_en ?: '-')
                    ->limit(40)
                    ->searchable(query: function (Builder $query, string $search): Builder {
                        return $query->where('title_json', 'like', "%{$search}%");
                    }),

                Tables\Columns\TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'draft' => 'gray',
                        'sending' => 'warning',
                        'paused' => 'info',
                        'sent' => 'success',
                        'cancelled' => 'danger',
                        default => 'gray',
                    })
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'draft' => 'Concept',
                        'sending' => 'Verzenden...',
                        'paused' => 'Gepauzeerd',
                        'sent' => 'Verzonden',
                        'cancelled' => 'Geannuleerd',
                        default => ucfirst($state),
                    }),

                Tables\Columns\TextColumn::make('statistics')
                    ->label('Statistieken')
                    ->state(fn (Newsletter $record): string => sprintf(
                        '%d/%d verzonden | %d geopend | %d geklikt',
                        $record->total_sent,
                        $record->total_recipients,
                        $record->total_opened,
                        $record->total_clicked
                    )),

                Tables\Columns\TextColumn::make('creator.name')
                    ->label('Door')
                    ->sortable(),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('Aangemaakt')
                    ->dateTime('d M Y H:i')
                    ->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->label('Status')
                    ->options([
                        'draft' => 'Concept',
                        'sending' => 'Verzenden',
                        'paused' => 'Gepauzeerd',
                        'sent' => 'Verzonden',
                        'cancelled' => 'Geannuleerd',
                    ]),
            ])
            ->actions([
                Tables\Actions\Action::make('send_test')
                    ->label('Test Email')
                    ->icon('heroicon-o-paper-airplane')
                    ->form([
                        Forms\Components\TextInput::make('test_email')
                            ->label('Email adres')
                            ->email()
                            ->required()
                            ->default(fn () => auth()->user()->email),
                    ])
                    ->action(function (Newsletter $record, array $data): void {
                        try {
                            app(NewsletterService::class)->sendTestEmail($record, $data['test_email']);
                            Notification::make()
                                ->title('Test email verstuurd')
                                ->body("Test email verzonden naar {$data['test_email']}")
                                ->success()
                                ->send();
                        } catch (\Throwable $e) {
                            Notification::make()
                                ->title('Fout bij verzenden')
                                ->body($e->getMessage())
                                ->danger()
                                ->send();
                        }
                    })
                    ->visible(fn (Newsletter $record): bool => $record->isDraft()),

                Tables\Actions\Action::make('start_sending')
                    ->label('Start verzending')
                    ->icon('heroicon-o-play')
                    ->color('success')
                    ->form([
                        Forms\Components\Select::make('segment_key')
                            ->label('Doelgroep')
                            ->options(fn (): array => app(\App\Services\NewsletterSegmentService::class)->availableSegments())
                            ->default(\App\Services\NewsletterSegmentService::SEGMENT_ALL)
                            ->required()
                            ->reactive(),
                        Forms\Components\Select::make('send_limit')
                            ->label('Verzend naar')
                            ->options([
                                10 => 'Eerste 10 ontvangers',
                                100 => 'Eerste 100 ontvangers',
                                1000 => 'Eerste 1000 ontvangers',
                                '' => 'Alle ontvangers',
                            ])
                            ->default(10)
                            ->required()
                            ->reactive(),
                        Forms\Components\Placeholder::make('estimated_reach')
                            ->label('Geschat bereik')
                            ->content(function (Forms\Get $get): string {
                                $segments = app(\App\Services\NewsletterSegmentService::class);
                                $segmentKey = $get('segment_key') ?? \App\Services\NewsletterSegmentService::SEGMENT_ALL;
                                $eligible = $segments->count($segmentKey);
                                $limitRaw = $get('send_limit');
                                $limit = ($limitRaw === '' || $limitRaw === null) ? null : (int) $limitRaw;
                                $reach = $limit === null ? $eligible : min($limit, $eligible);

                                return number_format($reach, 0, ',', '.').' van '.number_format($eligible, 0, ',', '.').' ontvangers';
                            }),
                    ])
                    ->action(function (Newsletter $record, array $data): void {
                        $limit = ($data['send_limit'] === '' || $data['send_limit'] === null) ? null : (int) $data['send_limit'];
                        $segmentKey = $data['segment_key'] ?? \App\Services\NewsletterSegmentService::SEGMENT_ALL;
                        try {
                            app(NewsletterService::class)->startSending($record, $limit, $segmentKey);
                            $segmentLabel = app(\App\Services\NewsletterSegmentService::class)->label($segmentKey);
                            Notification::make()
                                ->title('Verzending gestart')
                                ->body(($limit ? "Eerste {$limit}" : 'Alle').' ontvangers — segment: '.$segmentLabel)
                                ->success()
                                ->send();
                        } catch (\Throwable $e) {
                            Notification::make()
                                ->title('Fout bij starten')
                                ->body($e->getMessage())
                                ->danger()
                                ->send();
                        }
                    })
                    ->requiresConfirmation()
                    ->modalHeading('Start verzending')
                    ->modalDescription('Kies een doelgroep en eventuele limit. Het geschatte bereik update mee.')
                    ->visible(fn (Newsletter $record): bool => $record->isDraft()),

                Tables\Actions\Action::make('pause')
                    ->label('Pauzeer')
                    ->icon('heroicon-o-pause')
                    ->color('warning')
                    ->action(function (Newsletter $record): void {
                        try {
                            app(NewsletterService::class)->pauseSending($record);
                            Notification::make()
                                ->title('Verzending gepauzeerd')
                                ->success()
                                ->send();
                        } catch (\Throwable $e) {
                            Notification::make()
                                ->title('Fout bij pauzeren')
                                ->body($e->getMessage())
                                ->danger()
                                ->send();
                        }
                    })
                    ->requiresConfirmation()
                    ->visible(fn (Newsletter $record): bool => $record->canBePaused()),

                Tables\Actions\Action::make('resume')
                    ->label('Hervat')
                    ->icon('heroicon-o-play')
                    ->color('success')
                    ->action(function (Newsletter $record): void {
                        try {
                            app(NewsletterService::class)->resumeSending($record);
                            Notification::make()
                                ->title('Verzending hervat')
                                ->success()
                                ->send();
                        } catch (\Throwable $e) {
                            Notification::make()
                                ->title('Fout bij hervatten')
                                ->body($e->getMessage())
                                ->danger()
                                ->send();
                        }
                    })
                    ->requiresConfirmation()
                    ->visible(function (Newsletter $record): bool {
                        // Alleen tonen bij handmatige pause (= er zijn nog PENDING recipients).
                        if (! $record->canBeResumed()) {
                            return false;
                        }

                        return $record->recipients()
                            ->where('status', \App\Models\NewsletterRecipient::STATUS_PENDING)
                            ->exists();
                    }),

                Tables\Actions\Action::make('continue_sending')
                    ->label(function (Newsletter $record): string {
                        $remaining = app(NewsletterService::class)->unprocessedRecipientsCount($record);

                        return 'Verzend naar resterende '.number_format($remaining, 0, ',', '.').' ontvangers';
                    })
                    ->icon('heroicon-o-forward')
                    ->color('success')
                    ->action(function (Newsletter $record): void {
                        try {
                            app(NewsletterService::class)->continueSending($record);
                            Notification::make()
                                ->title('Verzending naar resterende ontvangers gestart')
                                ->success()
                                ->send();
                        } catch (\Throwable $e) {
                            Notification::make()
                                ->title('Fout bij doorzetten verzending')
                                ->body($e->getMessage())
                                ->danger()
                                ->send();
                        }
                    })
                    ->requiresConfirmation()
                    ->visible(function (Newsletter $record): bool {
                        if (! $record->isPaused() || $record->send_limit === null) {
                            return false;
                        }

                        return app(NewsletterService::class)->hasUnprocessedRecipients($record);
                    }),

                Tables\Actions\Action::make('cancel')
                    ->label('Annuleer')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->action(function (Newsletter $record): void {
                        try {
                            app(NewsletterService::class)->cancelSending($record);
                            Notification::make()
                                ->title('Verzending geannuleerd')
                                ->success()
                                ->send();
                        } catch (\Throwable $e) {
                            Notification::make()
                                ->title('Fout bij annuleren')
                                ->body($e->getMessage())
                                ->danger()
                                ->send();
                        }
                    })
                    ->requiresConfirmation()
                    ->modalHeading('Annuleer verzending')
                    ->modalDescription('Weet je zeker dat je de verzending wilt annuleren? Dit kan niet ongedaan worden gemaakt.')
                    ->visible(fn (Newsletter $record): bool => $record->canBeCancelled()),

                Tables\Actions\ViewAction::make(),
                Tables\Actions\EditAction::make()
                    ->visible(fn (Newsletter $record): bool => $record->canBeEdited()),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make()
                        ->before(function ($records) {
                            foreach ($records as $record) {
                                if (! $record->isDraft()) {
                                    Notification::make()
                                        ->title('Kan niet verwijderen')
                                        ->body('Alleen concept nieuwsbrieven kunnen worden verwijderd.')
                                        ->danger()
                                        ->send();

                                    return false;
                                }
                            }
                        }),
                ]),
            ])
            ->defaultSort('created_at', 'desc')
            ->defaultPaginationPageOption(25);
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
            'index' => Pages\ListNewsletters::route('/'),
            'create' => Pages\CreateNewsletter::route('/create'),
            'view' => Pages\ViewNewsletter::route('/{record}'),
            'edit' => Pages\EditNewsletter::route('/{record}/edit'),
        ];
    }
}
