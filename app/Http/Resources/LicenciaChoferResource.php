<?php

namespace App\Http\Resources;

use App\Models\Chofer;
use App\Models\SolicitudBorradoLicencia;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Chofer
 */
class LicenciaChoferResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public static function resumen(Chofer $chofer): array
    {
        $tieneArchivo = $chofer->tieneArchivoLicencia();
        $verificador = $chofer->relationLoaded('licenciaVerificadaPor')
            ? $chofer->licenciaVerificadaPor
            : null;
        $rechazador = $chofer->relationLoaded('licenciaRechazadaPor')
            ? $chofer->licenciaRechazadaPor
            : null;
        $ultimaSolicitud = $chofer->relationLoaded('ultimaSolicitudBorrado')
            ? $chofer->ultimaSolicitudBorrado
            : null;

        return [
            'estado' => $chofer->licencia_estado ?: Chofer::LICENCIA_PENDIENTE,
            'tiene_archivo' => $tieneArchivo,
            'archivo_nombre' => $chofer->licencia_archivo_nombre,
            'subida_en' => $chofer->licencia_subida_en?->toIso8601String(),
            'verificada_en' => $chofer->licencia_verificada_en?->toIso8601String(),
            'verificada_por' => self::datosUsuario($verificador),
            'motivo_rechazo' => $chofer->licencia_motivo_rechazo,
            'rechazada_en' => $chofer->licencia_rechazada_en?->toIso8601String(),
            'rechazada_por' => self::datosUsuario($rechazador),
            'borrado_habilitado' => (bool) $chofer->licencia_borrado_habilitado,
            'puede_subir' => ! $tieneArchivo && ($chofer->licencia_estado ?: Chofer::LICENCIA_PENDIENTE) !== Chofer::LICENCIA_VERIFICADA,
            'bloqueada' => $tieneArchivo && ! $chofer->licencia_borrado_habilitado,
            'ultima_solicitud' => $ultimaSolicitud instanceof SolicitudBorradoLicencia
                ? self::resumenSolicitud($ultimaSolicitud)
                : null,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function datosUsuario(?User $user): ?array
    {
        if (! $user instanceof User) {
            return null;
        }

        $nombre = $user->relationLoaded('administrador') && $user->administrador !== null
            ? $user->administrador->nombre_completo
            : $user->name;

        return [
            'id' => $user->id,
            'nombre' => $nombre,
            'email' => $user->email,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function resumenSolicitud(SolicitudBorradoLicencia $solicitud): array
    {
        $resueltaPor = $solicitud->relationLoaded('resueltaPor')
            ? $solicitud->resueltaPor
            : null;

        return [
            'id' => $solicitud->id,
            'motivo' => $solicitud->motivo,
            'estado' => $solicitud->estado,
            'created_at' => $solicitud->created_at?->toIso8601String(),
            'resuelta_en' => $solicitud->resuelta_en?->toIso8601String(),
            'respuesta_admin' => $solicitud->respuesta_admin,
            'resuelta_por' => self::datosUsuario($resueltaPor),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return self::resumen($this->resource);
    }
}
