<?php

use App\Models\Camion;
use App\Models\Chofer;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

test('valores por defecto a nivel de base de datos', function () {
    DB::table('camiones')->insert([
        'patente' => 'AB1234',
        'marca' => 'Hino',
        'modelo' => '500',
        'anio' => 2022,
        'capacidad_kg' => 8000,
    ]);

    $camion = Camion::where('patente', 'AB1234')->first();

    expect($camion->estado_operativo)->toBe('disponible')
        ->and($camion->velocidad_actual_kmh)->toBe('0.00')
        ->and($camion->chofer_actual_id)->toBeNull()
        ->and($camion->ultima_latitud)->toBeNull();
});

test('aplica los casts numericos y de fecha', function () {
    $camion = Camion::factory()->create([
        'capacidad_kg' => 8000.5,
        'ultima_latitud' => -38.7359,
        'ultima_longitud' => -72.5904,
        'ultima_actualizacion_gps' => '2026-11-14 10:00:00',
    ])->fresh();

    expect($camion->capacidad_kg)->toBe('8000.50')
        ->and($camion->ultima_latitud)->toBe('-38.7359000')
        ->and($camion->ultima_longitud)->toBe('-72.5904000')
        ->and($camion->anio)->toBeInt()
        ->and($camion->ultima_actualizacion_gps->toDateTimeString())->toBe('2026-11-14 10:00:00');
});

test('la patente debe ser unica', function () {
    Camion::factory()->create(['patente' => 'ZZ9999']);
    Camion::factory()->create(['patente' => 'ZZ9999']);
})->throws(QueryException::class);

test('acepta cada estado operativo valido', function (string $estado) {
    $camion = Camion::factory()->create(['estado_operativo' => $estado]);

    expect($camion->fresh()->estado_operativo)->toBe($estado);
})->with(['disponible', 'en_ruta', 'mantenimiento', 'inactivo']);

test('rechaza un estado operativo invalido', function () {
    Camion::factory()->create(['estado_operativo' => 'volando']);
})->throws(QueryException::class);

test('la patente no admite mas de 10 caracteres', function () {
    Camion::factory()->create(['patente' => 'ABCDEFGHIJK']);
})->throws(QueryException::class);

test('se relaciona con el chofer actual', function () {
    $chofer = Chofer::factory()->create();
    $camion = Camion::factory()->create(['chofer_actual_id' => $chofer->id]);

    expect($camion->choferActual->is($chofer))->toBeTrue()
        ->and($chofer->camionActual->is($camion))->toBeTrue();
});

test('al borrar el chofer el camion queda sin chofer', function () {
    $chofer = Chofer::factory()->create();
    $camion = Camion::factory()->create(['chofer_actual_id' => $chofer->id]);

    $chofer->delete();

    expect($camion->fresh())->not->toBeNull()
        ->and($camion->fresh()->chofer_actual_id)->toBeNull();
});
