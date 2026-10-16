<?php

declare(strict_types=1);

namespace Workbench\Database\Seeders;

use Illuminate\Database\Seeder;
use Workbench\Database\Factories\UserFactory;

/**
 * The Workbench app's data, which composer serve seeds afresh each time: two users
 * for the dashboard's two roles, and the demo's rules and numbers (CONTRIBUTING.md).
 */
class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        UserFactory::new()->create(['name' => 'Manager', 'email' => 'manager@example.com']);
        UserFactory::new()->create(['name' => 'Viewer', 'email' => 'viewer@example.com']);

        $this->call(DemoSeeder::class);
    }
}
