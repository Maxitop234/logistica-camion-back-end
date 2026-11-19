<?php

use Illuminate\Support\Facades\DB;

test('la extension postgis esta habilitada', function () {
    $extension = DB::selectOne("select extname from pg_extension where extname = 'postgis'");

    expect($extension)->not->toBeNull();
})->skip(fn (): bool => DB::getDriverName() !== 'pgsql', 'Requiere PostgreSQL');

test('postgis calcula distancias geograficas en metros', function () {
    $resultado = DB::selectOne(
        'select ST_Distance(ST_MakePoint(-72.5904, -38.7359)::geography, ST_MakePoint(-72.5865, -38.7655)::geography) as metros'
    );

    expect((float) $resultado->metros)->toBeGreaterThan(3000.0)->toBeLessThan(3500.0);
})->skip(fn (): bool => DB::getDriverName() !== 'pgsql', 'Requiere PostgreSQL');
