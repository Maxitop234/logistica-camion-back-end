<?php

use App\Models\Camion;
use App\Models\Chofer;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;

test('el seeder crea administrador, choferes y camiones y es repetible', function () {
    $this->seed(DatabaseSeeder::class);
    $this->seed(DatabaseSeeder::class);

    expect(User::where('role', 'administrador')->count())->toBe(1)
        ->and(Chofer::count())->toBe(4)
        ->and(Camion::count())->toBe(4)
        ->and(Camion::whereNotNull('chofer_actual_id')->count())->toBe(3);
});

test('las credenciales de ejemplo permiten iniciar sesion', function (string $email, string $rol) {
    $this->seed(DatabaseSeeder::class);

    $this->postJson('/api/v1/auth/login', [
        'email' => $email, 'password' => 'password', 'device_name' => 'seed-test',
    ])->assertOk()->assertJsonPath('role', $rol);
})->with([
    ['admin@ecoruta.test', 'administrador'],
    ['chofer1@ecoruta.test', 'chofer'],
]);
