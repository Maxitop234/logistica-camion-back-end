<?php

namespace App\Services\Iot;

use App\Models\AuditLog;
use App\Models\CodigoVinculacion;
use App\Models\Dispositivo;
use App\Models\Punto;
use App\Models\User;
use App\Services\Puntos\AsignadorDeSector;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Ciclo de vida de un sensor: etiqueta, anuncio, aprobación del administrador,
 * entrega única del token y operaciones posteriores. Nadie maneja tokens a mano.
 */
class VinculacionDeDispositivos
{
    private const ALFABETO = '23456789ABCDEFGHJKMNPQRSTUVWXYZ';

    public function __construct(private readonly AsignadorDeSector $asignador) {}

    /**
     * Crea códigos de etiqueta por lote. El código secreto solo se devuelve aquí.
     *
     * @return list<array{codigo_hardware: string, codigo: string}>
     */
    public function generarCodigos(int $cantidad, ?string $lote = null, ?string $prefijo = null): array
    {
        $prefijo = strtoupper($prefijo ?? (string) config('iot.vinculacion.prefijo_hardware', 'ECO'));
        $siguiente = (int) CodigoVinculacion::query()->where('codigo_hardware', 'like', $prefijo.'-%')->count() + 1;
        $salida = [];

        for ($i = 0; $i < $cantidad; $i++) {
            do {
                $hardware = sprintf('%s-%04d', $prefijo, $siguiente++);
            } while (CodigoVinculacion::where('codigo_hardware', $hardware)->exists());

            $salida[] = ['codigo_hardware' => $hardware, 'codigo' => $this->registrarCodigo($hardware, $lote)];
        }

        return $salida;
    }

    /**
     * Registra la etiqueta de un hardware y devuelve su código secreto (solo se ve aquí).
     */
    public function registrarCodigo(string $codigoHardware, ?string $lote = null): string
    {
        $codigo = $this->codigoAleatorio();
        CodigoVinculacion::create([
            'codigo_hardware' => $codigoHardware,
            'codigo_hash' => CodigoVinculacion::hashDe($codigo),
            'lote' => $lote,
        ]);

        return $codigo;
    }

    /**
     * Código de 12 caracteres sin ambiguos (60 bits), con formato XXXX-XXXX-XXXX.
     */
    private function codigoAleatorio(): string
    {
        $texto = '';
        for ($i = 0; $i < 12; $i++) {
            $texto .= self::ALFABETO[random_int(0, strlen(self::ALFABETO) - 1)];
        }

        return implode('-', str_split($texto, 4));
    }

    /**
     * @throws IotException
     */
    private function verificarCodigo(string $codigoHardware, string $codigo): CodigoVinculacion
    {
        $registro = CodigoVinculacion::where('codigo_hardware', trim($codigoHardware))->first();
        // Mismo mensaje si el hardware no existe o el código no coincide: no revela cuál falló.
        if (! $registro instanceof CodigoVinculacion || ! $registro->coincide($codigo)) {
            throw new IotException('Código de vinculación inválido.', 403);
        }

        return $registro;
    }

    /**
     * Un sensor nuevo se anuncia y queda pendiente de aprobación.
     *
     * @param  array<string, mixed>  $datos  `codigo_hardware`, `codigo_vinculacion` y, opcionales, `firmware_version`, `conectividad` y `tipo_sensor`.
     * @return array{dispositivo: Dispositivo, creado: bool}
     *
     * @throws IotException
     */
    public function anunciar(array $datos): array
    {
        $registro = $this->verificarCodigo((string) $datos['codigo_hardware'], (string) $datos['codigo_vinculacion']);
        $dispositivo = Dispositivo::where('codigo_hardware', $registro->codigo_hardware)->first();

        if ($dispositivo instanceof Dispositivo && $dispositivo->estado === Dispositivo::ESTADO_DESACTIVADO) {
            throw new IotException('El sensor está desactivado.', 403);
        }
        if ($dispositivo instanceof Dispositivo && $dispositivo->estado === Dispositivo::ESTADO_ACTIVO) {
            throw new IotException('El sensor ya está vinculado. Pide al administrador rotar su token.', 409);
        }

        $tipo = in_array($datos['tipo_sensor'] ?? null, Dispositivo::TIPOS_SENSOR, true)
            ? $datos['tipo_sensor']
            : Dispositivo::TIPO_JSN_SR04T;
        $conectividad = in_array($datos['conectividad'] ?? null, Dispositivo::CONECTIVIDADES, true)
            ? $datos['conectividad']
            : 'wifi';

        $creado = ! $dispositivo instanceof Dispositivo;
        $dispositivo ??= new Dispositivo([
            'codigo_hardware' => $registro->codigo_hardware,
            'tipo_sensor' => $tipo,
            'distancia_lleno_cm' => (float) config("iot.sensores.{$tipo}.distancia_lleno_cm", 25),
            'intervalo_lectura_seg' => (int) config('iot.intervalo_defecto_seg', 3600),
        ]);
        $dispositivo->fill([
            'conectividad' => $conectividad,
            'firmware_version' => $datos['firmware_version'] ?? $dispositivo->firmware_version,
            'estado' => Dispositivo::ESTADO_PENDIENTE,
            'anunciado_en' => now(),
            'ultima_conexion_en' => now(),
        ])->save();

        $registro->update(['usado_en' => $registro->usado_en ?? now()]);

        return ['dispositivo' => $dispositivo, 'creado' => $creado];
    }

    /**
     * Consulta del sensor. Cuando fue aprobado recibe su token una sola vez.
     *
     * @return array{estado: string, token: string|null, intervalo_lectura_seg: int}
     *
     * @throws IotException
     */
    public function consultarEstado(string $codigoHardware, string $codigo): array
    {
        $registro = $this->verificarCodigo($codigoHardware, $codigo);
        $dispositivo = Dispositivo::where('codigo_hardware', $registro->codigo_hardware)->first();
        if (! $dispositivo instanceof Dispositivo) {
            throw new IotException('El sensor aún no se anunció.', 404);
        }

        $token = null;
        DB::transaction(function () use ($dispositivo, &$token): void {
            $dispositivo = Dispositivo::whereKey($dispositivo->id)->lockForUpdate()->firstOrFail();
            if ($dispositivo->estado === Dispositivo::ESTADO_ACTIVO && $dispositivo->token_pendiente_cifrado !== null) {
                $token = $dispositivo->token_pendiente_cifrado;
                $dispositivo->token_pendiente_cifrado = null;
                $dispositivo->forceFill(['token_entregado_en' => now()]);
            }
            $dispositivo->forceFill(['ultima_conexion_en' => now()])->save();
        });

        return [
            'estado' => $dispositivo->estado,
            'token' => $token,
            'intervalo_lectura_seg' => $dispositivo->intervalo_lectura_seg,
        ];
    }

    /**
     * Aprueba un sensor anunciado y lo asigna a un punto existente o nuevo.
     *
     * @param  Punto|array<string, mixed>  $punto  Punto existente o datos para crearlo.
     *
     * @throws IotException
     * @throws ValidationException
     */
    public function aprobar(string $codigoHardware, string $codigo, Punto|array $punto, User $admin): Dispositivo
    {
        $registro = $this->verificarCodigo($codigoHardware, $codigo);
        $dispositivo = Dispositivo::where('codigo_hardware', $registro->codigo_hardware)
            ->where('estado', Dispositivo::ESTADO_PENDIENTE)
            ->first();
        if (! $dispositivo instanceof Dispositivo) {
            throw new IotException('Ese sensor no está esperando aprobación. Debe anunciarse primero.', 404);
        }

        return DB::transaction(function () use ($dispositivo, $punto, $admin): Dispositivo {
            $puntoFinal = $punto instanceof Punto ? $punto : $this->crearPunto($punto);
            $this->asegurarPuntoLibre($puntoFinal, $dispositivo);

            $token = Dispositivo::nuevoToken();
            $dispositivo->fill([
                'estado' => Dispositivo::ESTADO_ACTIVO,
                'punto_id' => $puntoFinal->id,
                'token_hash' => Dispositivo::hashToken($token),
                'token_pendiente_cifrado' => $token,
                'token_entregado_en' => null,
                'vinculado_en' => now(),
                'aprobado_por_user_id' => $admin->id,
            ])->save();
            $this->reflejarEnPunto($dispositivo, $puntoFinal);

            $this->auditar('dispositivo_vinculado', $admin, $dispositivo, ['punto_id' => $puntoFinal->id]);

            return $dispositivo;
        });
    }

    /**
     * @param  array<string, mixed>  $datos
     */
    private function crearPunto(array $datos): Punto
    {
        $sectorId = $datos['sector_id'] ?? null;
        $sugerido = $sectorId === null;
        if ($sugerido) {
            $sectorId = $this->asignador->sectorMasCercano((float) $datos['latitud'], (float) $datos['longitud'])?->id;
        }

        return Punto::create([
            'nombre' => $datos['nombre'],
            'nombre_automatico' => false,
            'direccion' => $datos['direccion'],
            'tipo_punto' => $datos['tipo_punto'],
            'sector_id' => $sectorId,
            'latitud' => $datos['latitud'],
            'longitud' => $datos['longitud'],
            'fuente_ubicacion' => Punto::FUENTE_MANUAL,
            'requiere_revision' => $sugerido,
            'origen_actualizacion' => Punto::ORIGEN_MANUAL,
            'activo' => true,
        ]);
    }

    /**
     * @throws ValidationException
     */
    private function asegurarPuntoLibre(Punto $punto, Dispositivo $dispositivo): void
    {
        $ocupado = Dispositivo::where('punto_id', $punto->id)->whereKeyNot($dispositivo->id)->exists();
        if ($ocupado || ! $punto->activo) {
            throw ValidationException::withMessages([
                'punto_id' => $ocupado
                    ? 'Ese punto ya tiene un sensor asignado.'
                    : 'El punto está inactivo.',
            ]);
        }
    }

    /**
     * Mantiene en el punto los datos que leen KPI y mapas (código, calibración, token antiguo).
     */
    private function reflejarEnPunto(Dispositivo $dispositivo, ?Punto $punto): void
    {
        if (! $punto instanceof Punto) {
            return;
        }

        $punto->forceFill([
            'sensor_codigo' => $dispositivo->codigo_hardware,
            'sensor_token_hash' => null,
            'altura_contenedor_cm' => $dispositivo->altura_contenedor_cm,
            'bateria_voltaje' => $dispositivo->bateria_voltaje,
            'ultimo_heartbeat_iot' => $dispositivo->ultima_conexion_en,
        ])->save();
    }

    private function liberarPunto(Dispositivo $dispositivo): void
    {
        $punto = $dispositivo->punto;
        if ($punto instanceof Punto) {
            $punto->forceFill(['sensor_codigo' => null, 'sensor_token_hash' => null, 'bateria_voltaje' => null, 'ultimo_heartbeat_iot' => null])->save();
        }
    }

    public function desvincular(Dispositivo $dispositivo, User $admin): Dispositivo
    {
        return DB::transaction(function () use ($dispositivo, $admin): Dispositivo {
            $anterior = $dispositivo->punto_id;
            $this->liberarPunto($dispositivo);
            $dispositivo->update(['punto_id' => null]);
            $this->auditar('dispositivo_desvinculado', $admin, $dispositivo, ['punto_id' => $anterior]);

            return $dispositivo->refresh();
        });
    }

    /**
     * @throws ValidationException
     */
    public function mover(Dispositivo $dispositivo, Punto $destino, User $admin): Dispositivo
    {
        return DB::transaction(function () use ($dispositivo, $destino, $admin): Dispositivo {
            $this->asegurarPuntoLibre($destino, $dispositivo);
            $anterior = $dispositivo->punto_id;
            $this->liberarPunto($dispositivo);
            $dispositivo->update(['punto_id' => $destino->id]);
            $this->reflejarEnPunto($dispositivo, $destino);
            $this->auditar('dispositivo_movido', $admin, $dispositivo, ['punto_anterior_id' => $anterior, 'punto_id' => $destino->id]);

            return $dispositivo->refresh();
        });
    }

    public function desactivar(Dispositivo $dispositivo, User $admin): Dispositivo
    {
        return DB::transaction(function () use ($dispositivo, $admin): Dispositivo {
            $dispositivo->update([
                'estado' => Dispositivo::ESTADO_DESACTIVADO,
                'token_hash' => null,
                'token_pendiente_cifrado' => null,
            ]);
            $this->auditar('dispositivo_desactivado', $admin, $dispositivo);

            return $dispositivo;
        });
    }

    /**
     * Vuelve a activar un sensor desactivado: genera un token nuevo que el sensor retira solo.
     */
    public function reactivar(Dispositivo $dispositivo, User $admin): Dispositivo
    {
        if ($dispositivo->estado !== Dispositivo::ESTADO_DESACTIVADO) {
            throw new IotException('El sensor no está desactivado.', 409);
        }

        $this->emitirToken($dispositivo);
        $dispositivo->update(['estado' => Dispositivo::ESTADO_ACTIVO]);
        $this->auditar('dispositivo_reactivado', $admin, $dispositivo);

        return $dispositivo;
    }

    /**
     * Invalida el token vigente y deja uno nuevo para que el sensor lo retire una vez.
     */
    public function rotarToken(Dispositivo $dispositivo, User $admin): Dispositivo
    {
        if ($dispositivo->estado !== Dispositivo::ESTADO_ACTIVO) {
            throw new IotException('Solo se puede rotar el token de un sensor activo.', 409);
        }

        $this->emitirToken($dispositivo);
        $this->auditar('token_iot_rotado', $admin, $dispositivo);

        return $dispositivo;
    }

    private function emitirToken(Dispositivo $dispositivo): void
    {
        $token = Dispositivo::nuevoToken();
        $dispositivo->update([
            'token_hash' => Dispositivo::hashToken($token),
            'token_pendiente_cifrado' => $token,
            'token_entregado_en' => null,
        ]);
    }

    /**
     * Usa la última distancia medida como contenedor vacío (0 %).
     *
     * @throws IotException
     */
    public function calibrarVacio(Dispositivo $dispositivo, User $admin): Dispositivo
    {
        $distancia = $dispositivo->ultima_distancia_cm !== null ? (float) $dispositivo->ultima_distancia_cm : null;
        if ($distancia === null) {
            throw new IotException('El sensor aún no tiene una lectura válida para calibrar.', 422);
        }
        if ($distancia < $dispositivo->zonaCiegaCm() || $distancia <= (float) $dispositivo->distancia_lleno_cm) {
            throw new IotException('La última lectura queda dentro de la zona ciega: no sirve como vacío.', 422);
        }

        return DB::transaction(function () use ($dispositivo, $distancia, $admin): Dispositivo {
            $anterior = (float) $dispositivo->altura_contenedor_cm;
            $dispositivo->update(['altura_contenedor_cm' => $distancia]);
            $this->recalcularPunto($dispositivo, $distancia);
            $this->auditar('dispositivo_calibrado', $admin, $dispositivo, [
                'altura_anterior_cm' => $anterior,
                'altura_nueva_cm' => $distancia,
            ]);

            return $dispositivo->refresh();
        });
    }

    /**
     * Cambia la calibración y reevalúa el estado del punto con la última lectura.
     */
    public function recalcularPunto(Dispositivo $dispositivo, ?float $distancia = null): void
    {
        $punto = $dispositivo->punto;
        $distancia ??= $dispositivo->ultima_distancia_cm !== null ? (float) $dispositivo->ultima_distancia_cm : null;
        if (! $punto instanceof Punto) {
            return;
        }

        $punto->altura_contenedor_cm = $dispositivo->altura_contenedor_cm;
        if ($distancia !== null && $punto->origen_actualizacion === Punto::ORIGEN_SENSOR_IOT) {
            $evaluacion = $dispositivo->evaluarDistancia($distancia);
            if ($evaluacion['valida'] && $evaluacion['porcentaje'] !== null) {
                $punto->porcentaje_llenado = $evaluacion['porcentaje'];
                $punto->estado_llenado = Punto::clasificarEstado($evaluacion['porcentaje']);
            }
        }
        $punto->save();
    }

    /**
     * @param  array<string, mixed>  $detalles
     */
    private function auditar(string $accion, User $admin, Dispositivo $dispositivo, array $detalles = []): void
    {
        AuditLog::registrar($accion, $admin, request(), ['dispositivo_id' => $dispositivo->id, 'codigo_hardware' => $dispositivo->codigo_hardware, ...$detalles]);
    }
}
