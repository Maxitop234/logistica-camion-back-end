<?php

use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

test('is_active defaults to true at the database level', function () {
    DB::table('users')->insert([
        'name' => 'Sin estado',
        'email' => 'sin-estado@example.com',
        'password' => 'secret',
        'role' => 'chofer',
    ]);

    $user = User::where('email', 'sin-estado@example.com')->first();

    expect($user->is_active)->toBeTrue();
});

test('role defaults to chofer at the database level', function () {
    DB::table('users')->insert([
        'name' => 'Sin rol',
        'email' => 'sin-rol@example.com',
        'password' => 'secret',
    ]);

    $user = User::where('email', 'sin-rol@example.com')->first();

    expect($user->role)->toBe('chofer');
});

test('accepts each valid role', function (string $role) {
    $user = User::factory()->create(['role' => $role]);

    expect($user->refresh()->role)->toBe($role);
})->with(['administrador', 'chofer', 'sysadmin']);

test('rejects a role outside the allowed values', function () {
    User::factory()->create(['role' => 'invitado']);
})->throws(QueryException::class);
