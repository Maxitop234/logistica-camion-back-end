<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Licencia\StoreSolicitudBorradoLicenciaRequest;
use App\Http\Requests\Licencia\UploadLicenciaChoferRequest;
use App\Http\Resources\ChoferResource;
use App\Http\Resources\LicenciaChoferResource;
use App\Http\Resources\SolicitudBorradoLicenciaResource;
use App\Models\AuditLog;
use App\Models\Chofer;
use App\Models\NotificacionChofer;
use App\Models\SolicitudBorradoLicencia;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

class LicenciaChoferController extends Controller
{
    /**
     * Relaciones necesarias para serializar el resumen completo de licencia.
     *
     * @var array<int, string>
     */
    public const RELACIONES_LICENCIA = [
        'licenciaVerificadaPor.administrador',
        'ultimaSolicitudBorrado.resueltaPor.administrador',
    ];

    /**
     * Devuelve el estado de verificación de licencia del chofer autenticado.
     */
    public function miLicencia(Request $request): LicenciaChoferResource|JsonResponse
    {
        $chofer = $this->choferAutenticado($request);
        if (! $chofer instanceof Chofer) {
            return response()->json(['message' => 'No existe perfil de chofer asociado a esta cuenta.'], 404);
        }

        return new LicenciaChoferResource($chofer->load(self::RELACIONES_LICENCIA));
    }

    /**
     * Sube el PDF de la Hoja de Vida del Conductor del chofer autenticado.
     */
    public function subir(UploadLicenciaChoferRequest $request): JsonResponse
    {
        $chofer = $this->choferAutenticado($request);
        if (! $chofer instanceof Chofer) {
            return response()->json(['message' => 'No existe perfil de chofer asociado a esta cuenta.'], 404);
        }

        if ($chofer->tieneArchivoLicencia() || $chofer->licencia_estado === Chofer::LICENCIA_VERIFICADA) {
            return response()->json([
                'message' => 'Ya existe un documento subido y bloqueado. Solicita al administrador habilitar el borrado si necesitas reemplazarlo.',
            ], 409);
        }

        $archivo = $request->file('archivo');
        $nombreSeguro = $this->sanitizarNombre($archivo->getClientOriginalName());
        $sha256 = hash_file('sha256', $archivo->getRealPath());
        $tamano = (int) $archivo->getSize();
        $ruta = $archivo->storeAs(
            "licencias/chofer_{$chofer->id}",
            Str::uuid()->toString().'.pdf',
            'local'
        );

        DB::transaction(function () use ($chofer, $ruta, $nombreSeguro, $sha256, $tamano, $request): void {
            $chofer->update([
                'licencia_estado' => Chofer::LICENCIA_PENDIENTE,
                'licencia_archivo_path' => $ruta,
                'licencia_archivo_nombre' => $nombreSeguro,
                'licencia_archivo_sha256' => $sha256,
                'licencia_subida_en' => now(),
                'licencia_verificada_por_user_id' => null,
                'licencia_verificada_en' => null,
                'licencia_borrado_habilitado' => false,
            ]);

            AuditLog::registrar('licencia_subida', $request->user(), $request, [
                'chofer_id' => $chofer->id,
                'archivo_nombre' => $nombreSeguro,
                'tamano_bytes' => $tamano,
                'sha256' => $sha256,
            ]);
        });

        return (new LicenciaChoferResource($chofer->fresh()->load(self::RELACIONES_LICENCIA)))
            ->response()
            ->setStatusCode(201);
    }

    /**
     * Sirve el PDF propio del chofer autenticado desde el disco privado.
     */
    public function miArchivo(Request $request): StreamedResponse|JsonResponse
    {
        $chofer = $this->choferAutenticado($request);
        if (! $chofer instanceof Chofer) {
            return response()->json(['message' => 'No existe perfil de chofer asociado a esta cuenta.'], 404);
        }

        return $this->responderPdf($chofer);
    }

    /**
     * Registra una solicitud al administrador para habilitar el borrado del PDF.
     */
    public function solicitarBorrado(StoreSolicitudBorradoLicenciaRequest $request): JsonResponse
    {
        $chofer = $this->choferAutenticado($request);
        if (! $chofer instanceof Chofer) {
            return response()->json(['message' => 'No existe perfil de chofer asociado a esta cuenta.'], 404);
        }

        if (! $chofer->tieneArchivoLicencia()) {
            return response()->json(['message' => 'No hay ningún documento cargado para eliminar.'], 422);
        }

        if ($chofer->licencia_borrado_habilitado) {
            return response()->json(['message' => 'El borrado ya está habilitado para tu documento.'], 409);
        }

        $yaPendiente = $chofer->solicitudesBorradoLicencia()
            ->where('estado', SolicitudBorradoLicencia::ESTADO_PENDIENTE)
            ->exists();

        if ($yaPendiente) {
            return response()->json([
                'message' => 'Ya tienes una solicitud pendiente de revisión por el administrador.',
            ], 409);
        }

        $motivo = $request->validated('motivo');

        $solicitud = DB::transaction(function () use ($chofer, $motivo, $request): SolicitudBorradoLicencia {
            $nueva = $chofer->solicitudesBorradoLicencia()->create([
                'motivo' => $motivo,
                'estado' => SolicitudBorradoLicencia::ESTADO_PENDIENTE,
            ]);

            AuditLog::registrar('licencia_borrado_solicitado', $request->user(), $request, [
                'chofer_id' => $chofer->id,
                'solicitud_id' => $nueva->id,
                'motivo' => $motivo,
            ]);

            return $nueva;
        });

        return (new SolicitudBorradoLicenciaResource($solicitud->load('resueltaPor.administrador')))
            ->response()
            ->setStatusCode(201);
    }

    /**
     * Elimina el PDF del chofer autenticado solo si el administrador habilitó el borrado.
     */
    public function eliminarPropia(Request $request): LicenciaChoferResource|JsonResponse
    {
        $chofer = $this->choferAutenticado($request);
        if (! $chofer instanceof Chofer) {
            return response()->json(['message' => 'No existe perfil de chofer asociado a esta cuenta.'], 404);
        }

        if (! $chofer->tieneArchivoLicencia()) {
            return response()->json(['message' => 'No existe un documento cargado para eliminar.'], 404);
        }

        if (! $chofer->licencia_borrado_habilitado) {
            return response()->json([
                'message' => 'El borrado del documento está bloqueado por seguridad. Solicita autorización al administrador.',
            ], 403);
        }

        $rutaAnterior = $chofer->licencia_archivo_path;
        $shaAnterior = $chofer->licencia_archivo_sha256;

        DB::transaction(function () use ($chofer, $shaAnterior, $request): void {
            $chofer->update([
                'licencia_estado' => Chofer::LICENCIA_PENDIENTE,
                'licencia_archivo_path' => null,
                'licencia_archivo_nombre' => null,
                'licencia_archivo_sha256' => null,
                'licencia_subida_en' => null,
                'licencia_verificada_por_user_id' => null,
                'licencia_verificada_en' => null,
                'licencia_borrado_habilitado' => false,
            ]);

            AuditLog::registrar('licencia_eliminada_chofer', $request->user(), $request, [
                'chofer_id' => $chofer->id,
                'sha256_anterior' => $shaAnterior,
            ]);
        });

        if (is_string($rutaAnterior) && Storage::disk('local')->exists($rutaAnterior)) {
            Storage::disk('local')->delete($rutaAnterior);
        }

        return new LicenciaChoferResource($chofer->fresh()->load(self::RELACIONES_LICENCIA));
    }

    /**
     * Sirve el PDF de un chofer al administrador autenticado.
     */
    public function archivoPorAdmin(Chofer $chofer): StreamedResponse|JsonResponse
    {
        return $this->responderPdf($chofer);
    }

    /**
     * Marca manualmente la licencia de un chofer como verificada.
     */
    public function verificar(Request $request, Chofer $chofer): ChoferResource|JsonResponse
    {
        if (! $chofer->tieneArchivoLicencia()) {
            return response()->json([
                'message' => 'No se puede verificar la licencia sin un documento PDF cargado.',
            ], 422);
        }

        DB::transaction(function () use ($chofer, $request): void {
            $chofer->update([
                'licencia_estado' => Chofer::LICENCIA_VERIFICADA,
                'licencia_verificada_por_user_id' => $request->user()->id,
                'licencia_verificada_en' => now(),
                'licencia_borrado_habilitado' => false,
            ]);

            AuditLog::registrar('licencia_verificada', $request->user(), $request, [
                'chofer_id' => $chofer->id,
                'sha256' => $chofer->licencia_archivo_sha256,
            ]);

            NotificacionChofer::crear(
                $chofer,
                NotificacionChofer::TIPO_LICENCIA_VERIFICADA,
                'Licencia verificada',
                'Tu Hoja de Vida del Conductor fue verificada por el administrador.',
            );
        });

        return new ChoferResource($chofer->fresh()->load([
            'user',
            'camionActual',
            ...self::RELACIONES_LICENCIA,
        ]));
    }

    /**
     * Quita la verificación de una licencia ya verificada sin eliminar el PDF cargado.
     */
    public function desverificar(Request $request, Chofer $chofer): ChoferResource|JsonResponse
    {
        if ($chofer->licencia_estado !== Chofer::LICENCIA_VERIFICADA) {
            return response()->json([
                'message' => 'La licencia de este chofer no se encuentra verificada.',
            ], 422);
        }

        DB::transaction(function () use ($chofer, $request): void {
            $chofer->update([
                'licencia_estado' => Chofer::LICENCIA_PENDIENTE,
                'licencia_verificada_por_user_id' => null,
                'licencia_verificada_en' => null,
            ]);

            AuditLog::registrar('licencia_desverificada', $request->user(), $request, [
                'chofer_id' => $chofer->id,
                'archivo_nombre' => $chofer->licencia_archivo_nombre,
                'sha256' => $chofer->licencia_archivo_sha256,
            ]);

            NotificacionChofer::crear(
                $chofer,
                NotificacionChofer::TIPO_LICENCIA_DESVERIFICADA,
                'Verificación de licencia removida',
                'El administrador quitó la verificación de tu licencia. Tu documento PDF se conserva pendiente de revisión.',
            );
        });

        return new ChoferResource($chofer->fresh()->load([
            'user',
            'camionActual',
            ...self::RELACIONES_LICENCIA,
        ]));
    }

    /**
     * Destraba y elimina el documento de un chofer (pendiente o ya verificada) devolviéndolo a pendiente sin archivo.
     */
    public function destrabarPorAdmin(Request $request, Chofer $chofer): ChoferResource|JsonResponse
    {
        if (! $chofer->tieneArchivoLicencia() && $chofer->licencia_estado !== Chofer::LICENCIA_VERIFICADA) {
            return response()->json([
                'message' => 'El chofer no tiene un documento cargado para destrabar.',
            ], 422);
        }

        $rutaAnterior = $chofer->licencia_archivo_path;
        $shaAnterior = $chofer->licencia_archivo_sha256;
        $estadoAnterior = $chofer->licencia_estado;

        DB::transaction(function () use ($chofer, $shaAnterior, $estadoAnterior, $request): void {
            $chofer->update([
                'licencia_estado' => Chofer::LICENCIA_PENDIENTE,
                'licencia_archivo_path' => null,
                'licencia_archivo_nombre' => null,
                'licencia_archivo_sha256' => null,
                'licencia_subida_en' => null,
                'licencia_verificada_por_user_id' => null,
                'licencia_verificada_en' => null,
                'licencia_borrado_habilitado' => false,
            ]);

            $chofer->solicitudesBorradoLicencia()
                ->where('estado', SolicitudBorradoLicencia::ESTADO_PENDIENTE)
                ->update([
                    'estado' => SolicitudBorradoLicencia::ESTADO_APROBADA,
                    'resuelta_por_user_id' => $request->user()->id,
                    'resuelta_en' => now(),
                ]);

            AuditLog::registrar('licencia_destrabada_admin', $request->user(), $request, [
                'chofer_id' => $chofer->id,
                'estado_anterior' => $estadoAnterior,
                'sha256_anterior' => $shaAnterior,
            ]);

            NotificacionChofer::crear(
                $chofer,
                NotificacionChofer::TIPO_LICENCIA_DESTRABADA,
                'Licencia destrabada',
                'El administrador eliminó el PDF anterior de tu licencia. Ya puedes subir un nuevo documento.',
            );
        });

        if (is_string($rutaAnterior) && Storage::disk('local')->exists($rutaAnterior)) {
            Storage::disk('local')->delete($rutaAnterior);
        }

        return new ChoferResource($chofer->fresh()->load([
            'user',
            'camionActual',
            ...self::RELACIONES_LICENCIA,
        ]));
    }

    private function choferAutenticado(Request $request): ?Chofer
    {
        return $request->user()?->chofer()->first();
    }

    private function responderPdf(Chofer $chofer): StreamedResponse|JsonResponse
    {
        if (! $chofer->tieneArchivoLicencia() || ! Storage::disk('local')->exists($chofer->licencia_archivo_path)) {
            return response()->json(['message' => 'No existe un documento PDF cargado para este chofer.'], 404);
        }

        $nombre = $this->sanitizarNombre($chofer->licencia_archivo_nombre ?? 'hoja_vida_conductor.pdf');

        return Storage::disk('local')->response(
            $chofer->licencia_archivo_path,
            $nombre,
            [
                'Content-Type' => 'application/pdf',
                'X-Content-Type-Options' => 'nosniff',
            ]
        );
    }

    private function sanitizarNombre(string $nombre): string
    {
        $base = basename(str_replace('\\', '/', $nombre));
        $limpio = preg_replace('/[^A-Za-z0-9._ -]/', '_', $base) ?? '';
        $limpio = trim($limpio, " .\t\n\r\0\x0B");

        if ($limpio === '') {
            return 'hoja_vida_conductor.pdf';
        }

        if (! str_ends_with(strtolower($limpio), '.pdf')) {
            $limpio .= '.pdf';
        }

        return Str::limit($limpio, 120, '');
    }
}
