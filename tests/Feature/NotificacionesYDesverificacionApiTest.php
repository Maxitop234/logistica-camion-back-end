<?php

use App\Models\Administrador;
use App\Models\AuditLog;
use App\Models\Chofer;
use App\Models\NotificacionChofer;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('local');

    $this->admin = User::factory()->create(['role' => 'administrador']);
    Administrador::factory()->create([
        'user_id' => $this->admin->id,
        'nombre_completo' => 'María Administradora',
    ]);

    $this->chofer = Chofer::factory()->create(['nombre_cifrado' => 'Carlos Chofer']);
    $this->choferUser = $this->chofer->user;
});

function pdfPruebaNotif(string $nombre = 'hoja_vida.pdf'): UploadedFile
{
    $contenido = "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\n%%EOF\n";

    return UploadedFile::fake()->createWithContent($nombre, $contenido);
}

test('admin recibe alerta de PDF pendiente de revision y puede verificar y desverificar sin borrar el archivo', function () {
    // Sin PDF no se puede desverificar.
    $this->actingAs($this->admin, 'sanctum')
        ->postJson("/api/v1/choferes/{$this->chofer->id}/licencia/desverificar")
        ->assertUnprocessable();

    // Chofer sube PDF -> aparece en pendientes_revision del admin.
    $this->actingAs($this->choferUser, 'sanctum')
        ->post('/api/v1/chofer/licencia', ['archivo' => pdfPruebaNotif()], ['Accept' => 'application/json'])
        ->assertCreated();

    $rutaPdf = $this->chofer->fresh()->licencia_archivo_path;

    $this->actingAs($this->admin, 'sanctum')
        ->getJson('/api/v1/notificaciones/licencias')
        ->assertOk()
        ->assertJsonPath('meta.pendientes_revision_count', 1)
        ->assertJsonPath('meta.total_pendientes_count', 1)
        ->assertJsonPath('pendientes_revision.0.id', $this->chofer->id);

    // Admin verifica -> sale de pendientes_revision y notifica al chofer.
    $this->actingAs($this->admin, 'sanctum')
        ->postJson("/api/v1/choferes/{$this->chofer->id}/licencia/verificar")
        ->assertOk()
        ->assertJsonPath('data.licencia.estado', 'verificada');

    $this->actingAs($this->admin, 'sanctum')
        ->getJson('/api/v1/notificaciones/licencias')
        ->assertOk()
        ->assertJsonPath('meta.pendientes_revision_count', 0)
        ->assertJsonPath('meta.total_pendientes_count', 0);

    // Admin quita verificación (desverificar): vuelve a pendiente CON PDF intacto, audita y notifica.
    $this->actingAs($this->admin, 'sanctum')
        ->postJson("/api/v1/choferes/{$this->chofer->id}/licencia/desverificar")
        ->assertOk()
        ->assertJsonPath('data.licencia.estado', 'pendiente')
        ->assertJsonPath('data.licencia.tiene_archivo', true)
        ->assertJsonPath('data.licencia.verificada_por', null);

    Storage::disk('local')->assertExists($rutaPdf);
    expect(AuditLog::where('accion', 'licencia_desverificada')->where('user_id', $this->admin->id)->exists())->toBeTrue();

    // Vuelve a aparecer como PDF pendiente de revisión en la campana del admin.
    $this->actingAs($this->admin, 'sanctum')
        ->getJson('/api/v1/notificaciones/licencias')
        ->assertOk()
        ->assertJsonPath('meta.pendientes_revision_count', 1);

    // El chofer tiene 2 notificaciones (verificada + desverificada).
    $this->actingAs($this->choferUser, 'sanctum')
        ->getJson('/api/v1/chofer/notificaciones')
        ->assertOk()
        ->assertJsonPath('meta.no_leidas_count', 2)
        ->assertJsonPath('data.0.tipo', NotificacionChofer::TIPO_LICENCIA_DESVERIFICADA)
        ->assertJsonPath('data.1.tipo', NotificacionChofer::TIPO_LICENCIA_VERIFICADA);
});

test('chofer consulta notificaciones, marca una o todas como leidas y no accede a las de otro chofer', function () {
    $otroChofer = Chofer::factory()->create();

    $n1 = NotificacionChofer::crear($this->chofer, NotificacionChofer::TIPO_LICENCIA_VERIFICADA, 'Título 1', 'Mensaje 1');
    NotificacionChofer::crear($this->chofer, NotificacionChofer::TIPO_CUENTA_REACTIVADA, 'Título 2', 'Mensaje 2');
    $ajena = NotificacionChofer::crear($otroChofer, NotificacionChofer::TIPO_LICENCIA_VERIFICADA, 'Ajena', 'Otro chofer');

    // Control de rol y acceso.
    $this->actingAs($this->admin, 'sanctum')
        ->getJson('/api/v1/chofer/notificaciones')
        ->assertForbidden();

    $this->actingAs($this->choferUser, 'sanctum')
        ->postJson("/api/v1/chofer/notificaciones/{$ajena->id}/leer")
        ->assertForbidden();

    // Marca una como leída.
    $this->actingAs($this->choferUser, 'sanctum')
        ->postJson("/api/v1/chofer/notificaciones/{$n1->id}/leer")
        ->assertOk()
        ->assertJsonPath('data.leida', true);

    $this->actingAs($this->choferUser, 'sanctum')
        ->getJson('/api/v1/chofer/notificaciones')
        ->assertOk()
        ->assertJsonPath('meta.no_leidas_count', 1);

    // Marca todas como leídas.
    $this->actingAs($this->choferUser, 'sanctum')
        ->postJson('/api/v1/chofer/notificaciones/leer-todas')
        ->assertOk()
        ->assertJsonPath('meta.no_leidas_count', 0);

    $this->actingAs($this->choferUser, 'sanctum')
        ->getJson('/api/v1/chofer/notificaciones')
        ->assertOk()
        ->assertJsonPath('meta.no_leidas_count', 0);
});

test('acciones del admin sobre borrado de PDF y reactivacion generan notificaciones persistidas al chofer', function () {
    $this->actingAs($this->choferUser, 'sanctum')
        ->post('/api/v1/chofer/licencia', ['archivo' => pdfPruebaNotif()], ['Accept' => 'application/json'])
        ->assertCreated();

    $solicitudId = $this->actingAs($this->choferUser, 'sanctum')
        ->postJson('/api/v1/chofer/licencia/solicitud-borrado', [
            'motivo' => 'Subí el documento anterior por error.',
        ])
        ->assertCreated()
        ->json('data.id');

    $this->actingAs($this->admin, 'sanctum')
        ->postJson("/api/v1/notificaciones/licencias/{$solicitudId}/aprobar")
        ->assertOk();

    // Desactiva y reactiva manualmente al chofer.
    $this->actingAs($this->admin, 'sanctum')
        ->deleteJson("/api/v1/choferes/{$this->chofer->id}", ['motivo' => 'Licencia médica'])
        ->assertNoContent();

    $this->actingAs($this->admin, 'sanctum')
        ->postJson("/api/v1/choferes/{$this->chofer->id}/reactivar")
        ->assertOk();

    $tipos = NotificacionChofer::where('chofer_id', $this->chofer->id)
        ->pluck('tipo')
        ->all();

    expect($tipos)->toContain(NotificacionChofer::TIPO_BORRADO_APROBADO)
        ->and($tipos)->toContain(NotificacionChofer::TIPO_CUENTA_REACTIVADA);
});

test('admin rechaza licencia en revision con motivo, elimina pdf, audita, notifica y chofer puede resubir', function () {
    // Sin PDF no se puede rechazar.
    $this->actingAs($this->admin, 'sanctum')
        ->postJson("/api/v1/choferes/{$this->chofer->id}/licencia/rechazar", [
            'motivo' => 'El documento no corresponde a la Hoja de Vida.',
        ])
        ->assertUnprocessable();

    // Chofer sube PDF inicial.
    $this->actingAs($this->choferUser, 'sanctum')
        ->post('/api/v1/chofer/licencia', ['archivo' => pdfPruebaNotif('equivocado.pdf')], ['Accept' => 'application/json'])
        ->assertCreated();

    $rutaInicial = $this->chofer->fresh()->licencia_archivo_path;

    // Motivo obligatorio y mínimo 5 caracteres.
    $this->actingAs($this->admin, 'sanctum')
        ->postJson("/api/v1/choferes/{$this->chofer->id}/licencia/rechazar", ['motivo' => 'mal'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['motivo']);

    // Rechazo válido: elimina archivo, pasa a rechazada, guarda motivo/quién/cuándo, audita y notifica.
    $motivo = 'El documento no es la Hoja de Vida del Conductor';
    $this->actingAs($this->admin, 'sanctum')
        ->postJson("/api/v1/choferes/{$this->chofer->id}/licencia/rechazar", ['motivo' => $motivo])
        ->assertOk()
        ->assertJsonPath('data.licencia.estado', 'rechazada')
        ->assertJsonPath('data.licencia.tiene_archivo', false)
        ->assertJsonPath('data.licencia.puede_subir', true)
        ->assertJsonPath('data.licencia.motivo_rechazo', $motivo)
        ->assertJsonPath('data.licencia.rechazada_por.nombre', 'María Administradora');

    Storage::disk('local')->assertMissing($rutaInicial);
    expect($this->chofer->fresh()->licencia_rechazada_en)->not->toBeNull();
    expect(AuditLog::where('accion', 'licencia_rechazada')->where('user_id', $this->admin->id)->exists())->toBeTrue();

    // Sale de pendientes de revisión del admin.
    $this->actingAs($this->admin, 'sanctum')
        ->getJson('/api/v1/notificaciones/licencias')
        ->assertOk()
        ->assertJsonPath('meta.pendientes_revision_count', 0);

    // El chofer recibe notificación y ve el estado rechazada con su motivo.
    $this->actingAs($this->choferUser, 'sanctum')
        ->getJson('/api/v1/chofer/notificaciones')
        ->assertOk()
        ->assertJsonPath('data.0.tipo', NotificacionChofer::TIPO_LICENCIA_RECHAZADA);

    $this->actingAs($this->choferUser, 'sanctum')
        ->getJson('/api/v1/chofer/licencia')
        ->assertOk()
        ->assertJsonPath('data.estado', 'rechazada')
        ->assertJsonPath('data.motivo_rechazo', $motivo)
        ->assertJsonPath('data.puede_subir', true);

    // Re-subida tras rechazo (sin 409): vuelve a pendiente (En revisión) y limpia campos de rechazo.
    $this->actingAs($this->choferUser, 'sanctum')
        ->post('/api/v1/chofer/licencia', ['archivo' => pdfPruebaNotif('hoja_correcta.pdf')], ['Accept' => 'application/json'])
        ->assertCreated()
        ->assertJsonPath('data.estado', 'pendiente')
        ->assertJsonPath('data.tiene_archivo', true)
        ->assertJsonPath('data.borrado_habilitado', false)
        ->assertJsonPath('data.motivo_rechazo', null)
        ->assertJsonPath('data.rechazada_en', null)
        ->assertJsonPath('data.rechazada_por', null);

    // No se puede rechazar una licencia ya verificada.
    $this->actingAs($this->admin, 'sanctum')
        ->postJson("/api/v1/choferes/{$this->chofer->id}/licencia/verificar")
        ->assertOk();

    $this->actingAs($this->admin, 'sanctum')
        ->postJson("/api/v1/choferes/{$this->chofer->id}/licencia/rechazar", ['motivo' => $motivo])
        ->assertUnprocessable();
});
