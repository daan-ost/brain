<?php

/**
 * Project-specific extensions
 *
 * This file allows you to extend basewebsite functionality without modifying core files.
 * Add your project-specific models, relations, and configurations here.
 *
 * @see /docs/howto/extending-basewebsite.md
 */

return [

    /*
    |--------------------------------------------------------------------------
    | Project Information
    |--------------------------------------------------------------------------
    */

    'name' => env('PROJECT_NAME', 'NoBrainersBot'),
    'version' => env('PROJECT_VERSION', '1.0.0'),

    /*
    |--------------------------------------------------------------------------
    | Filament Admin Panel Extensions
    |--------------------------------------------------------------------------
    |
    | Add custom RelationManagers to existing resources.
    | These will be merged with the base relations.
    |
    | Example:
    | 'user_relations' => [
    |     \App\Filament\Resources\UserResource\RelationManagers\EventsRelationManager::class,
    | ],
    |
    */

    'filament' => [
        // Extra relations for UserResource
        'user_relations' => [
            // \App\Filament\Resources\UserResource\RelationManagers\EventsRelationManager::class,
        ],

        // Extra relations for OrganizationResource
        'organization_relations' => [
            // \App\Filament\Resources\OrganizationResource\RelationManagers\ProjectsRelationManager::class,
        ],

        // Extra navigation items
        'navigation_groups' => [
            // 'Project Features' => 10, // name => sort order
        ],

        // Extra resources to register
        'resources' => [
            // \App\Filament\Resources\EventResource::class,
        ],

        // Extra pages to register
        'pages' => [
            // \App\Filament\Pages\ProjectDashboard::class,
        ],

        // Extra widgets to register
        'widgets' => [
            // \App\Filament\Widgets\EventStatsWidget::class,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Model Extensions
    |--------------------------------------------------------------------------
    |
    | Define extra relationships to add to base models.
    | The key is the relationship name, value is [RelatedModel::class, 'foreign_key'].
    |
    | Example:
    | 'user_has_many' => [
    |     'events' => [\App\Models\Event::class, 'user_id'],
    | ],
    |
    */

    'models' => [
        // Extra hasMany relationships for User model
        'user_has_many' => [
            // 'events' => [\App\Models\Event::class, 'user_id'],
        ],

        // Extra hasMany relationships for Organization model
        'organization_has_many' => [
            // 'projects' => [\App\Models\Project::class, 'organization_id'],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Service Extensions
    |--------------------------------------------------------------------------
    |
    | Register project-specific services.
    |
    */

    'services' => [
        // 'event' => \App\Services\EventService::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Feature Flags
    |--------------------------------------------------------------------------
    |
    | Enable/disable basewebsite features for this project.
    |
    */

    'features' => [
        'organizations' => true,
        'credits' => true,
        'licenses' => true,
        'analytics' => true,
        'multi_language' => true,
    ],

];
