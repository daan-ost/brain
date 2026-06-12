<?php

namespace App\Filament\Resources\UserResource\RelationManagers;

use App\Filament\Resources\AnalyticsEventResource;
use App\Services\PostmarkTemplateService;
use Filament\Forms;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class AnalyticsEventsRelationManager extends RelationManager
{
    protected static string $relationship = 'analyticsEvents';

    protected static ?string $title = 'Activity & Emails';

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('event')
            ->columns([
                Tables\Columns\TextColumn::make('created_at')
                    ->label('Date')
                    ->dateTime('d-m-Y H:i')
                    ->sortable(),

                Tables\Columns\TextColumn::make('event')
                    ->label('Event')
                    ->badge()
                    ->color(fn (string $state): string => match (true) {
                        str_contains($state, 'email_sent') => 'success',
                        str_contains($state, 'email_failed') => 'danger',
                        str_contains($state, 'email_skipped') => 'warning',
                        str_contains($state, 'email') => 'info',
                        str_contains($state, 'login') => 'primary',
                        str_contains($state, 'signup') => 'success',
                        str_contains($state, 'checkout') => 'warning',
                        str_contains($state, 'error') || str_contains($state, 'failed') => 'danger',
                        default => 'gray',
                    })
                    ->formatStateUsing(fn (string $state): string => str_replace('_', ' ', $state)),

                Tables\Columns\TextColumn::make('email_type')
                    ->label('Email Type')
                    ->state(function ($record): ?string {
                        if (! $record->meta || ! is_array($record->meta)) {
                            return null;
                        }

                        return $record->meta['type'] ?? $record->meta['template_alias'] ?? null;
                    })
                    ->placeholder('—')
                    ->formatStateUsing(fn (?string $state): string => $state ? str_replace(['_', '-'], ' ', $state) : '—'),

                Tables\Columns\TextColumn::make('email_recipient')
                    ->label('Recipient')
                    ->state(function ($record): ?string {
                        if (! $record->meta || ! is_array($record->meta)) {
                            return null;
                        }

                        return $record->meta['recipient'] ?? null;
                    })
                    ->placeholder('—')
                    ->limit(30),

                Tables\Columns\IconColumn::make('success')
                    ->label('OK')
                    ->boolean()
                    ->trueIcon('heroicon-o-check-circle')
                    ->falseIcon('heroicon-o-x-circle')
                    ->trueColor('success')
                    ->falseColor('danger'),

                Tables\Columns\TextColumn::make('details')
                    ->label('Details')
                    ->state(function ($record): string {
                        if (! $record->meta || ! is_array($record->meta)) {
                            return '';
                        }

                        $details = [];

                        // For email events
                        if (isset($record->meta['postmark_message_id'])) {
                            $details[] = 'MSG: '.substr($record->meta['postmark_message_id'], 0, 12).'...';
                        }

                        // For checkout events
                        if (isset($record->meta['license_slug'])) {
                            $details[] = 'License: '.$record->meta['license_slug'];
                        }

                        // For error events
                        if (isset($record->meta['error'])) {
                            $details[] = 'Error: '.substr($record->meta['error'], 0, 30);
                        }

                        // Generic page type
                        if (isset($record->meta['page_type'])) {
                            $details[] = $record->meta['page_type'];
                        }

                        return implode(' | ', $details);
                    })
                    ->limit(50)
                    ->tooltip(function ($record): ?string {
                        if (! $record->meta) {
                            return null;
                        }

                        return json_encode($record->meta, JSON_PRETTY_PRINT);
                    }),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('event_type')
                    ->label('Event Type')
                    ->options([
                        'email' => 'Email Events',
                        'auth' => 'Authentication',
                        'checkout' => 'Checkout',
                        'organization' => 'Organization',
                        'profile' => 'Profile',
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        if (empty($data['value'])) {
                            return $query;
                        }

                        return match ($data['value']) {
                            'email' => $query->where('event', 'like', '%email%'),
                            'auth' => $query->where(function ($q) {
                                $q->where('event', 'like', '%login%')
                                    ->orWhere('event', 'like', '%signup%')
                                    ->orWhere('event', 'like', '%logout%');
                            }),
                            'checkout' => $query->where('event', 'like', '%checkout%'),
                            'organization' => $query->where('event', 'like', '%organization%'),
                            'profile' => $query->where('event', 'like', '%profile%'),
                            default => $query,
                        };
                    }),

                Tables\Filters\TernaryFilter::make('success')
                    ->label('Success')
                    ->placeholder('All')
                    ->trueLabel('Successful')
                    ->falseLabel('Failed'),

                Tables\Filters\Filter::make('emails_only')
                    ->label('Emails Only')
                    ->query(fn (Builder $query): Builder => $query->where('event', 'like', '%email%'))
                    ->toggle(),

                Tables\Filters\Filter::make('date_range')
                    ->form([
                        Forms\Components\DatePicker::make('from')
                            ->label('From'),
                        Forms\Components\DatePicker::make('until')
                            ->label('Until'),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when($data['from'], fn (Builder $q, $date) => $q->whereDate('created_at', '>=', $date))
                            ->when($data['until'], fn (Builder $q, $date) => $q->whereDate('created_at', '<=', $date));
                    }),
            ])
            ->actions([
                Tables\Actions\ViewAction::make()
                    ->url(fn ($record) => AnalyticsEventResource::getUrl('view', ['record' => $record])),

                Tables\Actions\Action::make('viewEmailContent')
                    ->label('View Email')
                    ->icon('heroicon-o-envelope-open')
                    ->color('info')
                    ->visible(function ($record): bool {
                        if (! $record->meta || ! is_array($record->meta)) {
                            return false;
                        }

                        return isset($record->meta['postmark_message_id'])
                            && str_contains($record->event, 'email_sent');
                    })
                    ->modalHeading('Email Content from Postmark')
                    ->modalDescription(fn ($record) => 'Message ID: '.($record->meta['postmark_message_id'] ?? 'Unknown'))
                    ->modalContent(function ($record) {
                        $messageId = $record->meta['postmark_message_id'] ?? null;

                        if (! $messageId) {
                            return view('filament.components.email-content-modal', [
                                'error' => 'No message ID found',
                                'content' => null,
                            ]);
                        }

                        try {
                            $service = app(PostmarkTemplateService::class);
                            $messageDetails = $service->getMessageDetails($messageId);

                            return view('filament.components.email-content-modal', [
                                'error' => null,
                                'content' => $messageDetails,
                            ]);
                        } catch (\Exception $e) {
                            return view('filament.components.email-content-modal', [
                                'error' => 'Could not fetch email: '.$e->getMessage(),
                                'content' => null,
                            ]);
                        }
                    })
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Close'),
            ])
            ->bulkActions([])
            ->defaultSort('created_at', 'desc')
            ->paginated([10, 25, 50]);
    }
}
