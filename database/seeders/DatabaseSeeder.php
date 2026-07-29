<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            AdminUserSeeder::class,
            UserSeeder::class,
            SubtestSeeder::class,
            TryoutSeeder::class,
            QuestionSeeder::class,
            PackageSeeder::class,
        ]);
    }
}