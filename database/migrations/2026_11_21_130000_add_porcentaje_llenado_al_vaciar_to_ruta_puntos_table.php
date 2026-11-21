<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ruta_puntos', function (Blueprint $table) {
            $table->smallInteger('porcentaje_llenado_al_vaciar')
                ->nullable()
                ->after('estado_llenado_inicial');
        });
    }

    public function down(): void
    {
        Schema::table('ruta_puntos', function (Blueprint $table) {
            $table->dropColumn('porcentaje_llenado_al_vaciar');
        });
    }
};
