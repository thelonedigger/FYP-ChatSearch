<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Seeds local development users (User A through User D).
 * Idempotent — safe to run multiple times without creating duplicates.
 */
class DevUserSeeder extends Seeder
{
    public function run(): void
    {
        $users = [
            ['name' => 'User A', 'email' => 'user-a@dev.local'],
            ['name' => 'User B', 'email' => 'user-b@dev.local'],
            ['name' => 'User C', 'email' => 'user-c@dev.local'],
            ['name' => 'User D', 'email' => 'user-d@dev.local'],
        ];

        foreach ($users as $userData) {
            User::firstOrCreate(
                ['email' => $userData['email']],
                [
                    'name'     => $userData['name'],
                    'password' => 'password', // Irrelevant — dev login skips password checks
                ],
            );
        }
    }
}