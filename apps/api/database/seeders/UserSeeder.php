<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

class UserSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $users = [
            [
                'name' => 'Admin User',
                'email' => 'admin@example.com',
                'password' => 'password',
            ],
            [
                'name' => 'Manager User',
                'email' => 'manager@example.com',
                'password' => 'password',
            ],
            [
                'name' => 'Staff User',
                'email' => 'staff@example.com',
                'password' => 'password',
            ],
        ];

        foreach ($users as $attributes) {
            User::updateOrCreate(
                ['email' => $attributes['email']],
                $attributes,
            );
        }
    }
}
