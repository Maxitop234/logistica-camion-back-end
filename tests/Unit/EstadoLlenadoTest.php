<?php

use App\Enums\EstadoLlenado;
use App\Models\Punto;

test('desdePorcentaje respeta los umbrales 10/40/70/89 en todos los bordes', function (int $porcentaje, EstadoLlenado $esperado) {
    expect(EstadoLlenado::desdePorcentaje($porcentaje))->toBe($esperado);
})->with([
    [0, EstadoLlenado::Vacio],
    [10, EstadoLlenado::Vacio],
    [11, EstadoLlenado::Bajo],
    [40, EstadoLlenado::Bajo],
    [41, EstadoLlenado::Medio],
    [70, EstadoLlenado::Medio],
    [71, EstadoLlenado::Alto],
    [89, EstadoLlenado::Alto],
    [90, EstadoLlenado::Lleno],
    [100, EstadoLlenado::Lleno],
]);

test('desdePorcentaje acota los valores fuera de 0-100', function () {
    expect(EstadoLlenado::desdePorcentaje(-5))->toBe(EstadoLlenado::Vacio)
        ->and(EstadoLlenado::desdePorcentaje(150))->toBe(EstadoLlenado::Lleno);
});

test('solo alto y lleno estan listos para recolectar', function () {
    expect(EstadoLlenado::Alto->estaListo())->toBeTrue()
        ->and(EstadoLlenado::Lleno->estaListo())->toBeTrue()
        ->and(EstadoLlenado::Medio->estaListo())->toBeFalse()
        ->and(EstadoLlenado::Bajo->estaListo())->toBeFalse()
        ->and(EstadoLlenado::Vacio->estaListo())->toBeFalse();
});

test('Punto::clasificarEstado usa el mismo enum', function () {
    expect(Punto::clasificarEstado(71))->toBe(EstadoLlenado::Alto->value)
        ->and(Punto::clasificarEstado(10))->toBe(EstadoLlenado::Vacio->value);
});
