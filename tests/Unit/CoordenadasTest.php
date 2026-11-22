<?php

use App\Services\Puntos\ExtractorDeCoordenadas;

test('convierte grados, minutos y segundos de Google Maps a decimal con 7 decimales', function (string $texto) {
    $coordenadas = (new ExtractorDeCoordenadas)->extraer($texto);

    expect($coordenadas)->not->toBeNull()
        ->and($coordenadas['latitud'])->toEqualWithDelta(-38.7623611, 1e-9)
        ->and($coordenadas['longitud'])->toEqualWithDelta(-72.7618889, 1e-9);
})->with([
    'formato de Google Maps' => '38°45\'44.5"S 72°45\'42.8"W',
    'oeste como O' => '38°45\'44.5"S 72°45\'42.8"O',
    'símbolos tipográficos' => '38º 45′ 44.5″ S, 72º 45′ 42.8″ W',
    'decimal' => '-38.7623611, -72.7618889',
    'decimal con punto y coma' => '-38.7623611;-72.7618889',
]);

test('lee las coordenadas de dos columnas en decimal o en grados', function () {
    $extractor = new ExtractorDeCoordenadas;

    expect($extractor->extraerDeColumnas('-38.709666', '-72.558745'))
        ->toBe(['latitud' => -38.709666, 'longitud' => -72.558745])
        ->and($extractor->extraerDeColumnas('38°45\'44.5"S', '72°45\'42.8"W'))
        ->toBe($extractor->extraer('38°45\'44.5"S 72°45\'42.8"W'))
        ->and($extractor->extraerDeColumnas('-38.7', ''))->toBeNull();
});

test('rechaza textos que no son coordenadas completas y válidas', function (string $texto) {
    expect((new ExtractorDeCoordenadas)->extraer($texto))->toBeNull();
})->with([
    'vacío' => '',
    'nombre de un punto' => 'Condominio Parque Oriente',
    'solo la latitud' => '38°45\'44.5"S',
    'dos latitudes' => '38°45\'44.5"S 38°45\'44.5"N',
    'minutos fuera de rango' => '38°61\'44.5"S 72°45\'42.8"W',
    'latitud imposible' => '-98.1, -72.6',
]);
