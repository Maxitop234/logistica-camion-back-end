<?php

use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\CamionController;
use App\Http\Controllers\Api\V1\ChoferController;
use App\Http\Controllers\Api\V1\LicenciaChoferController;
use App\Http\Controllers\Api\V1\NotificacionLicenciaController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

Route::prefix('v1')->group(function () {
    Route::prefix('auth')->group(function () {
        Route::post('login', [AuthController::class, 'login']);

        Route::middleware(['auth:sanctum', 'active'])->group(function () {
            Route::post('logout', [AuthController::class, 'logout']);
            Route::get('me', [AuthController::class, 'me']);
        });
    });

    Route::middleware(['auth:sanctum', 'active', 'role:chofer'])->prefix('chofer')->group(function () {
        Route::get('licencia', [LicenciaChoferController::class, 'miLicencia']);
        Route::post('licencia', [LicenciaChoferController::class, 'subir']);
        Route::get('licencia/archivo', [LicenciaChoferController::class, 'miArchivo']);
        Route::post('licencia/solicitud-borrado', [LicenciaChoferController::class, 'solicitarBorrado']);
        Route::delete('licencia', [LicenciaChoferController::class, 'eliminarPropia']);
    });

    Route::middleware(['auth:sanctum', 'active', 'role:administrador'])->group(function () {
        Route::apiResource('camiones', CamionController::class)->parameters(['camiones' => 'camion']);
        Route::apiResource('choferes', ChoferController::class)->parameters(['choferes' => 'chofer']);

        Route::get('choferes/{chofer}/licencia/archivo', [LicenciaChoferController::class, 'archivoPorAdmin']);
        Route::post('choferes/{chofer}/licencia/verificar', [LicenciaChoferController::class, 'verificar']);
        Route::delete('choferes/{chofer}/licencia', [LicenciaChoferController::class, 'destrabarPorAdmin']);

        Route::get('notificaciones/licencias', [NotificacionLicenciaController::class, 'index']);
        Route::post('notificaciones/licencias/{solicitud}/aprobar', [NotificacionLicenciaController::class, 'aprobar']);
        Route::post('notificaciones/licencias/{solicitud}/rechazar', [NotificacionLicenciaController::class, 'rechazar']);
    });
});
