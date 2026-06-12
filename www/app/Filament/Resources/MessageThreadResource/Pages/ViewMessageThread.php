<?php

namespace App\Filament\Resources\MessageThreadResource\Pages;

use App\Filament\Resources\MessageThreadResource;
use App\Jobs\SendTrustpilotInvite;
use App\Models\MessageThread;
use App\Models\ThreadMessage;
use Filament\Actions;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;

class ViewMessageThread extends ViewRecord
{
    protected static string $resource = MessageThreadResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('reply')
                ->label('Reply')
                ->icon('heroicon-o-paper-airplane')
                ->color('primary')
                ->form([
                    Forms\Components\RichEditor::make('content')
                        ->label('Message')
                        ->required()
                        ->toolbarButtons([
                            'bold',
                            'italic',
                            'link',
                            'bulletList',
                            'orderedList',
                        ]),
                    Forms\Components\FileUpload::make('attachments')
                        ->label('Attachments')
                        ->multiple()
                        ->maxFiles(10)
                        ->maxSize(51200) // 50MB
                        ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'application/pdf'])
                        ->disk('local')
                        ->directory('thread-attachments/'.now()->format('Y/m'))
                        ->visibility('private')
                        ->preserveFilenames(false)
                        ->helperText('Max 10 files, 50MB each. Images and PDF only.'),

                    Forms\Components\Toggle::make('send_trustpilot_invite')
                        ->label('Stuur Trustpilot-uitnodiging naar deze klant')
                        ->default(false)
                        ->visible(fn () => (bool) config('services.trustpilot.afs_email')),
                ])
                ->action(function (array $data): void {
                    $thread = $this->getRecord();

                    // Process attachments from Filament FileUpload
                    $attachments = [];
                    if (! empty($data['attachments'])) {
                        foreach ($data['attachments'] as $path) {
                            // Filament stores relative path in local disk
                            $attachments[] = [
                                'path' => $path,
                                'original_name' => basename($path),
                                'type' => Storage::disk('local')->mimeType($path) ?? 'application/octet-stream',
                                'size_kb' => round(Storage::disk('local')->size($path) / 1024),
                            ];
                        }
                    }

                    ThreadMessage::create([
                        'thread_id' => $thread->id,
                        'sender_id' => Auth::id(),
                        'sender_type' => MessageThread::SENDER_ADMIN,
                        'content' => $data['content'],
                        'attachments' => ! empty($attachments) ? $attachments : null,
                        'is_read' => false,
                    ]);

                    $thread->update([
                        'status' => MessageThread::STATUS_WAITING_FOR_USER,
                        'unread_count_admin' => 0,
                        'last_message_at' => now(),
                        'last_message_from' => MessageThread::SENDER_ADMIN,
                    ]);

                    $thread->incrementUnreadForUser();

                    // Trustpilot invite
                    if (! empty($data['send_trustpilot_invite']) && $thread->user) {
                        $user = $thread->user;
                        $locale = $user->preferred_language ?? config('app.locale', 'en');

                        SendTrustpilotInvite::dispatch(
                            userId: $user->id,
                            locale: $locale
                        );

                        $thread->update([
                            'context_json' => array_merge($thread->context_json ?? [], [
                                'trustpilot_invite_sent_at' => now()->toISOString(),
                                'trustpilot_invite_sent_by' => Auth::id(),
                            ]),
                        ]);
                    }

                    Notification::make()
                        ->title('Reply sent successfully')
                        ->success()
                        ->send();

                    $this->refreshFormData(['messages']);
                }),

            Actions\Action::make('updateStatus')
                ->label('Change Status')
                ->icon('heroicon-o-arrow-path')
                ->color('gray')
                ->form([
                    Forms\Components\Select::make('status')
                        ->options([
                            'open' => 'Open',
                            'waiting_for_user' => 'Waiting for User',
                            'closed' => 'Closed',
                        ])
                        ->default(fn () => $this->getRecord()->status)
                        ->required(),
                ])
                ->action(function (array $data): void {
                    $this->getRecord()->update(['status' => $data['status']]);

                    Notification::make()
                        ->title('Status updated')
                        ->success()
                        ->send();
                }),

            Actions\Action::make('markRead')
                ->label('Mark as Read')
                ->icon('heroicon-o-check')
                ->color('success')
                ->visible(fn () => $this->getRecord()->unread_count_admin > 0)
                ->action(function (): void {
                    $this->getRecord()->markReadForAdmin();

                    Notification::make()
                        ->title('Marked as read')
                        ->success()
                        ->send();
                }),
        ];
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        // Mark messages as read when viewing
        $this->getRecord()->markReadForAdmin();

        return $data;
    }
}
