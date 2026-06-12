<div class="bg-gray-50 dark:bg-gray-900 rounded-lg p-4">
    @if(empty($data))
        <p class="text-gray-500 text-sm italic">No metadata available</p>
    @else
        <pre class="text-xs overflow-x-auto"><code class="language-json">{{ json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</code></pre>
    @endif
</div>