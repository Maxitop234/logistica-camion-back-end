<?php

namespace App\Services;

use App\Models\Dispositivo;
use App\Models\LecturaPunto;
use App\Models\Punto;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Procesa lecturas de llenado de un punto de reciclaje (desde ESP32, simulación
 * de calibración o ajuste manual), con cálculo ultrasónico, clasificación y
 * soporte idempotente por `lectura_uuid`.
 */
class PuntoTelemetryService
{
    /**
     * @return array{punto: Punto, lectura: LecturaPunto, duplicada: bool}
     */
    public function registrarLectura(
        Punto $punto,
        string $origen,
        ?float $distanciaCm = null,
        ?int $porcentajeDirecto = null,
        ?float $bateriaVoltaje = null,
        ?CarbonInterface $medidoEn = null,
        ?string $lecturaUuid = null,
        ?User $registradaPor = null,
        ?Dispositivo $dispositivo = null,
        ?int $rssiDbm = null,
        ?CarbonInterface $recibidoEn = null,
    ): array {
        if (is_string($lecturaUuid) && $lecturaUuid !== '') {
            $existente = LecturaPunto::where('lectura_uuid', $lecturaUuid)->first();
            if ($existente instanceof LecturaPunto) {
                return [
                    'punto' => $punto->fresh(),
                    'lectura' => $existente,
                    'duplicada' => true,
                ];
            }
        }

        $alturaCm = (float) ($punto->altura_contenedor_cm ?: 120.0);
        // Con dispositivo, el porcentaje ya viene calculado con su calibración.
        $porcentaje = $porcentajeDirecto !== null
            ? max(0, min(100, $porcentajeDirecto))
            : ($distanciaCm !== null ? Punto::porcentajeDesdeDistancia($distanciaCm, $alturaCm) : 0);

        $estado = Punto::clasificarEstado($porcentaje);
        $momento = $medidoEn ?? now();

        $lectura = DB::transaction(function () use (
            $punto,
            $origen,
            $distanciaCm,
            $porcentaje,
            $estado,
            $bateriaVoltaje,
            $momento,
            $lecturaUuid,
            $registradaPor,
            $dispositivo,
            $rssiDbm,
            $recibidoEn,
        ): LecturaPunto {
            $nueva = $punto->lecturas()->create([
                'lectura_uuid' => $lecturaUuid,
                'origen' => $origen,
                'distancia_cm' => $distanciaCm,
                'porcentaje_llenado' => $porcentaje,
                'estado_llenado' => $estado,
                'bateria_voltaje' => $bateriaVoltaje,
                'registrada_por_user_id' => $registradaPor?->id,
                'dispositivo_id' => $dispositivo?->id,
                'rssi_dbm' => $rssiDbm,
                'medido_en' => $momento,
            ]);

            // Una lectura atrasada (buffer sin conexión) no pisa un estado más reciente.
            $esMasReciente = $dispositivo === null
                || $punto->ultima_actualizacion_estado === null
                || $momento->greaterThanOrEqualTo($punto->ultima_actualizacion_estado);

            $actualizacion = [];
            if ($esMasReciente) {
                $actualizacion = [
                    'porcentaje_llenado' => $porcentaje,
                    'estado_llenado' => $estado,
                    'origen_actualizacion' => $origen,
                    'ultima_actualizacion_estado' => $momento,
                ];
            }

            if ($origen === Punto::ORIGEN_SENSOR_IOT) {
                $actualizacion['ultimo_heartbeat_iot'] = $recibidoEn ?? now();
                if ($bateriaVoltaje !== null) {
                    $actualizacion['bateria_voltaje'] = $bateriaVoltaje;
                }
            }

            if ($actualizacion !== []) {
                $punto->update($actualizacion);
            }

            return $nueva;
        });

        return [
            'punto' => $punto->fresh(),
            'lectura' => $lectura,
            'duplicada' => false,
        ];
    }
}
