<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\DB;

class AdminSeeder extends Seeder
{
    public function run()
    {
        $name = env('ADMIN_NAME', 'Devteam Admin');
        $email = env('ADMIN_EMAIL', 'admin@example.com');
        $password = env('ADMIN_PASSWORD');

        if (blank($password)) {
            throw new \RuntimeException('Defina ADMIN_PASSWORD antes de executar o AdminSeeder.');
        }

        // Ensure users table exists; this seeder assumes Laravel default users migration
        DB::table('users')->insert([
            'name' => $name,
            'email' => $email,
            'password' => Hash::make($password),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Create basic roles if table exists
        if (DB::getSchemaBuilder()->hasTable('roles')) {
            $roleId = DB::table('roles')->insertGetId(['name' => 'administrator', 'created_at' => now(), 'updated_at' => now()]);
            $userId = DB::table('users')->where('email', $email)->value('id');
            if ($userId) {
                DB::table('role_user')->insert(['role_id' => $roleId, 'user_id' => $userId, 'created_at' => now(), 'updated_at' => now()]);
            }
        }
    }
}
