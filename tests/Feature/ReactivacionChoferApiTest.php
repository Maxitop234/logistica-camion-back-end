<?php

use App\Models\Administrador;
use App\Models\AuditLog;
use App\Models\Chofer;
use App\Models\User;

beforeEach(function () {
    $this->admin = User::factory()->create(['role' => 'administrador']);
    Administrador::factory()->create([
        'user_id' => $this->admin->id,
        'nombre_completo' => 'María Administradora',
    ]);

    $this->chofer = Chofer::factory()->create(['nombre_cifrado' => 'Carlos Chofer']);
    $this->choferUser = $this->chofer->user;
});

test('flujo de desactivacion, solicitud de reactivacion, rechazo con motivo y reactivacion manual', function () {
    $this->actingAs($this->admin, 'sanctum')
        ->deleteJson("/api/v1/choferes/{$this->chofer->id}", [
            'motivo' => 'Licencia médica',
        ])
        ->assertNoContent();

    expect(AuditLog::where('accion', 'chofer_desactivado')->where('user_id', $this->admin->id)->exists())->toBeTrue();

    // Chofer consulta su estado restringido y valida motivo obligatorio.
    $this->actingAs($this->choferUser->fresh(), 'sanctum')
        ->getJson('/api/v1/chofer/estado-cuenta')
        ->assertOk()
        ->assertJsonPath('data.is_active', false)
        ->assertJsonPath('data.motivo_desactivacion', 'Licencia médica')
        ->assertJsonPath('data.puede_solicitar_reactivacion', true)
        ->assertJsonPath('data.solicitud_reactivacion', null);

    $this->actingAs($this->choferUser->fresh(), 'sanctum')
        ->postJson('/api/v1/chofer/solicitud-reactivacion', ['motivo' => 'abc'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['motivo']);

    // Chofer envía solicitud y no puede duplicar mientras está pendiente.
    $solicitudId = $this->actingAs($this->choferUser->fresh(), 'sanctum')
        ->postJson('/api/v1/chofer/solicitud-reactivacion', [
            'motivo' => 'Ya tengo el alta médica para retomar turnos.',
        ])
        ->assertCreated()
        ->assertJsonPath('data.estado', 'pendiente')
        ->json('data.id');

    expect(AuditLog::where('accion', 'reactivacion_solicitada')->where('user_id', $this->choferUser->id)->exists())->toBeTrue();

    $this->actingAs($this->choferUser->fresh(), 'sanctum')
        ->postJson('/api/v1/chofer/solicitud-reactivacion', [
            'motivo' => 'Segundo intento duplicado.',
        ])
        ->assertStatus(409);

    // Admin ve la solicitud en notificaciones.
    $this->actingAs($this->admin, 'sanctum')
        ->getJson('/api/v1/notificaciones/reactivaciones')
        ->assertOk()
        ->assertJsonPath('meta.pendientes_count', 1)
        ->assertJsonPath('data.0.chofer.nombre', 'Carlos Chofer')
        ->assertJsonPath('data.0.motivo_desactivacion', 'Licencia médica')
        ->assertJsonPath('data.0.motivo', 'Ya tengo el alta médica para retomar turnos.');

    // Admin rechaza: exige motivo_rechazo obligatorio.
    $this->actingAs($this->admin, 'sanctum')
        ->postJson("/api/v1/notificaciones/reactivaciones/{$solicitudId}/rechazar", ['motivo_rechazo' => ''])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['motivo_rechazo']);

    $this->actingAs($this->admin, 'sanctum')
        ->postJson("/api/v1/notificaciones/reactivaciones/{$solicitudId}/rechazar", [
            'motivo_rechazo' => 'Falta presentar el certificado médico en oficina.',
        ])
        ->assertOk()
        ->assertJsonPath('data.estado', 'rechazada')
        ->assertJsonPath('data.motivo_rechazo', 'Falta presentar el certificado médico en oficina.');

    expect(AuditLog::where('accion', 'reactivacion_rechazada')->where('user_id', $this->admin->id)->exists())->toBeTrue();

    // Tras rechazo, el chofer ve el motivo y NO puede volver a solicitar en este ciclo.
    $this->actingAs($this->choferUser->fresh(), 'sanctum')
        ->getJson('/api/v1/chofer/estado-cuenta')
        ->assertOk()
        ->assertJsonPath('data.puede_solicitar_reactivacion', false)
        ->assertJsonPath('data.solicitud_reactivacion.estado', 'rechazada')
        ->assertJsonPath('data.solicitud_reactivacion.motivo_rechazo', 'Falta presentar el certificado médico en oficina.');

    $this->actingAs($this->choferUser->fresh(), 'sanctum')
        ->postJson('/api/v1/chofer/solicitud-reactivacion', [
            'motivo' => 'Intento tras rechazo en el mismo ciclo.',
        ])
        ->assertStatus(409);

    // El admin puede reactivar manualmente en cualquier momento (incluso tras rechazar).
    $this->actingAs($this->admin, 'sanctum')
        ->postJson("/api/v1/choferes/{$this->chofer->id}/reactivar")
        ->assertOk()
        ->assertJsonPath('data.is_active', true)
        ->assertJsonPath('data.motivo_desactivacion', null);

    expect($this->choferUser->fresh()->is_active)->toBeTrue();
    expect(AuditLog::where('accion', 'chofer_reactivado')->where('user_id', $this->admin->id)->exists())->toBeTrue();

    // No se puede reactivar si ya está activo.
    $this->actingAs($this->admin, 'sanctum')
        ->postJson("/api/v1/choferes/{$this->chofer->id}/reactivar")
        ->assertUnprocessable();
});

test('nuevo ciclo de desactivacion permite nueva solicitud y aprobacion reactiva inmediatamente', function () {
    // Ciclo 1: desactivar -> solicitar -> rechazar -> reactivar manual.
    $this->actingAs($this->admin, 'sanctum')
        ->deleteJson("/api/v1/choferes/{$this->chofer->id}", ['motivo' => 'Vacaciones o permiso'])
        ->assertNoContent();

    $sol1 = $this->actingAs($this->choferUser->fresh(), 'sanctum')
        ->postJson('/api/v1/chofer/solicitud-reactivacion', ['motivo' => 'Volví antes de vacaciones.'])
        ->assertCreated()
        ->json('data.id');

    $this->actingAs($this->admin, 'sanctum')
        ->postJson("/api/v1/notificaciones/reactivaciones/{$sol1}/rechazar", ['motivo_rechazo' => 'Tu turno parte el lunes.'])
        ->assertOk();

    $this->actingAs($this->admin, 'sanctum')
        ->postJson("/api/v1/choferes/{$this->chofer->id}/reactivar")
        ->assertOk();

    // Ciclo 2: nueva desactivación inicia limpia (sin heredar el rechazo anterior).
    $this->actingAs($this->admin, 'sanctum')
        ->deleteJson("/api/v1/choferes/{$this->chofer->id}", ['motivo' => 'Licencia de conducir vencida o suspendida'])
        ->assertNoContent();

    $this->actingAs($this->choferUser->fresh(), 'sanctum')
        ->getJson('/api/v1/chofer/estado-cuenta')
        ->assertOk()
        ->assertJsonPath('data.puede_solicitar_reactivacion', true)
        ->assertJsonPath('data.solicitud_reactivacion', null);

    $sol2 = $this->actingAs($this->choferUser->fresh(), 'sanctum')
        ->postJson('/api/v1/chofer/solicitud-reactivacion', ['motivo' => 'Ya renové mi licencia clase A4.'])
        ->assertCreated()
        ->json('data.id');

    // Aprobar reactiva inmediatamente al chofer y habilita sus endpoints operativos.
    $this->actingAs($this->admin, 'sanctum')
        ->postJson("/api/v1/notificaciones/reactivaciones/{$sol2}/aprobar")
        ->assertOk()
        ->assertJsonPath('data.estado', 'aprobada');

    expect($this->choferUser->fresh()->is_active)->toBeTrue()
        ->and($this->chofer->fresh()->motivo_desactivacion)->toBeNull();
    expect(AuditLog::where('accion', 'reactivacion_aprobada')->where('user_id', $this->admin->id)->exists())->toBeTrue();

    $this->actingAs($this->choferUser->fresh(), 'sanctum')
        ->getJson('/api/v1/chofer/licencia')
        ->assertOk();
});
