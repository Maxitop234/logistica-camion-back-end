<?php

use App\Models\Administrador;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

test('nombre_completo y telefono se guardan cifrados y se leen descifrados', function () {
    $admin = Administrador::factory()->create([
        'nombre_completo' => 'María Soto',
        'telefono' => '+56987654321',
    ]);

    $raw = DB::table('administradores')->where('id', $admin->id)->first();

    expect($raw->nombre_completo)->not->toContain('María')
        ->and($raw->telefono)->not->toContain('56987654321')
        ->and($admin->fresh()->nombre_completo)->toBe('María Soto')
        ->and($admin->fresh()->telefono)->toBe('+56987654321');
});

test('cargo y telefono son opcionales', function () {
    $admin = Administrador::factory()->create(['cargo' => null, 'telefono' => null]);

    expect($admin->fresh()->cargo)->toBeNull()
        ->and($admin->fresh()->telefono)->toBeNull();
});

test('un usuario solo puede tener un perfil de administrador', function () {
    $user = User::factory()->create(['role' => 'administrador']);

    Administrador::factory()->create(['user_id' => $user->id]);
    Administrador::factory()->create(['user_id' => $user->id]);
})->throws(QueryException::class);

test('la relacion con user funciona en ambos sentidos', function () {
    $admin = Administrador::factory()->create();

    expect($admin->user->administrador->is($admin))->toBeTrue()
        ->and($admin->user->role)->toBe('administrador');
});

test('al borrar el usuario se borra el administrador', function () {
    $admin = Administrador::factory()->create();

    $admin->user->delete();

    expect(Administrador::find($admin->id))->toBeNull();
});
