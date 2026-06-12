<?php

namespace App\Filament\Pages;

use App\Models\FailedJob;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Tables;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

class FailedJobs extends Page implements HasTable
{
    use InteractsWithTable;

    protected static ?string $navigationIcon = 'heroicon-o-exclamation-triangle';

    protected static ?string $navigationGroup = 'System';

    protected static ?int $navigationSort = 10;

    protected static ?string $title = 'Failed Jobs';

    protected static string $view = 'filament.pages.failed-jobs';

    public static function getNavigationBadge(): ?string
    {
        $count = DB::table('failed_jobs')->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'danger';
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('retryAll')
                ->label('Retry All')
                ->icon('heroicon-o-arrow-path')
                ->color('warning')
                ->requiresConfirmation()
                ->modalHeading('Retry All Failed Jobs')
                ->modalDescription('Are you sure you want to retry all failed jobs? This will add them back to the queue.')
                ->action(function () {
                    Artisan::call('queue:retry', ['id' => 'all']);

                    Notification::make()
                        ->title('All failed jobs have been queued for retry')
                        ->success()
                        ->send();
                }),

            Action::make('flushAll')
                ->label('Flush All')
                ->icon('heroicon-o-trash')
                ->color('danger')
                ->requiresConfirmation()
                ->modalHeading('Flush All Failed Jobs')
                ->modalDescription('Are you sure you want to permanently delete all failed jobs? This action cannot be undone.')
                ->action(function () {
                    Artisan::call('queue:flush');

                    Notification::make()
                        ->title('All failed jobs have been deleted')
                        ->warning()
                        ->send();
                }),
        ];
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(FailedJob::query())
            ->columns([
                Tables\Columns\TextColumn::make('id')
                    ->label('ID')
                    ->sortable(),

                Tables\Columns\TextColumn::make('uuid')
                    ->label('UUID')
                    ->limit(12)
                    ->copyable()
                    ->tooltip(fn ($state) => $state),

                Tables\Columns\TextColumn::make('queue')
                    ->label('Queue')
                    ->badge()
                    ->color('gray'),

                Tables\Columns\TextColumn::make('payload')
                    ->label('Job')
                    ->formatStateUsing(function ($state) {
                        $payload = json_decode($state, true);
                        $displayName = $payload['displayName'] ?? 'Unknown';

                        return class_basename($displayName);
                    })
                    ->tooltip(function ($state) {
                        $payload = json_decode($state, true);

                        return $payload['displayName'] ?? 'Unknown';
                    }),

                Tables\Columns\TextColumn::make('exception')
                    ->label('Exception')
                    ->limit(50)
                    ->wrap()
                    ->tooltip(fn ($state) => $state),

                Tables\Columns\TextColumn::make('failed_at')
                    ->label('Failed At')
                    ->dateTime()
                    ->sortable(),
            ])
            ->defaultSort('failed_at', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('queue')
                    ->options(fn () => FailedJob::distinct()
                        ->pluck('queue', 'queue')
                        ->toArray()
                    ),
            ])
            ->actions([
                Tables\Actions\Action::make('retry')
                    ->label('Retry')
                    ->icon('heroicon-o-arrow-path')
                    ->color('warning')
                    ->requiresConfirmation()
                    ->action(function ($record) {
                        Artisan::call('queue:retry', ['id' => [$record->uuid]]);

                        Notification::make()
                            ->title('Job queued for retry')
                            ->success()
                            ->send();
                    }),

                Tables\Actions\Action::make('view')
                    ->label('View')
                    ->icon('heroicon-o-eye')
                    ->modalHeading('Failed Job Details')
                    ->modalContent(fn ($record) => view('filament.pages.failed-job-details', ['job' => $record]))
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Close'),

                Tables\Actions\Action::make('delete')
                    ->label('Delete')
                    ->icon('heroicon-o-trash')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->action(function ($record) {
                        DB::table('failed_jobs')->where('id', $record->id)->delete();

                        Notification::make()
                            ->title('Failed job deleted')
                            ->warning()
                            ->send();
                    }),
            ])
            ->bulkActions([
                Tables\Actions\BulkAction::make('retrySelected')
                    ->label('Retry Selected')
                    ->icon('heroicon-o-arrow-path')
                    ->color('warning')
                    ->requiresConfirmation()
                    ->action(function ($records) {
                        $uuids = $records->pluck('uuid')->toArray();
                        Artisan::call('queue:retry', ['id' => $uuids]);

                        Notification::make()
                            ->title(count($uuids).' jobs queued for retry')
                            ->success()
                            ->send();
                    }),

                Tables\Actions\BulkAction::make('deleteSelected')
                    ->label('Delete Selected')
                    ->icon('heroicon-o-trash')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->action(function ($records) {
                        $ids = $records->pluck('id')->toArray();
                        DB::table('failed_jobs')->whereIn('id', $ids)->delete();

                        Notification::make()
                            ->title(count($ids).' failed jobs deleted')
                            ->warning()
                            ->send();
                    }),
            ])
            ->paginated([10, 25, 50])
            ->defaultPaginationPageOption(25);
    }
}
