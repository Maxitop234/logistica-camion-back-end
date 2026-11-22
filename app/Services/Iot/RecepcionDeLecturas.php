<?php

namespace App\Services\Iot;

use App\Http\Requests\Punto\StoreIotLecturaRequest;
use App\Models\Dispositivo;
use App\Models\LecturaInvalida;
use App\Models\LecturaPunto;
use App\Models\Punto;
use App\Services\PuntoTelemetryService;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Flujo único de ingesta de lecturas: lo usan el endpoint HTTP del sensor y,
 * en la demo, el simulador, de modo que ambos pasan por la misma autenticación
 * por token, validación, calibración y registro.
 */
class RecepcionDeLecturas
{
    public function __construct(private readonly PuntoTelemetryService $telemetria) {}

    /**
     * @param  array<string, mixed>  $datos  Cuerpo JSON de la petición.
     * @param  CarbonInterface|null  $recibidoEn  Instante de recepción (el simulador pasa el simulado).
     * @return array{punto: Punto|null, lectura: LecturaPunto|null, valida: bool, motivo_invalido: string|null, duplicada: bool, dispositivo: Dispositivo|null}
     *
     * @throws IotException
     * @throws ValidationException
     */
    public function recibir(?string $token, array $datos, ?CarbonInterface $recibidoEn = null): array
    {
        if ($token === null || trim($token) === '') {
            throw new IotException('Token de sensor requerido.', 401);
        }

        $hash = Dispositivo::hashToken($token);
        $dispositivo = Dispositivo::query()->where('token_hash', $hash)->first();

        $datos = Validator::make($datos, StoreIotLecturaRequest::reglas(), StoreIotLecturaRequest::mensajes())->validate();
        $recibidoEn ??= now();
        $medidoEn = isset($datos['medido_en']) ? Carbon::parse((string) $datos['medido_en']) : $recibidoEn;
        // Un reloj del sensor adelantado no debe dejar el estado "vigente" por tiempo indefinido.
        if ($medidoEn->greaterThan($recibidoEn->copy()->addMinutes(5))) {
            $medidoEn = Carbon::instance($recibidoEn);
        }

        if (! $dispositivo instanceof Dispositivo) {
            return $this->recibirConTokenAntiguo($hash, $datos, $medidoEn, $recibidoEn);
        }

        if ($dispositivo->estado !== Dispositivo::ESTADO_ACTIVO) {
            throw new IotException('El sensor está desactivado.', 403);
        }

        $punto = $dispositivo->punto;
        if (! $punto instanceof Punto) {
            $this->registrarConexion($dispositivo, $datos, $recibidoEn);
            throw new IotException('El sensor no está asignado a un punto.', 409);
        }

        if (! $punto->activo) {
            throw new IotException('El punto asociado a este sensor está inactivo.', 403);
        }

        $uuid = $datos['lectura_uuid'] ?? null;
        if (is_string($uuid) && $uuid !== '') {
            $existente = LecturaPunto::where('lectura_uuid', $uuid)->first();
            $invalida = $existente === null ? LecturaInvalida::where('lectura_uuid', $uuid)->first() : null;
            if ($existente !== null || $invalida !== null) {
                $this->registrarConexion($dispositivo, $datos, $recibidoEn);

                return [
                    'punto' => $punto->fresh(),
                    'lectura' => $existente,
                    'valida' => $existente !== null,
                    'motivo_invalido' => $invalida?->motivo,
                    'duplicada' => true,
                    'dispositivo' => $dispositivo->fresh(),
                ];
            }
        }

        $distancia = isset($datos['distancia_cm']) ? (float) $datos['distancia_cm'] : null;
        $evaluacion = isset($datos['porcentaje_llenado']) && $distancia === null
            ? ['valida' => true, 'porcentaje' => (int) $datos['porcentaje_llenado'], 'motivo' => null]
            : $dispositivo->evaluarDistancia($distancia);
        if (isset($datos['error']) && $distancia === null) {
            $evaluacion = ['valida' => false, 'porcentaje' => null, 'motivo' => $this->motivoDeError((string) $datos['error'])];
        }

        $bateria = isset($datos['bateria_voltaje']) ? (float) $datos['bateria_voltaje'] : null;
        $rssi = isset($datos['rssi_dbm']) ? (int) $datos['rssi_dbm'] : null;

        if (! $evaluacion['valida']) {
            $invalidaNueva = LecturaInvalida::create([
                'dispositivo_id' => $dispositivo->id,
                'punto_id' => $punto->id,
                'lectura_uuid' => $uuid,
                'distancia_cm' => $distancia,
                'motivo' => $evaluacion['motivo'],
                'bateria_voltaje' => $bateria,
                'rssi_dbm' => $rssi,
                'medido_en' => $medidoEn,
            ]);
            $dispositivo->lecturas_invalidas_seguidas++;
            $this->registrarConexion($dispositivo, $datos, $recibidoEn, $medidoEn);
            $punto->update(['ultimo_heartbeat_iot' => $recibidoEn]);

            return [
                'punto' => $punto->fresh(),
                'lectura' => null,
                'valida' => false,
                'motivo_invalido' => $invalidaNueva->motivo,
                'duplicada' => false,
                'dispositivo' => $dispositivo->fresh(),
            ];
        }

        $resultado = $this->telemetria->registrarLectura(
            punto: $punto,
            origen: Punto::ORIGEN_SENSOR_IOT,
            distanciaCm: $distancia,
            porcentajeDirecto: $evaluacion['porcentaje'],
            bateriaVoltaje: $bateria,
            medidoEn: $medidoEn,
            lecturaUuid: is_string($uuid) ? $uuid : null,
            dispositivo: $dispositivo,
            rssiDbm: $rssi,
            recibidoEn: $recibidoEn,
        );

        $dispositivo->lecturas_invalidas_seguidas = 0;
        $dispositivo->forceFill(['ultima_distancia_cm' => $distancia]);
        $this->registrarConexion($dispositivo, $datos, $recibidoEn, $medidoEn);

        return [
            'punto' => $resultado['punto'],
            'lectura' => $resultado['lectura'],
            'valida' => true,
            'motivo_invalido' => null,
            'duplicada' => $resultado['duplicada'],
            'dispositivo' => $dispositivo->fresh(),
        ];
    }

    /**
     * Actualiza lo que el sensor informa en cada contacto (conexión, batería, señal, firmware).
     *
     * @param  array<string, mixed>  $datos
     */
    private function registrarConexion(Dispositivo $dispositivo, array $datos, CarbonInterface $recibidoEn, ?CarbonInterface $medidoEn = null): void
    {
        $campos = ['ultima_conexion_en' => $recibidoEn];
        if ($medidoEn !== null && ($dispositivo->ultima_lectura_en === null || $medidoEn->greaterThan($dispositivo->ultima_lectura_en))) {
            $campos['ultima_lectura_en'] = $medidoEn;
        }
        if (isset($datos['bateria_voltaje'])) {
            $campos['bateria_voltaje'] = (float) $datos['bateria_voltaje'];
        }
        if (isset($datos['rssi_dbm'])) {
            $campos['rssi_dbm'] = (int) $datos['rssi_dbm'];
        }
        if (isset($datos['firmware_version'])) {
            $campos['firmware_version'] = (string) $datos['firmware_version'];
        }
        $dispositivo->forceFill($campos);
        $dispositivo->save();
    }

    private function motivoDeError(string $error): string
    {
        return $error === Dispositivo::INVALIDA_SIN_ECO ? Dispositivo::INVALIDA_SIN_ECO : Dispositivo::INVALIDA_ERROR_SENSOR;
    }

    /**
     * Compatibilidad: token por punto anterior a los dispositivos que no se migró.
     *
     * @param  array<string, mixed>  $datos
     * @return array{punto: Punto|null, lectura: LecturaPunto|null, valida: bool, motivo_invalido: string|null, duplicada: bool, dispositivo: Dispositivo|null}
     */
    private function recibirConTokenAntiguo(string $hash, array $datos, CarbonInterface $medidoEn, CarbonInterface $recibidoEn): array
    {
        $punto = Punto::where('sensor_token_hash', $hash)->first();
        if (! $punto instanceof Punto) {
            throw new IotException('Token de sensor inválido.', 401);
        }

        if (! $punto->activo) {
            throw new IotException('El punto asociado a este sensor está inactivo.', 403);
        }

        $resultado = $this->telemetria->registrarLectura(
            punto: $punto,
            origen: Punto::ORIGEN_SENSOR_IOT,
            distanciaCm: isset($datos['distancia_cm']) ? (float) $datos['distancia_cm'] : null,
            porcentajeDirecto: isset($datos['porcentaje_llenado']) ? (int) $datos['porcentaje_llenado'] : null,
            bateriaVoltaje: isset($datos['bateria_voltaje']) ? (float) $datos['bateria_voltaje'] : null,
            medidoEn: $medidoEn,
            lecturaUuid: $datos['lectura_uuid'] ?? null,
            recibidoEn: $recibidoEn,
        );

        return [
            'punto' => $resultado['punto'],
            'lectura' => $resultado['lectura'],
            'valida' => true,
            'motivo_invalido' => null,
            'duplicada' => $resultado['duplicada'],
            'dispositivo' => null,
        ];
    }
}
