<x-desktop-only-notice
    title="Mission Builder"
    description="The visual builder requires a larger screen. Switch to a tablet or desktop to edit behavior trees."
    hint="You can still view the mission JSON below."
>
    <div class="bg-white dark:bg-gray-800 rounded-lg border border-gray-200 dark:border-gray-700" style="height: 640px;">
        {{-- BT Builder canvas and controls will be rendered here --}}
        <div class="flex h-full">
            {{-- Left panel: node palette --}}
            <div class="w-64 border-r border-gray-200 dark:border-gray-700 p-4 overflow-y-auto">
                <h4 class="text-sm font-medium text-gray-700 dark:text-gray-300 mb-3">Nodes</h4>
            </div>

            {{-- Center: canvas --}}
            <div class="flex-1 relative overflow-hidden">
            </div>

            {{-- Right panel: properties --}}
            <div class="w-72 border-l border-gray-200 dark:border-gray-700 p-4 overflow-y-auto">
                <h4 class="text-sm font-medium text-gray-700 dark:text-gray-300 mb-3">Properties</h4>
            </div>
        </div>
    </div>
</x-desktop-only-notice>
