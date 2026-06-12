<?php

namespace App\Providers;

use App\Models\Order;
use App\Models\User;
use App\Policies\InvoicePolicy;
use App\Policies\UserPolicy;
use Illuminate\Foundation\Support\Providers\AuthServiceProvider as ServiceProvider;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;

class AuthServiceProvider extends ServiceProvider
{
    /**
     * The model to policy mappings for the application.
     *
     * @var array<class-string, class-string>
     */
    protected $policies = [
        User::class => UserPolicy::class,
        Order::class => InvoicePolicy::class,
    ];

    /**
     * Register any authentication / authorization services.
     */
    public function boot(): void
    {
        $this->registerPolicies();

        $this->registerLogViewerGates();
    }

    /**
     * Register Log Viewer authorization gates.
     *
     * Note: We explicitly check the 'admin' guard instead of using the $user parameter
     * because the Log Viewer package calls Gate::allows() without passing a user.
     * The auth:admin middleware ensures authentication before these gates run.
     */
    private function registerLogViewerGates(): void
    {
        $allowAdminCallback = function (): bool {
            $user = Auth::guard('admin')->user();

            return $user && ($user->hasRole('admin') || $user->is_admin);
        };

        // Log Viewer access and download permissions
        Gate::define('viewLogViewer', $allowAdminCallback);
        Gate::define('downloadLogFile', $allowAdminCallback);
        Gate::define('downloadLogFolder', $allowAdminCallback);

        // Disable log deletion to prevent accidental data loss
        Gate::define('deleteLogFile', fn (): bool => false);
        Gate::define('deleteLogFolder', fn (): bool => false);
    }
}
