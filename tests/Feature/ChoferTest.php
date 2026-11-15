<?php

use App\Models\Chofer;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

test('nombre y telefono se guardan cifrados y se leen descifrados', function () {
    $chofer = Chofer::factory()->create([
        'nombre_cifrado' => 'Juan Pérez',
        'telefono_cifrado' => '+56912345678',
    ]);

    $raw = DB::table('choferes')->where('id', $chofer->id)->first();

    expect($raw->nombre_cifrado)->not->toContain('Juan')
        ->and($raw->telefono_cifrado)->not->toContain('56912345678')
        ->and($chofer->fresh()->nombre_cifrado)->toBe('Juan Pérez')
        ->and($chofer->fresh()->telefono_cifrado)->toBe('+56912345678');
});

test('el rut con y sin formato produce el mismo hash', function () {
    expect(Chofer::hashRut('12.345.678-9'))->toBe(Chofer::hashRut('123456789'));
});

test('el rut se normaliza con K mayuscula sin puntos guion ni espacios', function () {
    expect(Chofer::normalizarRut(' 12.345.678-k '))->toBe('12345678K')
        ->and(Chofer::hashRut('12.345.678-k'))->toBe(Chofer::hashRut('12345678K'));
});

test('el hash del rut es hmac con la clave de la aplicacion', function () {
    expect(Chofer::hashRut('12.345.678-9'))
        ->toBe(hash_hmac('sha256', '123456789', config('app.key')))
        ->not->toBe(hash('sha256', '123456789'));
});

test('licencia_clase es A4 y disponible es true por defecto', function () {
    $user = User::factory()->create();

    DB::table('choferes')->insert([
        'user_id' => $user->id,
        'nombre_cifrado' => 'x',
        'rut_hash' => Chofer::hashRut('1-9'),
    ]);

    $chofer = Chofer::where('user_id', $user->id)->first();

    expect($chofer->licencia_clase)->toBe('A4')
        ->and($chofer->disponible)->toBeTrue()
        ->and($chofer->telefono_cifrado)->toBeNull();
});

test('rut_hash debe ser unico', function () {
    $hash = Chofer::hashRut('11.111.111-1');

    Chofer::factory()->create(['rut_hash' => $hash]);
    Chofer::factory()->create(['rut_hash' => $hash]);
})->throws(QueryException::class);

test('un usuario solo puede tener un perfil de chofer', function () {
    $user = User::factory()->create();

    Chofer::factory()->create(['user_id' => $user->id]);
    Chofer::factory()->create(['user_id' => $user->id]);
})->throws(QueryException::class);

test('la relacion con user funciona en ambos sentidos', function () {
    $chofer = Chofer::factory()->create();

    expect($chofer->user->chofer->is($chofer))->toBeTrue()
        ->and($chofer->user->role)->toBe('chofer');
});

test('al borrar el usuario se borra el chofer', function () {
    $chofer = Chofer::factory()->create();

    $chofer->user->delete();

    expect(Chofer::find($chofer->id))->toBeNull();
});
