<?php

namespace App\Filament\Resources;

use App\Filament\Resources\PostmarkTemplateResource\Pages;
use App\Models\PostmarkLayoutTemplate;
use App\Models\PostmarkTemplate;
use App\Services\PostmarkTemplateService;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class PostmarkTemplateResource extends Resource
{
    protected static ?string $model = PostmarkTemplate::class;

    protected static ?string $navigationIcon = 'heroicon-o-envelope';

    protected static ?string $navigationGroup = 'Email';

    protected static ?int $navigationSort = 1;

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Tabs::make('Template')
                    ->tabs([
                        Forms\Components\Tabs\Tab::make('Basic Information')
                            ->schema([
                                Forms\Components\Grid::make(2)
                                    ->schema([
                                        Forms\Components\TextInput::make('name')
                                            ->label('Template Name')
                                            ->required()
                                            ->maxLength(255),

                                        Forms\Components\TextInput::make('alias')
                                            ->label('Template Alias')
                                            ->required()
                                            ->unique(ignoreRecord: true)
                                            ->maxLength(255)
                                            ->helperText('Unique identifier for this template'),
                                    ]),

                                Forms\Components\Grid::make(3)
                                    ->schema([
                                        Forms\Components\Select::make('template_type')
                                            ->label('Template Type')
                                            ->options([
                                                'Standard' => 'Standard Template',
                                                'Layout' => 'Layout Template',
                                            ])
                                            ->default('Standard')
                                            ->required(),

                                        Forms\Components\Select::make('layout_template_alias')
                                            ->label('Layout Template')
                                            ->options(fn () => PostmarkLayoutTemplate::where('active', true)->pluck('name', 'alias'))
                                            ->nullable()
                                            ->searchable(),

                                        Forms\Components\Toggle::make('active')
                                            ->label('Active')
                                            ->default(true),
                                    ]),
                            ]),

                        Forms\Components\Tabs\Tab::make('Content')
                            ->schema([
                                Forms\Components\TextInput::make('subject')
                                    ->label('Email Subject')
                                    ->helperText('Use {{variable}} syntax for dynamic content')
                                    ->maxLength(255),

                                Forms\Components\RichEditor::make('html_body')
                                    ->label('HTML Body')
                                    ->toolbarButtons([
                                        'bold',
                                        'italic',
                                        'underline',
                                        'strike',
                                        'link',
                                        'bulletList',
                                        'orderedList',
                                        'codeBlock',
                                    ])
                                    ->columnSpanFull(),

                                Forms\Components\Textarea::make('text_body')
                                    ->label('Text Body')
                                    ->rows(10)
                                    ->helperText('Fallback for email clients that don\'t support HTML')
                                    ->columnSpanFull(),
                            ]),

                        Forms\Components\Tabs\Tab::make('Sync Info')
                            ->schema([
                                Forms\Components\TextInput::make('postmark_id')
                                    ->label('Postmark ID')
                                    ->disabled()
                                    ->helperText('Automatically set when synced from Postmark'),

                                Forms\Components\Textarea::make('postmark_metadata_display')
                                    ->label('Postmark Metadata')
                                    ->disabled()
                                    ->rows(10)
                                    ->formatStateUsing(fn ($record) => $record?->postmark_metadata ? json_encode($record->postmark_metadata, JSON_PRETTY_PRINT) : ''),
                            ]),
                    ])
                    ->columnSpanFull(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('alias')
                    ->label('Alias')
                    ->searchable()
                    ->sortable()
                    ->limit(20),

                Tables\Columns\TextColumn::make('name')
                    ->label('Name')
                    ->searchable()
                    ->sortable()
                    ->limit(30),

                Tables\Columns\TextColumn::make('template_type')
                    ->label('Type')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'Layout' => 'info',
                        'Standard' => 'gray',
                        default => 'gray',
                    }),

                Tables\Columns\TextColumn::make('layout_template_alias')
                    ->label('Layout')
                    ->placeholder('None'),

                Tables\Columns\IconColumn::make('active')
                    ->label('Active')
                    ->boolean(),

                Tables\Columns\TextColumn::make('updated_at')
                    ->label('Updated')
                    ->dateTime()
                    ->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('template_type')
                    ->options([
                        'Standard' => 'Standard',
                        'Layout' => 'Layout',
                    ]),

                Tables\Filters\TernaryFilter::make('active'),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
                Tables\Actions\EditAction::make(),

                Tables\Actions\Action::make('preview')
                    ->label('Preview')
                    ->icon('heroicon-o-eye')
                    ->color('info')
                    ->modalHeading('Template Preview')
                    ->modalContent(fn (PostmarkTemplate $record) => view('filament.modals.template-preview', ['template' => $record]))
                    ->modalSubmitAction(false),

                Tables\Actions\Action::make('testSend')
                    ->label('Test Send')
                    ->icon('heroicon-o-paper-airplane')
                    ->color('warning')
                    ->form([
                        Forms\Components\TextInput::make('email')
                            ->label('Send to Email')
                            ->email()
                            ->required(),
                    ])
                    ->action(function (PostmarkTemplate $record, array $data): void {
                        try {
                            $postmarkService = app(PostmarkTemplateService::class);
                            $postmarkService->sendTestEmail($record->alias, $data['email']);

                            Notification::make()
                                ->title('Test email sent successfully')
                                ->success()
                                ->send();
                        } catch (\Exception $e) {
                            Notification::make()
                                ->title('Failed to send test email')
                                ->body($e->getMessage())
                                ->danger()
                                ->send();
                        }
                    }),

                Tables\Actions\Action::make('pushToProduction')
                    ->label('Push to Production')
                    ->icon('heroicon-o-arrow-up-tray')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalHeading('Push Template to Production')
                    ->modalDescription('This will push the current template to Postmark production. Are you sure?')
                    ->action(function (PostmarkTemplate $record): void {
                        try {
                            $postmarkService = app(PostmarkTemplateService::class);
                            $postmarkService->pushToProduction($record->alias);

                            Notification::make()
                                ->title('Template pushed to production')
                                ->success()
                                ->send();
                        } catch (\Exception $e) {
                            Notification::make()
                                ->title('Failed to push template')
                                ->body($e->getMessage())
                                ->danger()
                                ->send();
                        }
                    }),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ])
            ->headerActions([
                Tables\Actions\Action::make('syncFromPostmark')
                    ->label('Sync from Postmark')
                    ->icon('heroicon-o-arrow-path')
                    ->color('primary')
                    ->requiresConfirmation()
                    ->modalHeading('Sync Templates from Postmark')
                    ->modalDescription('This will fetch all templates from Postmark and update the local database.')
                    ->action(function (): void {
                        try {
                            $postmarkService = app(PostmarkTemplateService::class);
                            $templates = $postmarkService->getTemplates();
                            $synced = 0;

                            foreach ($templates as $templateData) {
                                if ($templateData['TemplateType'] !== 'Standard') {
                                    continue;
                                }

                                $fullTemplate = $postmarkService->getTemplate($templateData['TemplateId']);

                                PostmarkTemplate::updateOrCreate(
                                    ['alias' => $templateData['Alias']],
                                    [
                                        'postmark_id' => $templateData['TemplateId'],
                                        'name' => $templateData['Name'],
                                        'subject' => $fullTemplate['Subject'] ?? '',
                                        'html_body' => $fullTemplate['HtmlBody'] ?? '',
                                        'text_body' => $fullTemplate['TextBody'] ?? '',
                                        'template_type' => $templateData['TemplateType'],
                                        'layout_template_alias' => $templateData['LayoutTemplate'] ?? null,
                                        'active' => $templateData['Active'],
                                        'postmark_metadata' => $fullTemplate,
                                    ]
                                );
                                $synced++;
                            }

                            Notification::make()
                                ->title("Synced {$synced} templates from Postmark")
                                ->success()
                                ->send();
                        } catch (\Exception $e) {
                            Notification::make()
                                ->title('Failed to sync templates')
                                ->body($e->getMessage())
                                ->danger()
                                ->send();
                        }
                    }),
            ])
            ->defaultSort('updated_at', 'desc')
            ->defaultPaginationPageOption(25)
            ->paginationPageOptions([10, 25, 50]);
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist
            ->schema([
                Infolists\Components\Section::make('Template Details')
                    ->schema([
                        Infolists\Components\Grid::make(3)
                            ->schema([
                                Infolists\Components\TextEntry::make('name'),
                                Infolists\Components\TextEntry::make('alias'),
                                Infolists\Components\TextEntry::make('template_type')
                                    ->badge()
                                    ->color(fn (string $state): string => $state === 'Layout' ? 'info' : 'gray'),
                            ]),

                        Infolists\Components\Grid::make(3)
                            ->schema([
                                Infolists\Components\TextEntry::make('postmark_id')
                                    ->placeholder('Not synced'),
                                Infolists\Components\TextEntry::make('layout_template_alias')
                                    ->placeholder('None'),
                                Infolists\Components\IconEntry::make('active')
                                    ->boolean(),
                            ]),
                    ]),

                Infolists\Components\Section::make('Content')
                    ->schema([
                        Infolists\Components\TextEntry::make('subject')
                            ->columnSpanFull(),

                        Infolists\Components\TextEntry::make('html_body')
                            ->label('HTML Body')
                            ->html()
                            ->columnSpanFull(),

                        Infolists\Components\TextEntry::make('text_body')
                            ->label('Text Body')
                            ->columnSpanFull(),
                    ])
                    ->collapsed(),

                Infolists\Components\Section::make('Timestamps')
                    ->schema([
                        Infolists\Components\Grid::make(2)
                            ->schema([
                                Infolists\Components\TextEntry::make('created_at')
                                    ->dateTime(),
                                Infolists\Components\TextEntry::make('updated_at')
                                    ->dateTime(),
                            ]),
                    ])
                    ->collapsed(),
            ]);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListPostmarkTemplates::route('/'),
            'create' => Pages\CreatePostmarkTemplate::route('/create'),
            'view' => Pages\ViewPostmarkTemplate::route('/{record}'),
            'edit' => Pages\EditPostmarkTemplate::route('/{record}/edit'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->where('template_type', 'Standard');
    }
}
