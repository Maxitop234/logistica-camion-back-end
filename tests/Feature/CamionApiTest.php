<?php

use App\Models\AuditLog;
use App\Models\Camion;
use App\Models\Chofer;
use App\Models\User;

beforeEach(function () {
    $this->admin = User::factory()->create(['role' => 'administrador']);
});

function datosCamion(array $extra = []): array
{
    return [
        'patente' => 'ab1234', 'marca' => 'Hino', 'modelo' => '500', 'anio' => 2022, 'capacidad_kg' => 8000.5,
        ...$extra,
    ];
}

test('solo el administrador accede al crud de camiones', function () {
    $this->getJson('/api/v1/camiones')->assertUnauthorized();

    $this->actingAs(User::factory()->create(['role' => 'chofer']), 'sanctum')
        ->getJson('/api/v1/camiones')->assertForbidden();
});

test('crea un camion con patente en mayusculas y estado disponible', function () {
    $this->actingAs($this->admin, 'sanctum')
        ->postJson('/api/v1/camiones', datosCamion())
        ->assertCreated()
        ->assertJsonPath('data.patente', 'AB1234')
        ->assertJsonPath('data.estado_operativo', 'disponible');
});

test('valida patente duplicada, anio y capacidad', function () {
    Camion::factory()->create(['patente' => 'AB1234']);

    $this->actingAs($this->admin, 'sanctum')
        ->postJson('/api/v1/camiones', datosCamion(['anio' => 1900, 'capacidad_kg' => 0]))
        ->assertJsonValidationErrors(['patente', 'anio', 'capacidad_kg']);
});

test('lista y muestra camiones con su chofer actual descifrado y ultima posicion', function () {
    $chofer = Chofer::factory()->create(['nombre_cifrado' => 'Juan Pérez']);
    $camion = Camion::factory()->create([
        'chofer_actual_id' => $chofer->id, 'ultima_latitud' => -38.7359, 'ultima_longitud' => -72.5904,
    ]);

    $this->actingAs($this->admin, 'sanctum')
        ->getJson('/api/v1/camiones')
        ->assertOk()
        ->assertJsonPath('data.0.chofer_actual.nombre', 'Juan Pérez');

    $this->actingAs($this->admin, 'sanctum')
        ->getJson("/api/v1/camiones/{$camion->id}")
        ->assertJsonPath('data.ultima_posicion.latitud', '-38.7359000');
});

test('actualiza datos, estado y asigna chofer', function () {
    $camion = Camion::factory()->create();
    $chofer = Chofer::factory()->create();

    $this->actingAs($this->admin, 'sanctum')
        ->putJson("/api/v1/camiones/{$camion->id}", ['estado_operativo' => 'mantenimiento', 'chofer_actual_id' => $chofer->id])
        ->assertOk()
        ->assertJsonPath('data.estado_operativo', 'mantenimiento')
        ->assertJsonPath('data.chofer_actual.id', $chofer->id);
});

test('un chofer no puede estar asignado a dos camiones y el estado debe ser valido', function () {
    $chofer = Chofer::factory()->create();
    Camion::factory()->create(['chofer_actual_id' => $chofer->id]);
    $otro = Camion::factory()->create();

    $this->actingAs($this->admin, 'sanctum')
        ->putJson("/api/v1/camiones/{$otro->id}", ['chofer_actual_id' => $chofer->id, 'estado_operativo' => 'volando'])
        ->assertJsonValidationErrors(['chofer_actual_id', 'estado_operativo']);
});

test('desactivar un camion lo deja inactivo y sin chofer', function () {
    $camion = Camion::factory()->create(['chofer_actual_id' => Chofer::factory()->create()->id]);

    $this->actingAs($this->admin, 'sanctum')->deleteJson("/api/v1/camiones/{$camion->id}")->assertNoContent();

    expect($camion->fresh()->estado_operativo)->toBe('inactivo')
        ->and($camion->fresh()->chofer_actual_id)->toBeNull();
});

test('no se puede desactivar un camion en ruta', function () {
    $camion = Camion::factory()->enRuta()->create();

    $this->actingAs($this->admin, 'sanctum')->deleteJson("/api/v1/camiones/{$camion->id}")->assertStatus(409);

    expect($camion->fresh()->estado_operativo)->toBe('en_ruta');
});

test('desactivar un camion guarda motivo y auditoria y reactivar los limpia y audita', function () {
    $camion = Camion::factory()->create(['chofer_actual_id' => Chofer::factory()->create()->id]);

    // Motivo muy corto rechazado con 422.
    $this->actingAs($this->admin, 'sanctum')
        ->deleteJson("/api/v1/camiones/{$camion->id}", ['motivo' => 'abc'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['motivo']);

    // Baja con motivo válido.
    $this->actingAs($this->admin, 'sanctum')
        ->deleteJson("/api/v1/camiones/{$camion->id}", ['motivo' => 'Falla mecánica grave / siniestro'])
        ->assertNoContent();

    $this->actingAs($this->admin, 'sanctum')
        ->getJson("/api/v1/camiones/{$camion->id}")
        ->assertOk()
        ->assertJsonPath('data.estado_operativo', 'inactivo')
        ->assertJsonPath('data.motivo_desactivacion', 'Falla mecánica grave / siniestro');

    expect($camion->fresh()->desactivado_en)->not->toBeNull()
        ->and($camion->fresh()->desactivado_por_user_id)->toBe($this->admin->id)
        ->and(AuditLog::where('accion', 'camion_desactivado')->where('user_id', $this->admin->id)->exists())->toBeTrue();

    // Reactivación limpia motivo y audita.
    $this->actingAs($this->admin, 'sanctum')
        ->putJson("/api/v1/camiones/{$camion->id}", ['estado_operativo' => 'disponible'])
        ->assertOk()
        ->assertJsonPath('data.estado_operativo', 'disponible')
        ->assertJsonPath('data.motivo_desactivacion', null)
        ->assertJsonPath('data.desactivado_en', null);

    expect(AuditLog::where('accion', 'camion_reactivado')->where('user_id', $this->admin->id)->exists())->toBeTrue();
});
