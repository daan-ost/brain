<div class="space-y-4">
    <div>
        <h4 class="text-sm font-medium text-gray-500 dark:text-gray-400">UUID</h4>
        <p class="mt-1 font-mono text-sm">{{ $job->uuid }}</p>
    </div>

    <div>
        <h4 class="text-sm font-medium text-gray-500 dark:text-gray-400">Queue</h4>
        <p class="mt-1">{{ $job->queue }}</p>
    </div>

    <div>
        <h4 class="text-sm font-medium text-gray-500 dark:text-gray-400">Failed At</h4>
        <p class="mt-1">{{ $job->failed_at }}</p>
    </div>

    <div>
        <h4 class="text-sm font-medium text-gray-500 dark:text-gray-400">Payload</h4>
        <pre class="mt-2 p-4 bg-gray-50 dark:bg-gray-800 rounded-lg text-xs overflow-auto max-h-48">{{ json_encode(json_decode($job->payload), JSON_PRETTY_PRINT) }}</pre>
    </div>

    <div>
        <h4 class="text-sm font-medium text-gray-500 dark:text-gray-400">Exception</h4>
        <pre class="mt-2 p-4 bg-red-50 dark:bg-red-900/20 rounded-lg text-xs overflow-auto max-h-96 whitespace-pre-wrap">{{ $job->exception }}</pre>
    </div>
</div>
