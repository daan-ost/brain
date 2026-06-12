<?php

declare(strict_types=1);

namespace App\Livewire\Concerns;

use App\Models\Organization;

trait ResolvesOrganization
{
    protected function resolveOrganization(): ?Organization
    {
        $organizationId = session('current_organization_id');
        $user = auth()->user();

        if ($organizationId) {
            $org = $user->organizations()->find($organizationId);
            if ($org) {
                return $org;
            }
        }

        return $user->organizations()->first();
    }
}
