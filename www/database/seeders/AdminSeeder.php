<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;

class AdminSeeder extends Seeder
{
    public function run(): void
    {
        // Create admin role if it doesn't exist
        $adminRole = Role::firstOrCreate(['name' => 'admin']);

        // Create admin user if it doesn't exist
        $adminUser = User::firstOrCreate(
            ['email' => 'admin@example.com'],
            [
                'name' => 'Admin User',
                'email' => 'admin@example.com',
                'password' => bcrypt('admin123'), // Change this in production!
                'email_verified_at' => now(),
                'credits' => 1000,
            ]
        );

        // Assign admin role to user
        if (! $adminUser->hasRole('admin')) {
            $adminUser->assignRole($adminRole);
        }

        $this->command->info('Admin user created: admin@example.com / admin123');
    }
}
