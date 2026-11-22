<?php

use Illuminate\Support\Facades\Route;

test('no existe registro público ni rutas web de cuenta', function (string $metodo, string $ruta) {
    $this->call($metodo, $ruta)->assertNotFound();
})->with([
    ['GET', '/register'],
    ['POST', '/register'],
    ['GET', '/forgot-password'],
    ['POST', '/forgot-password'],
    ['POST', '/reset-password'],
    ['GET', '/login'],
    ['POST', '/login'],
    ['GET', '/email/verify'],
    ['POST', '/two-factor-challenge'],
    ['POST', '/user/two-factor-authentication'],
    ['GET', '/passkeys/login/options'],
    ['POST', '/user/passkeys'],
]);

test('las únicas rutas fuera de /api son las del sistema', function () {
    $permitidas = ['/', 'up', 'storage/{path}', 'sanctum/csrf-cookie', '_boost/browser-logs'];

    $inesperadas = collect(Route::getRoutes()->getRoutes())
        ->map(fn ($ruta) => $ruta->uri())
        ->reject(fn (string $uri) => str_starts_with($uri, 'api/') || in_array($uri, $permitidas, true))
        ->values()->all();

    expect($inesperadas)->toBe([]);
});
