<?php

use App\Models\Sector;
use App\Services\Puntos\ImportadorDeSectores;
use Database\Seeders\SectorSeeder;

test('importa los sectores con sus dias en numeros, nombres o abreviaturas', function () {
    $this->artisan('sectores:importar', ['archivo' => fixtureDeImportacion('sectores.csv')])
        ->expectsOutputToContain('Sectores: 3 creados, 0 actualizados, 0 sin cambios, 0 filas con error.')
        ->assertSuccessful();

    $norte = Sector::where('nombre', 'Sector Prueba Norte')->firstOrFail();
    $sur = Sector::where('nombre', 'Sector Prueba Sur')->firstOrFail();
    $oriente = Sector::where('nombre', 'Sector Prueba Oriente')->firstOrFail();

    expect($norte->diasNormalizados())->toBe([2, 4])
        ->and($norte->periodicidad_semanal)->toBe(2)
        ->and($norte->puntos_esperados)->toBe(3)
        ->and($sur->diasNormalizados())->toBe([1, 3, 5])
        ->and($oriente->diasNormalizados())->toBe([2, 4])
        ->and($oriente->puntos_esperados)->toBeNull()
        ->and($oriente->activo)->toBeTrue();
});

test('con dias y frecuencia inconsistentes avisa y mandan los dias', function () {
    $this->artisan('sectores:importar', ['archivo' => fixtureDeImportacion('sectores.csv')])
        ->expectsOutputToContain('Línea 4: El sector «Sector Prueba Oriente» declara 1 vez(ces) por semana pero marca 2 día(s); se usan los días (martes, jueves).')
        ->assertSuccessful();

    $oriente = Sector::where('nombre', 'Sector Prueba Oriente')->firstOrFail();
    expect($oriente->diasNormalizados())->toBe([2, 4])
        ->and($oriente->periodicidad_semanal)->toBe(1)
        ->and($oriente->diasCoincidenConPeriodicidad())->toBeFalse();
});

test('reimportar el mismo archivo no duplica y no cambia nada', function () {
    $this->artisan('sectores:importar', ['archivo' => fixtureDeImportacion('sectores.csv')])->assertSuccessful();

    $this->artisan('sectores:importar', ['archivo' => fixtureDeImportacion('sectores.csv')])
        ->expectsOutputToContain('Sectores: 0 creados, 0 actualizados, 3 sin cambios, 0 filas con error.')
        ->assertSuccessful();

    expect(Sector::count())->toBe(3);
});

test('reconoce un sector existente aunque la planilla cambie la puntuacion y conserva su nombre', function () {
    $this->seed(SectorSeeder::class);
    $archivo = archivoCsvTemporal(<<<'CSV'
        sector,dias,frecuencia_semanal
        "Villa San Andres , Fdo Las Mariposas","martes, jueves",2
        "S.Martin,Av.Alemania . Thiers , P.Dreves","jueves, viernes",1
        "Amanecer y Amanecer Alto ( Santa Elene del Maipo Bajo, Vista Verde , juan Pablo II , Laquelarren )","lunes, viernes",3
        "Langdon,Turingia,V.Los Ríos","lunes, miércoles, viernes",3
        CSV);

    $reporte = app(ImportadorDeSectores::class)->importar($archivo);

    expect($reporte->creados)->toBe(0)
        ->and($reporte->sinCambios)->toBe(4)
        ->and(Sector::count())->toBe(12)
        ->and(Sector::where('nombre', 'Villa San Andres, Fdo Las Mariposas')->exists())->toBeTrue();
});

test('la planilla de puntos sirve para crear sus sectores una sola vez', function () {
    $this->artisan('sectores:importar', ['archivo' => fixtureDeImportacion('planilla.csv')])
        ->expectsOutputToContain('Sectores: 2 creados, 0 actualizados, 0 sin cambios, 0 filas con error.')
        ->assertSuccessful();

    $sur = Sector::where('nombre', 'Sector Prueba Sur')->firstOrFail();
    expect(Sector::where('nombre', 'Sector Prueba Norte, Villa Uno')->exists())->toBeTrue()
        ->and($sur->descripcion)->toBe('Villa Dos y Villa Tres')
        ->and($sur->diasNormalizados())->toBe([1, 5]);
});

test('una fila con errores se informa con su linea y no detiene la importacion', function () {
    $archivo = archivoCsvTemporal(<<<'CSV'
        nombre,dias_recoleccion,periodicidad_semanal,puntos_esperados
        Sector Prueba Uno,2|4,2,10
        Sector Prueba Dos,martes|feriado,2,
        ,1,1,
        Sector Prueba Tres,5,9,
        Sector Prueba Uno,1|3,2,
        CSV);

    $this->artisan('sectores:importar', ['archivo' => $archivo])
        ->expectsOutputToContain('Línea 3: Los días «martes|feriado» del sector «Sector Prueba Dos» no se reconocen')
        ->expectsOutputToContain('Línea 4: Falta el nombre del sector.')
        ->expectsOutputToContain('Línea 5: La frecuencia semanal «9» del sector «Sector Prueba Tres» debe ser un entero de 1 a 7.')
        ->expectsOutputToContain('Línea 6: El sector «Sector Prueba Uno» trae días o frecuencia distintos a los de la línea 2')
        ->expectsOutputToContain('Sectores: 1 creados, 0 actualizados, 0 sin cambios, 3 filas con error.')
        ->assertSuccessful();

    expect(Sector::pluck('nombre')->all())->toBe(['Sector Prueba Uno'])
        ->and(Sector::firstOrFail()->diasNormalizados())->toBe([2, 4]);
});

test('con --dry-run muestra el resultado sin guardar', function () {
    $this->artisan('sectores:importar', ['archivo' => fixtureDeImportacion('sectores.csv'), '--dry-run' => true])
        ->expectsOutputToContain('Simulación (--dry-run): no se guardó ningún cambio.')
        ->expectsOutputToContain('Sectores: 3 creados')
        ->assertSuccessful();

    expect(Sector::count())->toBe(0);
});

test('falla con un mensaje claro si el archivo no existe o le faltan columnas', function () {
    $this->artisan('sectores:importar', ['archivo' => 'storage/app/private/importaciones/no-existe.csv'])
        ->expectsOutputToContain('No se encontró el archivo')
        ->assertFailed();

    $this->artisan('sectores:importar', ['archivo' => archivoCsvTemporal("nombre,frecuencia\nSector Prueba,2\n")])
        ->expectsOutputToContain('El archivo debe tener las columnas `nombre` (o `sector`) y `dias_recoleccion` (o `dias`).')
        ->assertFailed();
});

test('interpreta los dias y normaliza los nombres de sector', function () {
    expect(Sector::diasDesdeTexto('2|4'))->toBe([2, 4])
        ->and(Sector::diasDesdeTexto('martes, jueves'))->toBe([2, 4])
        ->and(Sector::diasDesdeTexto('mar|jue'))->toBe([2, 4])
        ->and(Sector::diasDesdeTexto('lunes, miércoles y viernes'))->toBe([1, 3, 5])
        ->and(Sector::diasDesdeTexto('Miercoles; Lunes'))->toBe([1, 3])
        ->and(Sector::diasDesdeTexto(''))->toBeNull()
        ->and(Sector::diasDesdeTexto('8'))->toBeNull()
        ->and(Sector::diasDesdeTexto('m'))->toBeNull()
        ->and(Sector::claveDeNombre('Fundó del Carmen más Luis Duran'))->toBe('fundo del carmen mas luis duran')
        ->and(Sector::claveDeNombre('Las Quilas , centro'))->toBe(Sector::claveDeNombre('Las Quilas, centro'));
});
