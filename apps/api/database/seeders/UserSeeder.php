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
                'name' => 'Thariq',
                'email' => 'thariq@roketboy.com',
                'password' => 'rahasia',
            ],
            [
                'name' => 'Omar',
                'email' => 'caid.cakep@gmail.com',
                'password' => 'rahasia',
            ],
            [
                'name' => 'Guston',
                'email' => 'guston@gmail.com',
                'password' => 'rahasia',
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
