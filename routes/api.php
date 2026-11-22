<?php

use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\CamionController;
use App\Http\Controllers\Api\V1\ChoferController;
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
        Route::post('puntos/{punto}/sensor-token', [PuntoController::class, 'generarTokenSensor']);
        Route::post('puntos/{punto}/simular-lectura', [PuntoController::class, 'simularLecturaIot']);

        Route::get('rutas', [RutaController::class, 'index']);
        Route::post('rutas/generar', [RutaController::class, 'generar']);
        Route::get('rutas/{ruta}/traza', [GpsController::class, 'traza']);
        Route::get('gps/flota', [GpsController::class, 'flota']);

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
