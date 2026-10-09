<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $email = config('commerce.admin_email');
        $password = config('commerce.admin_password');
        if (! $email || ! $password) {
            return;
        }
        if (strlen($password) < 16 || str_starts_with($password, 'replace-with')) {
            throw new \RuntimeException('ADMIN_PASSWORD requiere al menos 16 caracteres y no puede ser un placeholder');
        }
        User::firstOrCreate(['email' => strtolower(trim($email))], ['password' => $password, 'role' => 'ADMIN']);
    }
}
