<div class="space-y-4">
    <div>
        <h4 class="text-sm font-medium text-gray-500 dark:text-gray-400">Subject</h4>
        <p class="mt-1">{{ $template->subject ?: 'No subject' }}</p>
    </div>

    <div>
        <h4 class="text-sm font-medium text-gray-500 dark:text-gray-400">HTML Preview</h4>
        <div class="mt-2 p-4 bg-white border rounded-lg max-h-96 overflow-auto">
            {!! $template->html_body ?: '<em class="text-gray-400">No HTML content</em>' !!}
        </div>
    </div>

    @if($template->text_body)
    <div>
        <h4 class="text-sm font-medium text-gray-500 dark:text-gray-400">Text Version</h4>
        <pre class="mt-2 p-4 bg-gray-50 dark:bg-gray-800 rounded-lg text-sm whitespace-pre-wrap max-h-48 overflow-auto">{{ $template->text_body }}</pre>
    </div>
    @endif
</div>
