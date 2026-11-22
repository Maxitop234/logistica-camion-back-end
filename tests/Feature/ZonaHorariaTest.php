<?php

use App\Models\Administrador;
use App\Models\User;
use App\Support\DiaDeNegocio;
use Illuminate\Support\Carbon;

afterEach(function (): void {
    Carbon::setTestNow();
});

/** 22:30 del 22-11-2026 en Chile (UTC-3 en verano) = 01:30 UTC del día 23. */
function nocheEnChile(): void
{
    Carbon::setTestNow(Carbon::parse('2026-11-23 01:30:00', 'UTC'));
}

test('entre las 21:00 y las 23:59 de Chile el día de negocio sigue siendo el de Chile', function () {
    nocheEnChile();

    expect(now()->format('Y-m-d'))->toBe('2026-11-23')
        ->and(DiaDeNegocio::hoy()->format('Y-m-d'))->toBe('2026-11-22')
        ->and(DiaDeNegocio::fecha(now()))->toBe('2026-11-22')
        ->and(DiaDeNegocio::ahora()->format('H:i'))->toBe('22:30');
});

test('en invierno (UTC-4) también y 23:59 de Chile no salta de día', function () {
    // 23:59 del 15-07-2026 en Chile = 03:59 UTC del día 16.
    Carbon::setTestNow(Carbon::parse('2026-07-16 03:59:00', 'UTC'));

    expect(DiaDeNegocio::hoy()->format('Y-m-d'))->toBe('2026-07-15');

    Carbon::setTestNow(Carbon::parse('2026-07-16 04:00:00', 'UTC'));
    expect(DiaDeNegocio::hoy()->format('Y-m-d'))->toBe('2026-07-16');
});

test('la zona es configurable por env', function () {
    nocheEnChile();
    config(['ecoruta.zona_horaria' => 'UTC']);

    expect(DiaDeNegocio::hoy()->format('Y-m-d'))->toBe('2026-11-23');
});

test('inicio y fin del día son instantes de Chile, no de UTC', function () {
    expect(DiaDeNegocio::inicioDelDia('2026-11-22')->utc()->format('Y-m-d H:i'))->toBe('2026-11-22 03:00')
        ->and(DiaDeNegocio::finDelDia('2026-11-22')->utc()->format('Y-m-d H:i'))->toBe('2026-11-23 02:59');
});

test('el KPI sin fechas usa el día de Chile como fin del período', function () {
    nocheEnChile();
    $admin = User::factory()->create(['role' => 'administrador']);
    Administrador::factory()->create(['user_id' => $admin->id]);

    $this->actingAs($admin, 'sanctum')
        ->getJson('/api/v1/kpis/dashboard')
        ->assertOk()
        ->assertJsonPath('data.periodo.fecha_fin', '2026-11-22')
        ->assertJsonPath('data.periodo.fecha_inicio', '2026-10-24');
});
