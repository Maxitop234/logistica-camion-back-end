<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('desvios_ruta', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ruta_id')->constrained('rutas')->cascadeOnDelete();
            $table->foreignId('chofer_id')->constrained('choferes')->cascadeOnDelete();
            $table->foreignId('camion_id')->constrained('camiones')->cascadeOnDelete();
            $table->timestampTz('inicio_en');
            $table->timestampTz('fin_en');
            $table->unsignedInteger('distancia_m');
            $table->unsignedInteger('max_separacion_m');
            // Polilíneas codificadas (precisión 1e5): lo que hizo el camión y lo planificado en ese tramo.
            $table->text('geometria_real');
            $table->text('geometria_planificada')->nullable();
            // Celdas (~100 m) del inicio y fin sobre la ruta planificada: agrupa desvíos repetidos.
            $table->string('clave_tramo', 60)->index();
            $table->decimal('centro_latitud', 10, 7);
            $table->decimal('centro_longitud', 10, 7);
            // Motivo opcional que informa el chofer.
            $table->string('motivo_codigo', 40)->nullable();
            $table->string('motivo_texto', 300)->nullable();
            $table->timestampTz('revisado_en')->nullable();
            $table->timestampsTz();

            $table->unique(['ruta_id', 'inicio_en']);
            $table->index(['chofer_id', 'revisado_en']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('desvios_ruta');
    }
};
