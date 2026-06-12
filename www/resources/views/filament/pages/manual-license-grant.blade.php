<x-filament-panels::page>
    <x-filament::section>
        <x-slot name="heading">
            Grant License Manually
        </x-slot>
        <x-slot name="description">
            Use this form to manually grant a license to a user or organization. This bypasses the normal purchase flow and should be used for promotional, partner, or compensation purposes.
        </x-slot>

        <form wire:submit="grantLicense">
            {{ $this->form }}

            <div class="mt-6 flex justify-end gap-3">
                <x-filament::button type="submit" color="primary" icon="heroicon-o-gift">
                    Grant License
                </x-filament::button>
            </div>
        </form>
    </x-filament::section>

    <x-filament::section>
        <x-slot name="heading">
            Recent Manual Grants
        </x-slot>

        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b dark:border-gray-700">
                        <th class="px-4 py-3 text-left font-medium text-gray-500 dark:text-gray-400">Date</th>
                        <th class="px-4 py-3 text-left font-medium text-gray-500 dark:text-gray-400">Recipient</th>
                        <th class="px-4 py-3 text-left font-medium text-gray-500 dark:text-gray-400">License</th>
                        <th class="px-4 py-3 text-left font-medium text-gray-500 dark:text-gray-400">Source</th>
                        <th class="px-4 py-3 text-left font-medium text-gray-500 dark:text-gray-400">Status</th>
                    </tr>
                </thead>
                <tbody class="divide-y dark:divide-gray-700">
                    @php
                        $recentUserLicenses = \App\Models\UserLicense::with(['user', 'license'])
                            ->whereIn('source', ['admin_grant', 'promotional', 'partner', 'compensation', 'trial_extension', 'other'])
                            ->orderBy('created_at', 'desc')
                            ->limit(5)
                            ->get();

                        $recentOrgLicenses = \App\Models\OrganizationLicense::with(['organization', 'license'])
                            ->whereIn('source', ['admin_grant', 'promotional', 'partner', 'compensation', 'trial_extension', 'other'])
                            ->orderBy('created_at', 'desc')
                            ->limit(5)
                            ->get();

                        $recentGrants = $recentUserLicenses->map(fn($l) => [
                            'date' => $l->created_at,
                            'recipient' => $l->user?->name ?? 'Unknown User',
                            'type' => 'User',
                            'license' => $l->license?->name ?? 'Unknown',
                            'source' => $l->source,
                            'status' => $l->status,
                        ])->concat($recentOrgLicenses->map(fn($l) => [
                            'date' => $l->created_at,
                            'recipient' => $l->organization?->name ?? 'Unknown Org',
                            'type' => 'Organization',
                            'license' => $l->license?->name ?? 'Unknown',
                            'source' => $l->source,
                            'status' => $l->status,
                        ]))->sortByDesc('date')->take(10);
                    @endphp

                    @forelse($recentGrants as $grant)
                        <tr class="hover:bg-gray-50 dark:hover:bg-gray-800">
                            <td class="px-4 py-3 text-gray-500">
                                {{ $grant['date']->format('Y-m-d H:i') }}
                            </td>
                            <td class="px-4 py-3">
                                {{ $grant['recipient'] }}
                                <span class="text-xs text-gray-400">({{ $grant['type'] }})</span>
                            </td>
                            <td class="px-4 py-3">
                                {{ $grant['license'] }}
                            </td>
                            <td class="px-4 py-3">
                                <x-filament::badge color="gray">
                                    {{ str_replace('_', ' ', ucfirst($grant['source'])) }}
                                </x-filament::badge>
                            </td>
                            <td class="px-4 py-3">
                                <x-filament::badge :color="$grant['status'] === 'active' ? 'success' : ($grant['status'] === 'trial' ? 'info' : 'gray')">
                                    {{ ucfirst($grant['status']) }}
                                </x-filament::badge>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="px-4 py-8 text-center text-gray-500">
                                No recent manual grants
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-filament::section>
</x-filament-panels::page>
