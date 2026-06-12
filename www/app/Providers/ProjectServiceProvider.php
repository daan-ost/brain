<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

/**
 * ProjectServiceProvider
 *
 * Loads project-specific extensions defined in config/project.php.
 * This keeps basewebsite core files untouched while allowing customization.
 *
 * @see /docs/howto/extending-basewebsite.md
 */
class ProjectServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->registerProjectServices();
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->bootModelExtensions();
    }

    /**
     * Register project-specific services from config.
     */
    private function registerProjectServices(): void
    {
        $services = config('project.services', []);

        foreach ($services as $alias => $serviceClass) {
            if (class_exists($serviceClass)) {
                $this->app->singleton($alias, $serviceClass);
            }
        }
    }

    /**
     * Boot model extensions (relationships) from config.
     */
    private function bootModelExtensions(): void
    {
        // Add dynamic relationships to User model
        $this->extendUserModel();

        // Add dynamic relationships to Organization model
        $this->extendOrganizationModel();
    }

    /**
     * Add project-specific relationships to User model.
     */
    private function extendUserModel(): void
    {
        $relations = config('project.models.user_has_many', []);

        foreach ($relations as $name => $config) {
            if (is_array($config) && count($config) >= 2) {
                [$relatedClass, $foreignKey] = $config;

                if (class_exists($relatedClass)) {
                    \App\Models\User::resolveRelationUsing($name, function ($model) use ($relatedClass, $foreignKey) {
                        return $model->hasMany($relatedClass, $foreignKey);
                    });
                }
            }
        }
    }

    /**
     * Add project-specific relationships to Organization model.
     */
    private function extendOrganizationModel(): void
    {
        $relations = config('project.models.organization_has_many', []);

        foreach ($relations as $name => $config) {
            if (is_array($config) && count($config) >= 2) {
                [$relatedClass, $foreignKey] = $config;

                if (class_exists($relatedClass)) {
                    \App\Models\Organization::resolveRelationUsing($name, function ($model) use ($relatedClass, $foreignKey) {
                        return $model->hasMany($relatedClass, $foreignKey);
                    });
                }
            }
        }
    }

    /**
     * Get extra Filament relations for a resource.
     *
     * Usage in Resource:
     * public static function getRelations(): array
     * {
     *     return array_merge([
     *         // base relations
     *     ], ProjectServiceProvider::getExtraRelations('user'));
     * }
     */
    public static function getExtraRelations(string $resource): array
    {
        $key = "project.filament.{$resource}_relations";
        $relations = config($key, []);

        return array_filter($relations, fn ($class) => class_exists($class));
    }

    /**
     * Get extra Filament resources to register.
     */
    public static function getExtraResources(): array
    {
        $resources = config('project.filament.resources', []);

        return array_filter($resources, fn ($class) => class_exists($class));
    }

    /**
     * Get extra Filament pages to register.
     */
    public static function getExtraPages(): array
    {
        $pages = config('project.filament.pages', []);

        return array_filter($pages, fn ($class) => class_exists($class));
    }

    /**
     * Get extra Filament widgets to register.
     */
    public static function getExtraWidgets(): array
    {
        $widgets = config('project.filament.widgets', []);

        return array_filter($widgets, fn ($class) => class_exists($class));
    }

    /**
     * Check if a feature is enabled.
     */
    public static function featureEnabled(string $feature): bool
    {
        return config("project.features.{$feature}", true);
    }
}
