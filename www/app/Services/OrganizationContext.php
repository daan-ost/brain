<?php

namespace App\Services;

class OrganizationContext
{
    /**
     * Resolve the current organization ID from the session.
     *
     * Returns null (bypass scoping) for:
     * - Console/CLI commands
     * - Filament admin panel (/beheer)
     * - Unauthenticated users
     * - Users without an active organization in session
     */
    public function id(): ?int
    {
        // No scoping in CLI context (migrations, commands, tinker)
        if (app()->runningInConsole()) {
            return null;
        }

        // No scoping for Filament admin panel
        $request = request();
        if ($request && $request->is('beheer/*', 'beheer', 'livewire/update')) {
            // Check if the livewire update is for a Filament component
            if ($request->is('livewire/update')) {
                $referer = $request->headers->get('referer', '');
                if (str_contains($referer, '/beheer')) {
                    return null;
                }
            } else {
                return null;
            }
        }

        // No scoping if not authenticated
        if (! auth()->check()) {
            return null;
        }

        return session('current_organization_id');
    }
}
