<?php

use App\Models\Administrador;
use App\Models\Camion;
use App\Models\Chofer;
use App\Models\Punto;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DemoSeeder;

test('en modo empresa DatabaseSeeder falla con mensaje claro si faltan variables en env', function () {
    config([
        'app.demo_mode' => false,
        'app.admin.email' => '',
        'app.admin.password' => '',
    ]);

    expect(fn () => $this->seed(DatabaseSeeder::class))
        ->toThrow(RuntimeException::class, 'ADMIN_EMAIL');
});

test('en modo empresa DatabaseSeeder crea unicamente la cuenta administradora desde env y es repetible', function () {
    config([
        'app.demo_mode' => false,
        'app.admin.email' => 'admin.empresa@terranova.test',
        'app.admin.password' => 'ClaveEmpresaSegura#2026',
        'app.admin.nombre' => 'Administración EcoRuta',
    ]);

    $this->seed(DatabaseSeeder::class);
    $this->seed(DatabaseSeeder::class);

    expect(User::where('role', 'administrador')->count())->toBe(1)
        ->and(Administrador::first()?->nombre_completo)->toBe('Administración EcoRuta')
        ->and(Chofer::count())->toBe(0)
        ->and(Camion::count())->toBe(0)
        ->and(Punto::count())->toBe(0);

    $this->postJson('/api/v1/auth/login', [
        'email' => 'admin.empresa@terranova.test',
        'password' => 'ClaveEmpresaSegura#2026',
        'device_name' => 'empresa-seed-test',
    ])->assertOk()->assertJsonPath('role', 'administrador');
});

test('DemoSeeder se niega a ejecutarse cuando APP_DEMO_MODE es false', function () {
    config([
        'app.demo_mode' => false,
        'app.demo.admin_email' => 'admin@demo.ecoruta.cl',
        'app.demo.admin_password' => 'DemoAdmin#2026',
        'app.demo.chofer_password' => 'DemoChofer#2026',
    ]);

    expect(fn () => $this->seed(DemoSeeder::class))
        ->toThrow(RuntimeException::class, 'APP_DEMO_MODE=false');

    expect(User::count())->toBe(0);
});
