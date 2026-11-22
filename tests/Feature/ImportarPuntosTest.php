<?php

use App\Models\Punto;
use App\Models\Sector;
use App\Services\Puntos\ImportadorDePuntos;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Http::preventStrayRequests();
    config(['services.nominatim.base_url' => 'https://nominatim.test']);

    Sector::factory()->create(['nombre' => 'Sector Prueba Norte', 'puntos_esperados' => 3]);
    Sector::factory()->create(['nombre' => 'Sector Prueba Sur', 'puntos_esperados' => 2]);
});

/**
 * @return array<string, int|string|null>
 */
function filaDeConciliacion(array $filas, string $sector): array
{
    return collect($filas)->firstWhere('sector', $sector);
}

test('importa coordenadas decimales y en grados, y omite con su linea las filas que no puede importar', function () {
    $this->artisan('puntos:importar', ['archivo' => fixtureDeImportacion('puntos.csv')])
        ->expectsOutputToContain('Línea 4: La fila no trae coordenadas: agrégalas o vuelve a importar con --geocodificar.')
        ->expectsOutputToContain('Línea 5: La ubicación (-33.4500000, -70.6600000) está fuera de la zona de operación')
        ->expectsOutputToContain('Línea 6: El sector «Sector Inexistente» no existe: impórtalo primero con `sectores:importar`.')
        ->expectsOutputToContain('Puntos: 2 creados, 0 actualizados, 0 sin cambios, 3 omitidos.')
        ->assertSuccessful();

    $decimal = Punto::where('nombre', 'Punto de prueba decimal')->firstOrFail();
    $gms = Punto::where('nombre', 'Punto de prueba GMS')->firstOrFail();

    expect(Punto::count())->toBe(2)
        ->and($decimal->latitud)->toBe('-38.7400000')
        ->and($decimal->longitud)->toBe('-72.6000000')
        ->and($decimal->fuente_ubicacion)->toBe(Punto::FUENTE_COORDENADAS)
        ->and($decimal->requiere_revision)->toBeFalse()
        ->and($decimal->tipo_material)->toBe('mixto')
        ->and($decimal->nombre_automatico)->toBeFalse()
        ->and($decimal->estado_llenado)->toBeNull()
        ->and($decimal->sector?->nombre)->toBe('Sector Prueba Norte')
        ->and($decimal->codigo_externo)->toHaveLength(64)
        ->and($gms->latitud)->toBe('-38.7623611')
        ->and($gms->longitud)->toBe('-72.7618889')
        ->and($gms->tipo_punto)->toBe('condominio')
        ->and($gms->nota)->toBe('Junto a un paradero de prueba');
});

test('con --geocodificar busca la direccion en Nominatim dentro de la zona y deja el punto para revision', function () {
    Http::fake([
        'nominatim.test/search*' => Http::response([['lat' => '-38.7350000', 'lon' => '-72.6100000']]),
    ]);

    $this->artisan('puntos:importar', ['archivo' => fixtureDeImportacion('puntos.csv'), '--geocodificar' => true])
        ->expectsOutputToContain('Puntos: 3 creados, 0 actualizados, 0 sin cambios, 2 omitidos.')
        ->assertSuccessful();

    $geocodificado = Punto::where('nombre', 'Punto de prueba solo dirección')->firstOrFail();
    expect($geocodificado->latitud)->toBe('-38.7350000')
        ->and($geocodificado->fuente_ubicacion)->toBe(Punto::FUENTE_GEOCODIFICADA)
        ->and($geocodificado->requiere_revision)->toBeTrue();

    Http::assertSentCount(1);
    Http::assertSent(fn (Request $request): bool => $request['q'] === 'Calle Ficticia 300 Temuco, Araucanía, Chile'
        && $request['bounded'] === 1
        && $request['viewbox'] === '-72.85,-38.62,-72.45,-38.85'
        && $request['countrycodes'] === 'cl'
        && $request->hasHeader('User-Agent'));
});

test('si Nominatim no encuentra la direccion la fila se omite con su linea', function () {
    Http::fake(['nominatim.test/search*' => Http::response([])]);

    $this->artisan('puntos:importar', ['archivo' => fixtureDeImportacion('puntos.csv'), '--geocodificar' => true])
        ->expectsOutputToContain('Línea 4: No se encontró la dirección «Calle Ficticia 300 Temuco» en OpenStreetMap: agrega sus coordenadas.')
        ->assertSuccessful();

    expect(Punto::count())->toBe(2);
});

test('reimportar el mismo archivo no duplica los puntos', function () {
    $this->artisan('puntos:importar', ['archivo' => fixtureDeImportacion('puntos.csv')])->assertSuccessful();
    $codigos = Punto::orderBy('id')->pluck('codigo_externo')->all();

    $this->artisan('puntos:importar', ['archivo' => fixtureDeImportacion('puntos.csv')])
        ->expectsOutputToContain('Puntos: 0 creados, 0 actualizados, 2 sin cambios, 3 omitidos.')
        ->assertSuccessful();

    expect(Punto::count())->toBe(2)
        ->and(Punto::orderBy('id')->pluck('codigo_externo')->all())->toBe($codigos);
});

test('reimportar actualiza los datos del archivo pero no el estado ni una ubicacion corregida a mano', function () {
    $this->artisan('puntos:importar', ['archivo' => fixtureDeImportacion('puntos.csv')])->assertSuccessful();

    $decimal = Punto::where('nombre', 'Punto de prueba decimal')->firstOrFail();
    $decimal->update(['estado_llenado' => Punto::ESTADO_ALTO, 'porcentaje_llenado' => 80]);
    $gms = Punto::where('nombre', 'Punto de prueba GMS')->firstOrFail();
    $gms->update(['latitud' => '-38.7620000', 'longitud' => '-72.7610000', 'fuente_ubicacion' => Punto::FUENTE_MANUAL]);

    $archivo = archivoCsvTemporal(<<<'CSV'
        sector,nombre,direccion,coordenadas,tipo_punto,tipo_material,nota
        Sector Prueba Norte,Punto de prueba decimal,Calle Ficticia 100 Temuco,"-38.7410000, -72.6010000",calle,vidrio,
        Sector Prueba Norte,Punto de prueba GMS,Calle Ficticia 200 Temuco,"-38.7000000, -72.7000000",condominio,,Junto a un paradero de prueba
        CSV);

    $this->artisan('puntos:importar', ['archivo' => $archivo])
        ->expectsOutputToContain('Línea 3: El punto «Punto de prueba GMS» fue movido a mano en el panel: se conserva su ubicación.')
        ->expectsOutputToContain('Puntos: 0 creados, 1 actualizados, 1 sin cambios, 0 omitidos.')
        ->assertSuccessful();

    expect($decimal->refresh()->latitud)->toBe('-38.7410000')
        ->and($decimal->tipo_material)->toBe('vidrio')
        ->and($decimal->estado_llenado)->toBe(Punto::ESTADO_ALTO)
        ->and($decimal->porcentaje_llenado)->toBe(80)
        ->and($gms->refresh()->latitud)->toBe('-38.7620000')
        ->and($gms->fuente_ubicacion)->toBe(Punto::FUENTE_MANUAL)
        ->and(Punto::count())->toBe(2);
});

test('concilia por sector lo importado con los puntos esperados', function () {
    $reporte = app(ImportadorDePuntos::class)->importar(fixtureDeImportacion('puntos.csv'));

    expect(filaDeConciliacion($reporte->filas, 'Sector Prueba Norte'))->toBe([
        'sector' => 'Sector Prueba Norte',
        'en_archivo' => 2,
        'creados' => 2,
        'actualizados' => 0,
        'sin_cambios' => 0,
        'omitidos' => 0,
        'activos_en_base' => 2,
        'puntos_esperados' => 3,
        'diferencia' => -1,
        'por_revisar' => 0,
    ])->and(filaDeConciliacion($reporte->filas, 'Sector Prueba Sur'))->toMatchArray([
        'en_archivo' => 2,
        'creados' => 0,
        'omitidos' => 2,
        'activos_en_base' => 0,
        'diferencia' => -2,
    ]);
});

test('importa la planilla del equipo: pines, nombres repetidos, duplicados y lugares en dos sectores', function () {
    $this->artisan('sectores:importar', ['archivo' => fixtureDeImportacion('planilla.csv')])->assertSuccessful();

    $this->artisan('puntos:importar', ['archivo' => fixtureDeImportacion('planilla.csv')])
        ->expectsOutputToContain('Línea 5: Repite el punto de la línea 3 (mismo sector, nombre, dirección y ubicación): se omite.')
        ->expectsOutputToContain('Línea 2: El mismo lugar está en los sectores «Sector Prueba Norte, Villa Uno» (línea 2) y «Sector Prueba Sur» (línea 7): queda un punto en cada sector.')
        ->expectsOutputToContain('Puntos: 5 creados, 0 actualizados, 0 sin cambios, 1 omitidos.')
        ->assertSuccessful();

    $norte = Sector::where('nombre', 'Sector Prueba Norte, Villa Uno')->firstOrFail();
    $labranza = Punto::where('sector_id', $norte->id)->where('nombre', 'Labranza')->get();
    $pin = Punto::where('latitud', '-38.7623611')->firstOrFail();
    $condominioSur = Punto::where('nombre', 'Condominio Prueba Uno')->where('sector_id', '!=', $norte->id)->firstOrFail();

    expect($labranza)->toHaveCount(2)
        ->and($labranza->pluck('codigo_externo')->unique())->toHaveCount(2)
        ->and($pin->nombre)->toBe('Calle Ficticia 0460')
        ->and($pin->tipo_punto)->toBe('calle')
        ->and(Punto::where('nombre', 'Condominio Prueba Uno')->pluck('tipo_punto')->unique()->all())->toBe(['condominio'])
        ->and($condominioSur->nota)->toBe('También en sector 1');

    $this->artisan('puntos:importar', ['archivo' => fixtureDeImportacion('planilla.csv')])
        ->expectsOutputToContain('Puntos: 0 creados, 0 actualizados, 5 sin cambios, 1 omitidos.')
        ->assertSuccessful();
    expect(Punto::count())->toBe(5);
});

test('con --dry-run muestra la conciliacion sin guardar', function () {
    $this->artisan('puntos:importar', ['archivo' => fixtureDeImportacion('puntos.csv'), '--dry-run' => true])
        ->expectsOutputToContain('Simulación (--dry-run): no se guardó ningún cambio.')
        ->expectsOutputToContain('Puntos: 2 creados')
        ->assertSuccessful();

    expect(Punto::count())->toBe(0);
});

test('falla con un mensaje claro si al archivo le faltan columnas', function () {
    $this->artisan('puntos:importar', ['archivo' => archivoCsvTemporal("sector,nombre\nSector Prueba Norte,Punto\n")])
        ->expectsOutputToContain('El archivo debe tener las columnas `sector` y `nombre`')
        ->assertFailed();
});
