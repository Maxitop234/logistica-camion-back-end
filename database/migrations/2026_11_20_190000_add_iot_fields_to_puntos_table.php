<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('puntos', function (Blueprint $table) {
            $table->string('origen_actualizacion', 24)->default('manual')->after('ultima_actualizacion_estado');
            $table->decimal('altura_contenedor_cm', 6, 2)->default(120.00)->after('origen_actualizacion');
            $table->string('sensor_codigo', 64)->nullable()->unique()->after('altura_contenedor_cm');
            $table->string('sensor_token_hash', 64)->nullable()->index()->after('sensor_codigo');
            $table->decimal('bateria_voltaje', 4, 2)->nullable()->after('sensor_token_hash');
            $table->timestamp('ultimo_heartbeat_iot')->nullable()->after('bateria_voltaje');
        });

        DB::table('puntos')
            ->where('tipo_punto', '!=', 'condominio')
            ->update(['tipo_punto' => 'calle']);
    }

    public function down(): void
    {
        Schema::table('puntos', function (Blueprint $table) {
            $table->dropUnique(['sensor_codigo']);
            $table->dropIndex(['sensor_token_hash']);
            $table->dropColumn([
                'origen_actualizacion',
                'altura_contenedor_cm',
                'sensor_codigo',
                'sensor_token_hash',
                'bateria_voltaje',
                'ultimo_heartbeat_iot',
            ]);
        });
    }
};
