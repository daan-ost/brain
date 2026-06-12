@extends('layouts.profile')

@section('header')
    <h2 class="font-semibold text-xl text-gray-800 leading-tight">
        {{ __('messages.my_messages') }}
    </h2>
@endsection

@section('content')
    <div class="p-6">
        <section>
            <header>
                <h2 class="text-lg font-medium text-gray-900">
                    {{ __('messages.conversations') }}
                </h2>
                <p class="mt-1 text-sm text-gray-600">
                    {{ __('messages.conversations_description') }}
                </p>
            </header>

            {{-- New conversation form --}}
            <div class="mt-6 bg-white border border-gray-200 rounded-lg p-4">
                <h3 class="text-sm font-medium text-gray-900 mb-3">{{ __('messages.new_question') }}</h3>

                <form action="{{ route('profile.messages.store') }}" method="POST" enctype="multipart/form-data">
                    @csrf

                    {{-- Category dropdown --}}
                    <div class="mb-3">
                        <select
                            name="category_id"
                            class="w-full sm:w-auto rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 text-sm"
                        >
                            @foreach($categories as $category)
                                <option value="{{ $category->id }}" {{ $category->slug === 'support' ? 'selected' : '' }}>
                                    {{ $category->name }}
                                </option>
                            @endforeach
                        </select>
                        @error('category_id')
                            <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                        @enderror
                    </div>

                    {{-- Message textarea --}}
                    <div class="mb-3">
                        <textarea
                            name="content"
                            rows="3"
                            class="w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 text-sm text-gray-900 bg-white"
                            placeholder="{{ __('messages.question_placeholder') }}"
                            required
                            maxlength="1000"
                        >{{ old('content') }}</textarea>
                        @error('content')
                            <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                        @enderror
                    </div>

                    {{-- Attachment and submit --}}
                    <div class="flex flex-wrap items-center justify-between gap-3">
                        <div class="flex items-center gap-2">
                            {{-- File upload --}}
                            <label class="cursor-pointer inline-flex items-center text-sm text-gray-600 hover:text-gray-900">
                                <svg class="w-5 h-5 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.172 7l-6.586 6.586a2 2 0 102.828 2.828l6.414-6.586a4 4 0 00-5.656-5.656l-6.415 6.585a6 6 0 108.486 8.486L20.5 13"/>
                                </svg>
                                <span>{{ __('messages.add_attachment') }}</span>
                                <span class="text-xs text-gray-400 ml-1">({{ __('messages.max_attachments', ['count' => 5]) }})</span>
                                <input
                                    type="file"
                                    name="attachments[]"
                                    multiple
                                    accept="image/*,application/pdf"
                                    class="hidden"
                                    id="attachment-input"
                                >
                            </label>
                        </div>

                        <button
                            type="submit"
                            class="inline-flex items-center px-4 py-2 bg-indigo-600 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-indigo-700 focus:bg-indigo-700 active:bg-indigo-900 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 transition ease-in-out duration-150"
                        >
                            <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 19l9 2-9-18-9 18 9-2zm0 0v-8"/>
                            </svg>
                            {{ __('messages.send_question') }}
                        </button>
                    </div>

                    {{-- Selected files list --}}
                    <div id="attachment-list" class="mt-2 space-y-1 hidden"></div>

                    @error('attachments.*')
                        <p class="mt-2 text-sm text-red-600">{{ $message }}</p>
                    @enderror
                </form>
            </div>

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

            {{-- Thread list --}}
            @if($threads->count() > 0)
                <div class="mt-6 space-y-4">
                    @foreach($threads as $thread)
                        <a href="{{ route('profile.messages.show', $thread) }}"
                           class="block bg-white border rounded-lg p-4 hover:bg-gray-50 transition-colors {{ $thread->unread_count_user > 0 ? 'border-indigo-300 bg-indigo-50' : 'border-gray-200' }}">
                            <div class="flex items-start justify-between">
                                <div class="flex-1 min-w-0">
                                    <div class="flex items-center gap-2">
                                        {{-- Thumb indicator --}}
                                        @if($thread->thumb)
                                            <span class="text-xl">{{ $thread->thumb === 'up' ? '👍' : '👎' }}</span>
                                        @endif

                                        {{-- Title --}}
                                        <h3 class="text-sm font-semibold text-gray-900 truncate">
                                            {{ $thread->title ?: __('messages.no_title') }}
                                        </h3>

                                        {{-- Unread badge --}}
                                        @if($thread->unread_count_user > 0)
                                            <span class="inline-flex items-center justify-center px-2 py-0.5 text-xs font-bold leading-none text-white bg-red-500 rounded-full">
                                                {{ $thread->unread_count_user }}
                                            </span>
                                        @endif
                                    </div>

                                    {{-- Category --}}
                                    @if($thread->category)
                                        <p class="mt-1 text-xs text-gray-500">
                                            {{ $thread->category->name }}
                                        </p>
                                    @endif

                                    {{-- Last message preview --}}
                                    @if($thread->latestMessage)
                                        <p class="mt-2 text-sm text-gray-600 line-clamp-2">
                                            @if($thread->latestMessage->sender_type === 'admin')
                                                <span class="font-medium text-indigo-600">{{ config('app.name') }} Support:</span>
                                            @endif
                                            {{ Str::limit(strip_html_for_preview($thread->latestMessage->content), 100) }}
                                        </p>
                                    @endif
                                </div>

                                <div class="ml-4 flex flex-col items-end">
                                    {{-- Status badge --}}
                                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium
                                        @if($thread->status === 'open') bg-green-100 text-green-800
                                        @elseif($thread->status === 'waiting_for_user') bg-blue-100 text-blue-800
                                        @else bg-gray-100 text-gray-800
                                        @endif">
                                        {{ __('messages.status_' . $thread->status) }}
                                    </span>

                                    {{-- Date --}}
                                    <p class="mt-2 text-xs text-gray-500">
                                        <span class="local-time-relative" data-timestamp="{{ ($thread->last_message_at ?? $thread->created_at)->toISOString() }}">{{ $thread->last_message_at?->diffForHumans() ?? $thread->created_at->diffForHumans() }}</span>
                                    </p>
                                </div>
                            </div>
                        </a>
                    @endforeach
                </div>

                {{-- Pagination --}}
                <div class="mt-6">
                    {{ $threads->links() }}
                </div>
            @else
                <div class="mt-6 text-center py-12 bg-gray-50 rounded-lg">
                    <svg class="mx-auto h-12 w-12 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z" />
                    </svg>
                    <h3 class="mt-2 text-sm font-medium text-gray-900">{{ __('messages.no_messages') }}</h3>
                    <p class="mt-1 text-sm text-gray-500">{{ __('messages.no_messages_description') }}</p>
                </div>
            @endif
        </section>
    </div>

    <script>
        (function() {
            const input = document.getElementById('attachment-input');
            const listContainer = document.getElementById('attachment-list');
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

        // Convert timestamps to relative local time
        (function() {
            function timeAgo(date) {
                const now = new Date();
                const seconds = Math.floor((now - date) / 1000);

                if (seconds < 60) return '{{ __('messages.just_now') }}';

                const minutes = Math.floor(seconds / 60);
                if (minutes < 60) return minutes + ' {{ __('messages.minutes_ago') }}';

                const hours = Math.floor(minutes / 60);
                if (hours < 24) return hours + ' {{ __('messages.hours_ago') }}';

                const days = Math.floor(hours / 24);
                if (days < 7) return days + ' {{ __('messages.days_ago') }}';

                // For older dates, show the actual date
                return date.toLocaleString(undefined, {
                    year: 'numeric',
                    month: 'short',
                    day: 'numeric',
                    hour: '2-digit',
                    minute: '2-digit'
                });
            }

            document.querySelectorAll('.local-time-relative').forEach(el => {
                const timestamp = el.dataset.timestamp;
                if (timestamp) {
                    const date = new Date(timestamp);
                    el.textContent = timeAgo(date);
                }
            });
        })();
    </script>
@endsection
