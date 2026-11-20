<?php

use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\CamionController;
use App\Http\Controllers\Api\V1\ChoferController;
use App\Http\Controllers\Api\V1\LicenciaChoferController;
use App\Http\Controllers\Api\V1\NotificacionLicenciaController;
use App\Http\Controllers\Api\V1\ReactivacionChoferController;
use App\Http\Controllers\Api\V1\RutaController;
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

    // Endpoints restringidos permitidos a un chofer incluso si su cuenta está desactivada.
    Route::middleware(['auth:sanctum', 'active:permitir_chofer_inactivo', 'role:chofer'])->prefix('chofer')->group(function () {
        Route::get('estado-cuenta', [ReactivacionChoferController::class, 'estadoCuenta']);
        Route::post('solicitud-reactivacion', [ReactivacionChoferController::class, 'solicitar']);
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
            Route::get('ruta-activa', [RutaController::class, 'rutaActiva']);
        });

        Route::post('rutas/{ruta}/iniciar', [RutaController::class, 'iniciar']);
        Route::post('rutas/{ruta}/sincronizar-lote', [RutaController::class, 'sincronizarLote']);
        Route::post('rutas/{ruta}/finalizar', [RutaController::class, 'finalizar']);
    });

    Route::middleware(['auth:sanctum', 'active', 'role:administrador'])->group(function () {
        Route::apiResource('camiones', CamionController::class)->parameters(['camiones' => 'camion']);
        Route::apiResource('choferes', ChoferController::class)->parameters(['choferes' => 'chofer']);
        Route::post('choferes/{chofer}/reactivar', [ChoferController::class, 'reactivar']);

        Route::get('rutas', [RutaController::class, 'index']);
        Route::post('rutas/generar', [RutaController::class, 'generar']);

        Route::get('choferes/{chofer}/licencia/archivo', [LicenciaChoferController::class, 'archivoPorAdmin']);
        Route::post('choferes/{chofer}/licencia/verificar', [LicenciaChoferController::class, 'verificar']);
        Route::delete('choferes/{chofer}/licencia', [LicenciaChoferController::class, 'destrabarPorAdmin']);

        Route::get('notificaciones/licencias', [NotificacionLicenciaController::class, 'index']);
        Route::post('notificaciones/licencias/{solicitud}/aprobar', [NotificacionLicenciaController::class, 'aprobar']);
        Route::post('notificaciones/licencias/{solicitud}/rechazar', [NotificacionLicenciaController::class, 'rechazar']);

        Route::get('notificaciones/reactivaciones', [ReactivacionChoferController::class, 'listarNotificaciones']);
        Route::post('notificaciones/reactivaciones/{solicitud}/aprobar', [ReactivacionChoferController::class, 'aprobar']);
        Route::post('notificaciones/reactivaciones/{solicitud}/rechazar', [ReactivacionChoferController::class, 'rechazar']);
    });
});
