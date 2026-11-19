<?php

use App\Models\Administrador;
use App\Models\AuditLog;
use App\Models\Chofer;
use App\Models\SolicitudBorradoLicencia;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('local');
    Storage::fake('public');

    $this->admin = User::factory()->create(['role' => 'administrador']);
    Administrador::factory()->create([
        'user_id' => $this->admin->id,
        'nombre_completo' => 'María Administradora',
    ]);

    $this->chofer = Chofer::factory()->create(['nombre_cifrado' => 'Carlos Chofer']);
    $this->choferUser = $this->chofer->user;
});

function pdfValido(string $nombre = 'hoja_vida.pdf'): UploadedFile
{
    $contenido = "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\n%%EOF\n";

    return UploadedFile::fake()->createWithContent($nombre, $contenido);
}

test('un chofer nace con licencia pendiente sin archivo y puede subir', function () {
    $this->actingAs($this->choferUser, 'sanctum')
        ->getJson('/api/v1/chofer/licencia')
        ->assertOk()
        ->assertJsonPath('data.estado', 'pendiente')
        ->assertJsonPath('data.tiene_archivo', false)
        ->assertJsonPath('data.puede_subir', true)
        ->assertJsonPath('data.bloqueada', false)
        ->assertJsonPath('data.borrado_habilitado', false);
});

test('el chofer sube un pdf valido a disco privado, queda bloqueado y se audita', function () {
    $this->actingAs($this->choferUser, 'sanctum')
        ->post('/api/v1/chofer/licencia', ['archivo' => pdfValido('mi_hoja_vida.pdf')], ['Accept' => 'application/json'])
        ->assertCreated()
        ->assertJsonPath('data.estado', 'pendiente')
        ->assertJsonPath('data.tiene_archivo', true)
        ->assertJsonPath('data.archivo_nombre', 'mi_hoja_vida.pdf')
        ->assertJsonPath('data.puede_subir', false)
        ->assertJsonPath('data.bloqueada', true);

    $chofer = $this->chofer->fresh();
    expect($chofer->licencia_archivo_path)->not->toBeNull()
        ->and($chofer->licencia_archivo_sha256)->toHaveLength(64);

    Storage::disk('local')->assertExists($chofer->licencia_archivo_path);
    expect(Storage::disk('public')->allFiles())->toBeEmpty();

    expect(AuditLog::where('accion', 'licencia_subida')->where('user_id', $this->choferUser->id)->exists())->toBeTrue();

    // Segundo intento de subida queda bloqueado (409) y borrado directo prohibido (403).
    $this->actingAs($this->choferUser, 'sanctum')
        ->post('/api/v1/chofer/licencia', ['archivo' => pdfValido('otro.pdf')], ['Accept' => 'application/json'])
        ->assertStatus(409);

    $this->actingAs($this->choferUser, 'sanctum')
        ->deleteJson('/api/v1/chofer/licencia')
        ->assertForbidden();
});

test('rechaza archivos que no sean pdf real o excedan 5 mb', function () {
    $falsoPdf = UploadedFile::fake()->createWithContent('falso.pdf', 'esto no es un pdf');
    $this->actingAs($this->choferUser, 'sanctum')
        ->post('/api/v1/chofer/licencia', ['archivo' => $falsoPdf], ['Accept' => 'application/json'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['archivo']);

    $txt = UploadedFile::fake()->createWithContent('doc.txt', '%PDF-1.4 fake');
    $this->actingAs($this->choferUser, 'sanctum')
        ->post('/api/v1/chofer/licencia', ['archivo' => $txt], ['Accept' => 'application/json'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['archivo']);

    $pesado = UploadedFile::fake()->create('grande.pdf', 6000, 'application/pdf');
    $this->actingAs($this->choferUser, 'sanctum')
        ->post('/api/v1/chofer/licencia', ['archivo' => $pesado], ['Accept' => 'application/json'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['archivo']);
});

test('respeta control de roles y permite descargar solo el pdf autorizado', function () {
    $this->actingAs($this->choferUser, 'sanctum')
        ->post('/api/v1/chofer/licencia', ['archivo' => pdfValido()], ['Accept' => 'application/json'])
        ->assertCreated();

    $otroChofer = Chofer::factory()->create();

    // El propio chofer puede ver su archivo; otro chofer sin archivo recibe 404.
    $this->actingAs($this->choferUser, 'sanctum')
        ->get('/api/v1/chofer/licencia/archivo')
        ->assertOk()
        ->assertHeader('Content-Type', 'application/pdf');

    $this->actingAs($otroChofer->user, 'sanctum')
        ->get('/api/v1/chofer/licencia/archivo')
        ->assertNotFound();

    // Un chofer no puede usar endpoints de administrador ni descargar el PDF de otro chofer.
    $this->actingAs($otroChofer->user, 'sanctum')
        ->get("/api/v1/choferes/{$this->chofer->id}/licencia/archivo")
        ->assertForbidden();

    $this->actingAs($this->choferUser, 'sanctum')
        ->getJson('/api/v1/notificaciones/licencias')
        ->assertForbidden();

    // El administrador puede descargar el PDF del chofer y verlo en el listado de choferes.
    $this->actingAs($this->admin, 'sanctum')
        ->get("/api/v1/choferes/{$this->chofer->id}/licencia/archivo")
        ->assertOk()
        ->assertHeader('Content-Type', 'application/pdf');

    $this->actingAs($this->admin, 'sanctum')
        ->getJson('/api/v1/choferes')
        ->assertOk()
        ->assertJsonPath('data.0.licencia.tiene_archivo', true)
        ->assertJsonPath('data.0.licencia.estado', 'pendiente');
});

test('flujo completo de solicitud de borrado, rechazo, aprobacion y eliminacion por el chofer', function () {
    $this->actingAs($this->choferUser, 'sanctum')
        ->post('/api/v1/chofer/licencia', ['archivo' => pdfValido()], ['Accept' => 'application/json'])
        ->assertCreated();

    $rutaOriginal = $this->chofer->fresh()->licencia_archivo_path;

    // Motivo obligatorio.
    $this->actingAs($this->choferUser, 'sanctum')
        ->postJson('/api/v1/chofer/licencia/solicitud-borrado', ['motivo' => 'abc'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['motivo']);

    // Crea solicitud pendiente y bloquea duplicados.
    $solicitudId = $this->actingAs($this->choferUser, 'sanctum')
        ->postJson('/api/v1/chofer/licencia/solicitud-borrado', [
            'motivo' => 'Subí el archivo equivocado por error.',
        ])
        ->assertCreated()
        ->assertJsonPath('data.estado', 'pendiente')
        ->json('data.id');

    $this->actingAs($this->choferUser, 'sanctum')
        ->postJson('/api/v1/chofer/licencia/solicitud-borrado', [
            'motivo' => 'Segundo intento duplicado.',
        ])
        ->assertStatus(409);

    // Admin ve la notificación pendiente con los datos del chofer.
    $this->actingAs($this->admin, 'sanctum')
        ->getJson('/api/v1/notificaciones/licencias')
        ->assertOk()
        ->assertJsonPath('meta.pendientes_count', 1)
        ->assertJsonPath('data.0.chofer.nombre', 'Carlos Chofer')
        ->assertJsonPath('data.0.motivo', 'Subí el archivo equivocado por error.');

    // Admin rechaza la primera solicitud: sigue bloqueado y el chofer ve el rechazo.
    $this->actingAs($this->admin, 'sanctum')
        ->postJson("/api/v1/notificaciones/licencias/{$solicitudId}/rechazar", [
            'respuesta_admin' => 'El documento cargado sí corresponde.',
        ])
        ->assertOk()
        ->assertJsonPath('data.estado', 'rechazada')
        ->assertJsonPath('data.respuesta_admin', 'El documento cargado sí corresponde.');

    $this->actingAs($this->choferUser, 'sanctum')
        ->getJson('/api/v1/chofer/licencia')
        ->assertOk()
        ->assertJsonPath('data.borrado_habilitado', false)
        ->assertJsonPath('data.ultima_solicitud.estado', 'rechazada')
        ->assertJsonPath('data.ultima_solicitud.respuesta_admin', 'El documento cargado sí corresponde.');

    expect(AuditLog::where('accion', 'licencia_borrado_rechazado')->exists())->toBeTrue();

    // Chofer puede enviar una nueva solicitud tras el rechazo y el admin la aprueba.
    $nuevaId = $this->actingAs($this->choferUser, 'sanctum')
        ->postJson('/api/v1/chofer/licencia/solicitud-borrado', [
            'motivo' => 'Necesito subir la versión actualizada de hoy.',
        ])
        ->assertCreated()
        ->json('data.id');

    $this->actingAs($this->admin, 'sanctum')
        ->postJson("/api/v1/notificaciones/licencias/{$nuevaId}/aprobar")
        ->assertOk()
        ->assertJsonPath('data.estado', 'aprobada');

    expect($this->chofer->fresh()->licencia_borrado_habilitado)->toBeTrue();
    expect(AuditLog::where('accion', 'licencia_borrado_aprobado')->exists())->toBeTrue();

    // No se puede aprobar dos veces.
    $this->actingAs($this->admin, 'sanctum')
        ->postJson("/api/v1/notificaciones/licencias/{$nuevaId}/aprobar")
        ->assertStatus(409);

    // Ahora el chofer puede eliminar su PDF, el archivo físico se borra y vuelve a pendiente sin archivo.
    $this->actingAs($this->choferUser, 'sanctum')
        ->deleteJson('/api/v1/chofer/licencia')
        ->assertOk()
        ->assertJsonPath('data.estado', 'pendiente')
        ->assertJsonPath('data.tiene_archivo', false)
        ->assertJsonPath('data.borrado_habilitado', false)
        ->assertJsonPath('data.puede_subir', true);

    Storage::disk('local')->assertMissing($rutaOriginal);
    expect(AuditLog::where('accion', 'licencia_eliminada_chofer')->exists())->toBeTrue();

    // El chofer puede subir un nuevo PDF después de eliminar el anterior.
    $this->actingAs($this->choferUser, 'sanctum')
        ->post('/api/v1/chofer/licencia', ['archivo' => pdfValido('nueva_hoja.pdf')], ['Accept' => 'application/json'])
        ->assertCreated()
        ->assertJsonPath('data.archivo_nombre', 'nueva_hoja.pdf');
});

test('el administrador verifica la licencia con trazabilidad y tambien puede destrabar una verificada', function () {
    // Sin PDF no se puede verificar.
    $this->actingAs($this->admin, 'sanctum')
        ->postJson("/api/v1/choferes/{$this->chofer->id}/licencia/verificar")
        ->assertUnprocessable();

    $this->actingAs($this->choferUser, 'sanctum')
        ->post('/api/v1/chofer/licencia', ['archivo' => pdfValido()], ['Accept' => 'application/json'])
        ->assertCreated();

    $rutaPdf = $this->chofer->fresh()->licencia_archivo_path;

    // Verificación manual por el administrador.
    $this->actingAs($this->admin, 'sanctum')
        ->postJson("/api/v1/choferes/{$this->chofer->id}/licencia/verificar")
        ->assertOk()
        ->assertJsonPath('data.licencia.estado', 'verificada')
        ->assertJsonPath('data.licencia.verificada_por.nombre', 'María Administradora');

    expect($this->chofer->fresh()->licencia_verificada_en)->not->toBeNull();
    expect(AuditLog::where('accion', 'licencia_verificada')->where('user_id', $this->admin->id)->exists())->toBeTrue();

    // Si hay una solicitud pendiente y el admin destraba directamente la licencia verificada, todo vuelve a pendiente sin archivo.
    SolicitudBorradoLicencia::create([
        'chofer_id' => $this->chofer->id,
        'motivo' => 'Renové mi licencia y necesito actualizar el PDF.',
        'estado' => SolicitudBorradoLicencia::ESTADO_PENDIENTE,
    ]);

    $this->actingAs($this->admin, 'sanctum')
        ->deleteJson("/api/v1/choferes/{$this->chofer->id}/licencia")
        ->assertOk()
        ->assertJsonPath('data.licencia.estado', 'pendiente')
        ->assertJsonPath('data.licencia.tiene_archivo', false)
        ->assertJsonPath('data.licencia.verificada_por', null);

    Storage::disk('local')->assertMissing($rutaPdf);
    expect(SolicitudBorradoLicencia::where('chofer_id', $this->chofer->id)->where('estado', 'pendiente')->count())->toBe(0);
    expect(AuditLog::where('accion', 'licencia_destrabada_admin')->where('user_id', $this->admin->id)->exists())->toBeTrue();
});
