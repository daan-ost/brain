<section>
    <header>
        <h2 class="text-lg font-medium text-gray-900">
            {{ __('profile.workflows_title') }}
        </h2>

        <p class="mt-1 text-sm text-gray-600">
            {{ __('profile.workflows_description') }}
        </p>
    </header>

    <div class="mt-6">
        <livewire:workflow-manager />
    </div>
</section>