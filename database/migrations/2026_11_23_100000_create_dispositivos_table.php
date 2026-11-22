<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dispositivos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('punto_id')->nullable()->unique()->constrained('puntos')->nullOnDelete();
            $table->string('codigo_hardware', 64)->unique();
            $table->string('tipo_sensor', 24)->default('jsn_sr04t');
            $table->string('conectividad', 16)->default('wifi');
            $table->string('firmware_version', 32)->nullable();
            $table->decimal('altura_contenedor_cm', 6, 2)->default(120);
            $table->decimal('distancia_lleno_cm', 6, 2)->default(25);
            $table->unsignedInteger('intervalo_lectura_seg')->default(3600);
            $table->string('estado', 16)->default('pendiente')->index();
            $table->string('token_hash', 64)->nullable()->unique();
            $table->text('token_pendiente_cifrado')->nullable();
            $table->timestampTz('token_entregado_en')->nullable();
            $table->timestampTz('anunciado_en')->nullable();
            $table->timestampTz('vinculado_en')->nullable();
            $table->foreignId('aprobado_por_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('ultima_conexion_en')->nullable();
            $table->timestampTz('ultima_lectura_en')->nullable();
            $table->decimal('ultima_distancia_cm', 6, 2)->nullable();
            $table->decimal('bateria_voltaje', 4, 2)->nullable();
            $table->smallInteger('rssi_dbm')->nullable();
            $table->unsignedSmallInteger('lecturas_invalidas_seguidas')->default(0);
            $table->boolean('es_simulado')->default(false);
            $table->text('simulacion')->nullable();
            $table->text('notas')->nullable();
            $table->timestampsTz();
        });

        Schema::create('codigos_vinculacion', function (Blueprint $table) {
            $table->id();
            $table->string('codigo_hardware', 64)->unique();
            $table->string('codigo_hash', 64);
            $table->string('lote', 64)->nullable()->index();
            $table->timestampTz('usado_en')->nullable();
            $table->timestampsTz();
        });

        Schema::create('lecturas_invalidas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('dispositivo_id')->constrained('dispositivos')->cascadeOnDelete();
            $table->foreignId('punto_id')->nullable()->constrained('puntos')->nullOnDelete();
            $table->string('lectura_uuid', 64)->nullable()->unique();
            $table->decimal('distancia_cm', 7, 2)->nullable();
            $table->string('motivo', 24);
            $table->decimal('bateria_voltaje', 4, 2)->nullable();
            $table->smallInteger('rssi_dbm')->nullable();
            $table->timestampTz('medido_en');
            $table->timestampsTz();

            $table->index(['dispositivo_id', 'medido_en']);
        });

        Schema::table('lecturas_puntos', function (Blueprint $table) {
            $table->foreignId('dispositivo_id')->nullable()->after('punto_id')
                ->constrained('dispositivos')->nullOnDelete();
            $table->smallInteger('rssi_dbm')->nullable()->after('bateria_voltaje');
        });

        $this->migrarTokensPorPunto();
    }

    /**
     * Cada punto con sensor pasa a tener un dispositivo propio. El hash del token
     * antiguo se conserva (mismo SHA-256), así que el token ya instalado sigue válido.
     */
    private function migrarTokensPorPunto(): void
    {
        $ahora = now();

        DB::table('puntos')
            ->where(fn ($q) => $q->whereNotNull('sensor_codigo')->orWhereNotNull('sensor_token_hash'))
            ->orderBy('id')
            ->each(function (object $punto) use ($ahora): void {
                $codigo = is_string($punto->sensor_codigo) && trim($punto->sensor_codigo) !== ''
                    ? trim($punto->sensor_codigo)
                    : sprintf('ESP32-P%03d', $punto->id);
                $tieneToken = is_string($punto->sensor_token_hash) && $punto->sensor_token_hash !== '';

                DB::table('dispositivos')->insert([
                    'punto_id' => $punto->id,
                    'codigo_hardware' => $codigo,
                    'tipo_sensor' => 'hc_sr04',
                    'altura_contenedor_cm' => $punto->altura_contenedor_cm,
                    'distancia_lleno_cm' => 0,
                    'estado' => 'activo',
                    'token_hash' => $tieneToken ? $punto->sensor_token_hash : null,
                    'token_entregado_en' => $tieneToken ? $ahora : null,
                    'vinculado_en' => $ahora,
                    'ultima_conexion_en' => $punto->ultimo_heartbeat_iot,
                    'ultima_lectura_en' => $punto->ultimo_heartbeat_iot,
                    'bateria_voltaje' => $punto->bateria_voltaje,
                    'created_at' => $ahora,
                    'updated_at' => $ahora,
                ]);
            });
    }

    public function down(): void
    {
        Schema::table('lecturas_puntos', function (Blueprint $table) {
            $table->dropConstrainedForeignId('dispositivo_id');
            $table->dropColumn('rssi_dbm');
        });
        Schema::dropIfExists('lecturas_invalidas');
        Schema::dropIfExists('codigos_vinculacion');
        Schema::dropIfExists('dispositivos');
    }
};
