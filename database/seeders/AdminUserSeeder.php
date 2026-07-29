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
            ['email' => 'admin@amunisi.test'],
            [
                'name' => 'Admin Amunisi',
                'password' => Hash::make('password123'),
                'role' => 'admin',
            ]
        );
    }
}