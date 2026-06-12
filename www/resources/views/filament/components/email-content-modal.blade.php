<div class="space-y-4">
    @if($error)
        <div class="p-4 bg-danger-50 dark:bg-danger-900/20 border border-danger-200 dark:border-danger-700 rounded-lg">
            <div class="flex items-center gap-2 text-danger-700 dark:text-danger-400">
                <x-heroicon-o-exclamation-triangle class="w-5 h-5" />
                <span class="font-medium">{{ $error }}</span>
            </div>
            <p class="mt-2 text-sm text-danger-600 dark:text-danger-300">
                Note: Postmark only retains email content for approximately 45 days.
            </p>
        </div>
    @elseif($content)
        <div class="space-y-4">
            {{-- Email Header Info --}}
            <div class="grid grid-cols-2 gap-4 p-4 bg-gray-50 dark:bg-gray-800 rounded-lg">
                <div>
                    <span class="text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">To</span>
                    <p class="text-sm text-gray-900 dark:text-gray-100">
                        {{ $content['Recipients'][0] ?? $content['To'] ?? 'Unknown' }}
                    </p>
                </div>
                <div>
                    <span class="text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">From</span>
                    <p class="text-sm text-gray-900 dark:text-gray-100">
                        {{ $content['From'] ?? 'Unknown' }}
                    </p>
                </div>
                <div>
                    <span class="text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">Subject</span>
                    <p class="text-sm text-gray-900 dark:text-gray-100 font-medium">
                        {{ $content['Subject'] ?? 'No subject' }}
                    </p>
                </div>
                <div>
                    <span class="text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">Sent At</span>
                    <p class="text-sm text-gray-900 dark:text-gray-100">
                        {{ isset($content['ReceivedAt']) ? \Carbon\Carbon::parse($content['ReceivedAt'])->format('d-m-Y H:i:s') : 'Unknown' }}
                    </p>
                </div>
            </div>

            {{-- Status Info --}}
            <div class="flex flex-wrap gap-2">
                @if(isset($content['Status']))
                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium
                        {{ $content['Status'] === 'Sent' ? 'bg-success-100 text-success-800 dark:bg-success-900/30 dark:text-success-400' : 'bg-gray-100 text-gray-800 dark:bg-gray-700 dark:text-gray-300' }}">
                        Status: {{ $content['Status'] }}
                    </span>
                @endif

                @if(isset($content['TrackOpens']) && $content['TrackOpens'])
                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-info-100 text-info-800 dark:bg-info-900/30 dark:text-info-400">
                        Opens Tracked
                    </span>
                @endif

                @if(isset($content['MessageEvents']))
                    @foreach($content['MessageEvents'] as $event)
                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium
                            {{ $event['Type'] === 'Opened' ? 'bg-success-100 text-success-800 dark:bg-success-900/30 dark:text-success-400' : 'bg-gray-100 text-gray-800 dark:bg-gray-700 dark:text-gray-300' }}">
                            {{ $event['Type'] }}
                            @if(isset($event['ReceivedAt']))
                                ({{ \Carbon\Carbon::parse($event['ReceivedAt'])->diffForHumans() }})
                            @endif
                        </span>
                    @endforeach
                @endif
            </div>

            {{-- Email Body --}}
            @if(isset($content['HtmlBody']) && $content['HtmlBody'])
                <div>
                    <span class="text-xs font-medium text-gray-500 dark:text-gray-400 uppercase mb-2 block">HTML Content</span>
                    <div class="border border-gray-200 dark:border-gray-700 rounded-lg overflow-hidden">
                        <iframe
                            srcdoc="{{ htmlspecialchars($content['HtmlBody']) }}"
                            class="w-full h-96 bg-white"
                            sandbox="allow-same-origin"
                        ></iframe>
                    </div>
                </div>
            @elseif(isset($content['TextBody']) && $content['TextBody'])
                <div>
                    <span class="text-xs font-medium text-gray-500 dark:text-gray-400 uppercase mb-2 block">Text Content</span>
                    <pre class="p-4 bg-gray-50 dark:bg-gray-800 rounded-lg text-sm text-gray-900 dark:text-gray-100 whitespace-pre-wrap overflow-auto max-h-96">{{ $content['TextBody'] }}</pre>
                </div>
            @else
                <div class="p-4 bg-warning-50 dark:bg-warning-900/20 border border-warning-200 dark:border-warning-700 rounded-lg">
                    <p class="text-sm text-warning-700 dark:text-warning-400">
                        Email content not available. This may be because:
                    </p>
                    <ul class="mt-2 text-sm text-warning-600 dark:text-warning-300 list-disc list-inside">
                        <li>The email is older than 45 days</li>
                        <li>Content retention is disabled</li>
                        <li>The message was not delivered</li>
                    </ul>
                </div>
            @endif

            {{-- Metadata --}}
            @if(isset($content['Metadata']) && !empty($content['Metadata']))
                <div>
                    <span class="text-xs font-medium text-gray-500 dark:text-gray-400 uppercase mb-2 block">Metadata</span>
                    <pre class="p-4 bg-gray-50 dark:bg-gray-800 rounded-lg text-xs text-gray-700 dark:text-gray-300 overflow-auto">{{ json_encode($content['Metadata'], JSON_PRETTY_PRINT) }}</pre>
                </div>
            @endif
        </div>
    @else
        <div class="p-4 bg-gray-50 dark:bg-gray-800 rounded-lg text-center">
            <p class="text-sm text-gray-500 dark:text-gray-400">No email content available.</p>
        </div>
    @endif
</div>
