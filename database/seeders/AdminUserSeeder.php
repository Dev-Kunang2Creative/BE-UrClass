<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class AdminUserSeeder extends Seeder
{
    public function run(): void
    {
        User::updateOrCreate(
            ['email' => 'admin@urclass.id'],
            [
                'name' => 'Admin UrClass',
                'password' => Hash::make('password123'),
                'role' => 'admin',
            ]
        );

        User::updateOrCreate(
            ['email' => 'admin@amunisi.test'],
            [
                'name' => 'Admin Amunisi',
                'password' => Hash::make('password123'),
                'role' => 'admin',
            ]
        );

        User::updateOrCreate(
            ['email' => 'user@urclass.id'],
            [
                'name' => 'Farros UrClass',
                'password' => Hash::make('password123'),
                'role' => 'user',
                'kategori' => 'utbk',
            ]
        );
    }
}