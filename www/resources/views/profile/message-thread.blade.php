@extends('layouts.profile')

@section('header')
    <div class="flex items-center justify-between">
        <div class="flex items-center gap-3">
            <a href="{{ route('profile.messages') }}" class="text-gray-500 hover:text-gray-700">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/>
                </svg>
            </a>
            <h2 class="font-semibold text-xl text-gray-800 leading-tight">
                @if($thread->thumb)
                    <span class="mr-1">{{ $thread->thumb === 'up' ? '👍' : '👎' }}</span>
                @endif
                {{ $thread->title ?: __('messages.no_title') }}
            </h2>
        </div>
        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium
            @if($thread->status === 'open') bg-green-100 text-green-800
            @elseif($thread->status === 'waiting_for_user') bg-blue-100 text-blue-800
            @else bg-gray-100 text-gray-800
            @endif">
            {{ __('messages.status_' . $thread->status) }}
        </span>
    </div>
@endsection

@section('content')
    <div class="p-6">
        {{-- Messages --}}
        <div id="messages-container" class="space-y-4 mb-6" data-last-message-id="{{ $thread->messages->last()?->id ?? 0 }}">
            @foreach($thread->messages as $message)
                @include('profile.partials.message-bubble', ['message' => $message])
            @endforeach
        </div>

        {{-- Reply form --}}
        @if(!$thread->isClosed())
            <form action="{{ route('profile.messages.reply', $thread) }}" method="POST" enctype="multipart/form-data" class="mt-6">
                @csrf
                <div class="flex gap-3">
                    <div class="flex-1">
                        <textarea
                            name="content"
                            rows="2"
                            class="w-full rounded-lg border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 resize-none"
                            placeholder="{{ __('messages.type_reply') }}"
                            required
                            maxlength="1000"
                        ></textarea>
                        <div class="mt-2 flex items-center justify-between">
                            <label class="cursor-pointer inline-flex items-center text-sm text-gray-500 hover:text-gray-700">
                                <svg class="w-5 h-5 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.172 7l-6.586 6.586a2 2 0 102.828 2.828l6.414-6.586a4 4 0 00-5.656-5.656l-6.415 6.585a6 6 0 108.486 8.486L20.5 13"/>
                                </svg>
                                <span>{{ __('messages.add_attachment') }}</span>
                                <span class="text-xs text-gray-400 ml-1">({{ __('messages.max_attachments', ['count' => 5]) }})</span>
                                <input type="file" name="attachments[]" multiple accept="image/*,application/pdf" class="hidden" id="reply-attachment-input">
                            </label>
                        </div>
                        <div id="reply-attachment-list" class="mt-2 space-y-1 hidden"></div>
                    </div>
                    <button
                        type="submit"
                        class="self-start px-4 py-2 bg-indigo-600 text-white rounded-lg hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500"
                    >
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 19l9 2-9-18-9 18 9-2zm0 0v-8"/>
                        </svg>
                    </button>
                </div>
                @error('content')
                    <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                @enderror
                @error('attachments.*')
                    <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                @enderror
            </form>
        @else
            <div class="mt-6 text-center py-4 bg-gray-50 rounded-lg">
                <p class="text-sm text-gray-500">{{ __('messages.thread_closed') }}</p>
            </div>
        @endif

        {{-- Status messages --}}
        @if(session('status'))
            <div class="mt-4 p-3 bg-green-50 border border-green-200 rounded-lg text-sm text-green-700">
                {{ session('status') }}
            </div>
        @endif
        @if(session('error'))
            <div class="mt-4 p-3 bg-red-50 border border-red-200 rounded-lg text-sm text-red-700">
                {{ session('error') }}
            </div>
        @endif
    </div>

    {{-- Polling script --}}
    <script>
        (function() {
            const container = document.getElementById('messages-container');
            const pollUrl = '{{ route('profile.messages.check', $thread) }}';
            let lastMessageId = parseInt(container.dataset.lastMessageId) || 0;
            const pollInterval = 30000; // 30 seconds

            function formatLocalTime(isoString) {
                const date = new Date(isoString);
                return date.toLocaleString(undefined, {
                    day: 'numeric',
                    month: 'short',
                    year: 'numeric',
                    hour: '2-digit',
                    minute: '2-digit'
                });
            }

            function createMessageBubble(msg) {
                const div = document.createElement('div');
                div.className = msg.is_mine
                    ? 'flex justify-end'
                    : 'flex justify-start';

                const bubble = document.createElement('div');
                bubble.className = msg.is_mine
                    ? 'max-w-[80%] rounded-lg px-4 py-2 bg-indigo-600 text-white'
                    : 'max-w-[80%] rounded-lg px-4 py-2 bg-gray-100 text-gray-900';

                const header = document.createElement('div');
                header.className = msg.is_mine
                    ? 'text-xs text-indigo-200 mb-1'
                    : 'text-xs text-gray-500 mb-1';
                // msg.created_at is ISO string from API, convert to local time
                const localTime = formatLocalTime(msg.created_at);
                header.textContent = msg.sender_name + ' • ' + localTime;

                // Use content_html which is pre-rendered server-side
                const content = document.createElement('div');
                if (msg.sender_type === 'admin') {
                    // Admin messages: rendered HTML with prose styling
                    content.className = 'text-sm prose prose-sm max-w-none [&_a]:underline [&_a]:text-current';
                    content.innerHTML = msg.content_html;
                } else {
                    // User messages: plain text with linkified URLs
                    content.className = 'text-sm whitespace-pre-wrap';
                    content.innerHTML = msg.content_html;
                }

                bubble.appendChild(header);
                bubble.appendChild(content);
                div.appendChild(bubble);

                return div;
            }

            function poll() {
                fetch(pollUrl + '?last_message_id=' + lastMessageId, {
                    headers: {
                        'Accept': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest'
                    }
                })
                .then(response => response.json())
                .then(data => {
                    if (data.messages && data.messages.length > 0) {
                        data.messages.forEach(msg => {
                            container.appendChild(createMessageBubble(msg));
                            lastMessageId = Math.max(lastMessageId, msg.id);
                        });
                        container.dataset.lastMessageId = lastMessageId;
                        // Scroll to bottom
                        container.scrollTop = container.scrollHeight;
                    }
                })
                .catch(err => console.error('Polling error:', err));
            }

            // Start polling
            setInterval(poll, pollInterval);
        })();

        // Attachment management for reply form
        (function() {
            const input = document.getElementById('reply-attachment-input');
            const listContainer = document.getElementById('reply-attachment-list');
            if (!input || !listContainer) return;

            const maxFiles = 5;
            let selectedFiles = [];

            function updateFileList() {
                listContainer.innerHTML = '';

                if (selectedFiles.length === 0) {
                    listContainer.classList.add('hidden');
                    return;
                }

                listContainer.classList.remove('hidden');

                selectedFiles.forEach((file, index) => {
                    const item = document.createElement('div');
                    item.className = 'flex items-center justify-between bg-gray-50 rounded px-3 py-1.5 text-sm';

                    const nameSpan = document.createElement('span');
                    nameSpan.className = 'text-gray-700 truncate';
                    nameSpan.textContent = file.name;

                    const deleteBtn = document.createElement('button');
                    deleteBtn.type = 'button';
                    deleteBtn.className = 'ml-2 text-red-500 hover:text-red-700';
                    deleteBtn.innerHTML = '<svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>';
                    deleteBtn.onclick = function() {
                        selectedFiles.splice(index, 1);
                        syncInputFiles();
                        updateFileList();
                    };

                    item.appendChild(nameSpan);
                    item.appendChild(deleteBtn);
                    listContainer.appendChild(item);
                });
            }

            function syncInputFiles() {
                const dt = new DataTransfer();
                selectedFiles.forEach(file => dt.items.add(file));
                input.files = dt.files;
            }

            input.addEventListener('change', function(e) {
                const newFiles = Array.from(e.target.files);

                if (selectedFiles.length + newFiles.length > maxFiles) {
                    alert('{{ __('messages.too_many_attachments') }}');
                    return;
                }

                selectedFiles = selectedFiles.concat(newFiles);
                syncInputFiles();
                updateFileList();
            });
        })();

        // Convert existing message timestamps to local time
        (function() {
            document.querySelectorAll('.local-time').forEach(el => {
                const timestamp = el.dataset.timestamp;
                if (timestamp) {
                    const date = new Date(timestamp);
                    el.textContent = date.toLocaleString(undefined, {
                        day: 'numeric',
                        month: 'short',
                        year: 'numeric',
                        hour: '2-digit',
                        minute: '2-digit'
                    });
                }
            });
        })();
    </script>
@endsection
