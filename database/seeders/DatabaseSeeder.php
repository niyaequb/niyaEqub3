<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // User::factory(10)->create();
        // create super admin with Super Admin role and attach role

        // check if super admin already exists else create
        $roleName = 'Super Admin';
        Role::firstOrCreate(['name' => $roleName]);

        $user = User::factory()->create([
            'name' => 'Super Admin',
            'email' => 'super@admin.com',
            'phone' => '0123456789',
            'password' => Hash::make('password'),
            'is_active' => true,
            'type' => 'staff',
            'email_verified_at' => now(),
            'phone_verified_at' => now(),
        ])->assignRole('Super Admin');
    }
}
