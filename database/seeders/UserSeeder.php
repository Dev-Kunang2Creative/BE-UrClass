<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class UserSeeder extends Seeder
{
    public function run(): void
    {
        $students = [
            [
                'name' => 'Budi Santoso',
                'email' => 'budi@siswa.test',
                'phone_number' => '081234567890',
                'school_origin' => 'SMA Negeri 1 Jakarta',
                'grade_level' => '12',
                'gender' => 'L',
                'target_university_1' => 'Universitas Indonesia',
                'target_major_1' => 'Teknik Informatika',
            ],
            [
                'name' => 'Siti Rahayu',
                'email' => 'siti@siswa.test',
                'phone_number' => '082345678901',
                'school_origin' => 'SMA Negeri 3 Bandung',
                'grade_level' => '12',
                'gender' => 'P',
                'target_university_1' => 'Institut Teknologi Bandung',
                'target_major_1' => 'Teknik Kimia',
            ],
            [
                'name' => 'Andi Wijaya',
                'email' => 'andi@siswa.test',
                'phone_number' => '083456789012',
                'school_origin' => 'SMA Muhammadiyah 1 Surabaya',
                'grade_level' => '12',
                'gender' => 'L',
                'target_university_1' => 'Universitas Gadjah Mada',
                'target_major_1' => 'Kedokteran',
            ],
            [
                'name' => 'Dewi Lestari',
                'email' => 'dewi@siswa.test',
                'phone_number' => '084567890123',
                'school_origin' => 'SMAN 1 Yogyakarta',
                'grade_level' => '12',
                'gender' => 'P',
                'target_university_1' => 'Universitas Gadjah Mada',
                'target_major_1' => 'Akuntansi',
            ],
            [
                'name' => 'Rizky Pratama',
                'email' => 'rizky@siswa.test',
                'phone_number' => '085678901234',
                'school_origin' => 'SMA Negeri 2 Medan',
                'grade_level' => '11',
                'gender' => 'L',
                'target_university_1' => 'Universitas Sumatera Utara',
                'target_major_1' => 'Hukum',
            ],
        ];

        foreach ($students as $student) {
            User::updateOrCreate(
                ['email' => $student['email']],
                array_merge($student, [
                    'password' => Hash::make('password123'),
                    'role' => 'user',
                ])
            );
        }
    }
}
