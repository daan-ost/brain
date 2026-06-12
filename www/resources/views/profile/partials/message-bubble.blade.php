@php
    $isUser = $message->sender_type === 'user';
@endphp

<div class="flex {{ $isUser ? 'justify-end' : 'justify-start' }}">
    <div class="max-w-[80%] rounded-lg px-4 py-2 {{ $isUser ? 'bg-indigo-600 text-white' : 'bg-gray-100 text-gray-900' }}">
        <div class="text-xs {{ $isUser ? 'text-indigo-200' : 'text-gray-500' }} mb-1">
            @if($isUser)
                {{ __('messages.you') }}
            @else
                {{ $message->sender?->name ?? config('app.name') . ' Support' }}
            @endif
            &bull;
            <span class="local-time" data-timestamp="{{ $message->created_at->toISOString() }}">{{ $message->created_at->format('d M Y H:i') }}</span>
        </div>
        @if($message->sender_type === 'admin')
            {{-- Admin messages: render as HTML (trusted source) --}}
            <div class="text-sm prose prose-sm max-w-none {{ $isUser ? '' : 'prose-indigo' }} [&_a]:underline [&_a]:text-current">
                {!! \Illuminate\Support\Str::markdown($message->content) !!}
            </div>
        @else
            {{-- User messages: plain text with clickable links --}}
            <p class="text-sm whitespace-pre-wrap">{!! linkify(e($message->content)) !!}</p>
        @endif

        {{-- Attachments --}}
        @if($message->attachments && count($message->attachments) > 0)
            <div class="mt-2 space-y-1">
                @foreach($message->attachments as $attachment)
                    <a href="{{ \URL::temporarySignedRoute('thread.attachment.download', now()->addMinutes(15), ['path' => base64_encode($attachment['path'])]) }}"
                       class="inline-flex items-center gap-1 text-xs {{ $isUser ? 'text-indigo-200 hover:text-white' : 'text-indigo-600 hover:text-indigo-800' }}"
                       target="_blank">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.172 7l-6.586 6.586a2 2 0 102.828 2.828l6.414-6.586a4 4 0 00-5.656-5.656l-6.415 6.585a6 6 0 108.486 8.486L20.5 13"/>
                        </svg>
                        {{ $attachment['original_name'] ?? basename($attachment['path']) }}
                    </a>
                @endforeach
            </div>
        @endif
    </div>
</div>
