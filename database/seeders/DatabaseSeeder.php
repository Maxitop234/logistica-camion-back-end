<?php

namespace Database\Seeders;

use App\Models\Administrador;
use App\Models\User;
use Illuminate\Database\Seeder;
use RuntimeException;

class DatabaseSeeder extends Seeder
{
    /**
     * Seeder mínimo para el ambiente de la empresa: crea únicamente la cuenta
     * de administrador leyendo credenciales desde `.env` (sin datos ficticios).
     *
     * Si `APP_DEMO_MODE=true`, delega a {@see DemoSeeder} para evitar mezclar
     * la cuenta de la empresa con el ambiente de demostración.
     */
    public function run(): void
    {
        if (config('app.demo_mode')) {
            $this->call(DemoSeeder::class);

            return;
        }

        $email = trim((string) config('app.admin.email', ''));
        $password = (string) config('app.admin.password', '');
        $nombre = trim((string) config('app.admin.nombre', 'Administración EcoRuta'));

        if ($email === '' || $password === '') {
            throw new RuntimeException(
                'Faltan variables de entorno requeridas para sembrar el ambiente de la empresa: '
                .'define ADMIN_EMAIL y ADMIN_PASSWORD en .env.'
            );
        }

        $user = User::updateOrCreate(
            ['email' => $email],
            [
                'name' => $nombre !== '' ? $nombre : 'Administración EcoRuta',
                'password' => $password,
                'role' => 'administrador',
                'is_active' => true,
            ],
        );

        Administrador::updateOrCreate(
            ['user_id' => $user->id],
            [
                'nombre_completo' => $nombre !== '' ? $nombre : 'Administración EcoRuta',
                'cargo' => 'Administración General',
                'telefono' => null,
            ],
        );
    }
}
