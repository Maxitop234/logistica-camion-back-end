<?php

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

test('guarda detalles como json y los devuelve como arreglo', function () {
    $log = AuditLog::factory()->create([
        'accion' => 'route_generated',
        'detalles' => ['ruta_id' => 7, 'puntos' => [1, 2]],
    ]);

    expect($log->fresh()->detalles)->toEqual(['ruta_id' => 7, 'puntos' => [1, 2]]);
});

test('registra created_at y no tiene updated_at', function () {
    $log = AuditLog::factory()->create();

    expect($log->fresh()->created_at)->not->toBeNull()
        ->and(Schema::hasColumn('audit_logs', 'updated_at'))->toBeFalse();
});

test('ip, user agent, detalles y usuario son opcionales', function () {
    $log = AuditLog::create(['accion' => 'failed_login']);

    $log = $log->fresh();

    expect($log->user_id)->toBeNull()
        ->and($log->ip_address)->toBeNull()
        ->and($log->user_agent)->toBeNull()
        ->and($log->detalles)->toBeNull();
});

test('se relaciona con el usuario', function () {
    $user = User::factory()->create();
    $log = AuditLog::factory()->create(['user_id' => $user->id]);

    expect($log->user->is($user))->toBeTrue()
        ->and($user->auditLogs)->toHaveCount(1);
});

test('al borrar el usuario el registro se conserva con user_id nulo', function () {
    $log = AuditLog::factory()->create();

    $log->user->delete();

    expect(AuditLog::find($log->id))->not->toBeNull()
        ->and($log->fresh()->user_id)->toBeNull();
});

test('existe el indice compuesto user_id y created_at', function () {
    $indices = collect(DB::select("select indexdef from pg_indexes where tablename = 'audit_logs'"))
        ->pluck('indexdef')
        ->implode(' ');

    expect($indices)->toContain('(user_id, created_at)');
});
