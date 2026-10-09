<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        User::query()->firstOrCreate(['username' => 'admin'], [
            'full_name' => 'AquaTrack Administrator',
            'email' => 'admin@aquatrack.test',
            'password' => Hash::make('admin123'),
            'role' => 'Admin',
            'status' => 'Active',
        ]);
    }
}
