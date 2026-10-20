<?php

namespace Database\Seeders;

use App\Models\Brand;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

/**
 * Usuario y marca de demostracion para probar la app en local (QA, capturas E2E).
 * La contrasena sale de DEMO_USER_PASSWORD: nunca se versiona.
 *
 *   php artisan db:seed --class=DemoSeeder
 */
class DemoSeeder extends Seeder
{
    public function run(): void
    {
        if (app()->environment('production')) {
            throw new RuntimeException('DemoSeeder no se ejecuta en produccion.');
        }

        $password = (string) env('DEMO_USER_PASSWORD');

        if ($password === '') {
            throw new RuntimeException('Define DEMO_USER_PASSWORD en .env para crear el usuario demo.');
        }

        $user = User::query()->updateOrCreate(
            ['email' => env('DEMO_USER_EMAIL', 'demo@social-scheduler.test')],
            ['name' => 'Demo', 'password' => Hash::make($password), 'email_verified_at' => now()],
        );

        $this->command?->info("Usuario demo: {$user->email}");

        if (Brand::withoutUserScope()->where('user_id', $user->id)->doesntExist()) {
            $brand = new Brand(['name' => 'Marca demo', 'timezone' => 'Europe/Madrid']);
            $brand->user_id = $user->id;
            $brand->save();
        }
    }
}
