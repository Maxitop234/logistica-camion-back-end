<?php

use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\CamionController;
use App\Http\Controllers\Api\V1\ChoferController;
use App\Http\Controllers\Api\V1\DesvioController;
use App\Http\Controllers\Api\V1\DispositivoController;
use App\Http\Controllers\Api\V1\GeocodificacionController;
use App\Http\Controllers\Api\V1\GpsController;
use App\Http\Controllers\Api\V1\IotLecturaController;
use App\Http\Controllers\Api\V1\KpiController;
use App\Http\Controllers\Api\V1\LicenciaChoferController;
use App\Http\Controllers\Api\V1\NotificacionChoferController;
use App\Http\Controllers\Api\V1\NotificacionLicenciaController;
use App\Http\Controllers\Api\V1\PuntoController;
use App\Http\Controllers\Api\V1\ReactivacionChoferController;
use App\Http\Controllers\Api\V1\RutaController;
use App\Http\Controllers\Api\V1\SectorController;
use App\Http\Controllers\Api\V1\SolicitudPuntoController;
use App\Http\Controllers\Api\V1\VinculacionIotController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

Route::prefix('v1')->group(function () {
    Route::prefix('auth')->group(function () {
        Route::post('login', [AuthController::class, 'login']);

        Route::middleware(['auth:sanctum', 'active:permitir_chofer_inactivo'])->group(function () {
            Route::post('logout', [AuthController::class, 'logout']);
            Route::get('me', [AuthController::class, 'me']);
        });
    });

    // Ingesta IoT desde hardware ESP32 autenticado por token de sensor (sin cuenta humana).
    Route::post('iot/lecturas', [IotLecturaController::class, 'store'])
        ->middleware('throttle:120,1');

    // Vinculación del sensor con el código de su etiqueta (anuncio y retiro único del token).
    Route::post('iot/vinculacion/anunciar', [VinculacionIotController::class, 'anunciar'])
        ->middleware('throttle:iot-vinculacion');
    Route::post('iot/vinculacion/estado', [VinculacionIotController::class, 'estado'])
        ->middleware('throttle:iot-vinculacion');

    // Geocodificación inversa (Nominatim) compartida para administrador y chofer activos.
    Route::middleware(['auth:sanctum', 'active', 'role:administrador,chofer'])->group(function () {
        Route::get('geocodificacion/inversa', [GeocodificacionController::class, 'inversa'])
            ->middleware('throttle:60,1');
    });

    // Endpoints restringidos permitidos a un chofer incluso si su cuenta está desactivada.
    Route::middleware(['auth:sanctum', 'active:permitir_chofer_inactivo', 'role:chofer'])->prefix('chofer')->group(function () {
        Route::get('estado-cuenta', [ReactivacionChoferController::class, 'estadoCuenta']);
        Route::post('solicitud-reactivacion', [ReactivacionChoferController::class, 'solicitar']);
        Route::get('notificaciones', [NotificacionChoferController::class, 'index']);
        Route::post('notificaciones/leer-todas', [NotificacionChoferController::class, 'marcarTodasLeidas']);
        Route::post('notificaciones/{notificacion}/leer', [NotificacionChoferController::class, 'marcarLeida']);
    });

    // Endpoints operativos del chofer (bloqueados con 403 si está inactivo, sin revocar token).
    Route::middleware(['auth:sanctum', 'active', 'role:chofer'])->group(function () {
        Route::prefix('chofer')->group(function () {
            Route::get('licencia', [LicenciaChoferController::class, 'miLicencia']);
            Route::post('licencia', [LicenciaChoferController::class, 'subir']);
            Route::get('licencia/archivo', [LicenciaChoferController::class, 'miArchivo']);
            Route::post('licencia/solicitud-borrado', [LicenciaChoferController::class, 'solicitarBorrado']);
            Route::delete('licencia', [LicenciaChoferController::class, 'eliminarPropia']);
            Route::put('avatar', [ChoferController::class, 'actualizarAvatar']);
            Route::get('puntos', [PuntoController::class, 'indexChofer']);
            Route::post('solicitudes-punto', [SolicitudPuntoController::class, 'store']);
            Route::get('ruta-activa', [RutaController::class, 'rutaActiva']);
            Route::get('desvios', [DesvioController::class, 'indexChofer']);
            Route::put('desvios/{desvio}/motivo', [DesvioController::class, 'registrarMotivo']);
        });

        Route::post('rutas/{ruta}/iniciar', [RutaController::class, 'iniciar']);
        Route::post('rutas/{ruta}/sincronizar-lote', [RutaController::class, 'sincronizarLote']);
        Route::post('rutas/{ruta}/finalizar', [RutaController::class, 'finalizar']);
        Route::post('gps/ping', [GpsController::class, 'ping'])->middleware('throttle:120,1');
    });

    Route::middleware(['auth:sanctum', 'active', 'role:administrador'])->group(function () {
        Route::apiResource('camiones', CamionController::class)->parameters(['camiones' => 'camion']);
        Route::apiResource('choferes', ChoferController::class)->parameters(['choferes' => 'chofer']);
        Route::post('choferes/{chofer}/reactivar', [ChoferController::class, 'reactivar']);

        Route::apiResource('sectores', SectorController::class)->parameters(['sectores' => 'sector']);
        Route::apiResource('puntos', PuntoController::class)->parameters(['puntos' => 'punto']);
        Route::post('puntos/{punto}/reactivar', [PuntoController::class, 'reactivar']);
        Route::post('puntos/{punto}/simular-lectura', [PuntoController::class, 'simularLecturaIot'])->middleware('demo');

        // Sensores IoT: vinculación sin manejar tokens, calibración y diagnóstico.
        Route::post('dispositivos/aprobar', [DispositivoController::class, 'aprobar']);
        Route::post('dispositivos/simulador/completar', [DispositivoController::class, 'completarSimulador'])->middleware('demo');
        Route::get('dispositivos', [DispositivoController::class, 'index']);
        Route::get('dispositivos/{dispositivo}', [DispositivoController::class, 'show']);
        Route::patch('dispositivos/{dispositivo}', [DispositivoController::class, 'update']);
        Route::post('dispositivos/{dispositivo}/desvincular', [DispositivoController::class, 'desvincular']);
        Route::post('dispositivos/{dispositivo}/mover', [DispositivoController::class, 'mover']);
        Route::post('dispositivos/{dispositivo}/desactivar', [DispositivoController::class, 'desactivar']);
        Route::post('dispositivos/{dispositivo}/reactivar', [DispositivoController::class, 'reactivar']);
        Route::post('dispositivos/{dispositivo}/rotar-token', [DispositivoController::class, 'rotarToken']);
        Route::post('dispositivos/{dispositivo}/calibrar-vacio', [DispositivoController::class, 'calibrarVacio']);

        Route::get('rutas', [RutaController::class, 'index']);
        Route::post('rutas/generar', [RutaController::class, 'generar']);
        Route::post('rutas/{ruta}/cancelar', [RutaController::class, 'cancelar']);
        Route::get('rutas/{ruta}/traza', [GpsController::class, 'traza']);
        Route::get('gps/flota', [GpsController::class, 'flota']);
        Route::get('rutas/{ruta}/desvios', [DesvioController::class, 'porRuta']);
        Route::get('desvios/calles-problematicas', [DesvioController::class, 'callesProblematicas']);

        Route::get('kpis/dashboard', [KpiController::class, 'dashboard']);
        Route::get('kpis/alertas', [KpiController::class, 'alertas']);
        Route::get('kpis/parametros', [KpiController::class, 'parametros']);
        Route::put('kpis/parametros', [KpiController::class, 'actualizarParametros']);
        Route::get('kpis/exportar', [KpiController::class, 'exportar']);

        Route::get('choferes/{chofer}/licencia/archivo', [LicenciaChoferController::class, 'archivoPorAdmin']);
        Route::post('choferes/{chofer}/licencia/verificar', [LicenciaChoferController::class, 'verificar']);
        Route::post('choferes/{chofer}/licencia/desverificar', [LicenciaChoferController::class, 'desverificar']);
        Route::post('choferes/{chofer}/licencia/rechazar', [LicenciaChoferController::class, 'rechazar']);
        Route::delete('choferes/{chofer}/licencia', [LicenciaChoferController::class, 'destrabarPorAdmin']);

        Route::get('notificaciones/licencias', [NotificacionLicenciaController::class, 'index']);
        Route::post('notificaciones/licencias/{solicitud}/aprobar', [NotificacionLicenciaController::class, 'aprobar']);
        Route::post('notificaciones/licencias/{solicitud}/rechazar', [NotificacionLicenciaController::class, 'rechazar']);

        Route::get('notificaciones/reactivaciones', [ReactivacionChoferController::class, 'listarNotificaciones']);
        Route::post('notificaciones/reactivaciones/{solicitud}/aprobar', [ReactivacionChoferController::class, 'aprobar']);
        Route::post('notificaciones/reactivaciones/{solicitud}/rechazar', [ReactivacionChoferController::class, 'rechazar']);

        Route::get('notificaciones/puntos', [SolicitudPuntoController::class, 'index']);
        Route::post('notificaciones/puntos/{solicitud}/aprobar', [SolicitudPuntoController::class, 'aprobar']);
        Route::post('notificaciones/puntos/{solicitud}/rechazar', [SolicitudPuntoController::class, 'rechazar']);
    });
});
