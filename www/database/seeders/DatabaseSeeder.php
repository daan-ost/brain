<?php

namespace Database\Seeders;

use App\Models\User;
// use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // Seed licenses first (required for other seeders)
        $this->call([
            LicenseSeeder::class,
            AdminSeeder::class,
            TestUsersSeeder::class,
            MessageCategorySeeder::class,
        ]);

        // User::factory(10)->create();

        // Test users are already created by TestUsersSeeder
        // User::factory()->create([
        //     'name' => 'Test User',
        //     'email' => 'test@example.com',
        // ]);
    }
}
