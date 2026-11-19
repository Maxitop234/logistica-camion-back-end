<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ruta_puntos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ruta_id')->constrained('rutas')->cascadeOnDelete();
            $table->foreignId('punto_id')->constrained('puntos')->restrictOnDelete();
            $table->smallInteger('orden_parada');
            $table->string('estado_llenado_inicial', 16);
            $table->string('estado_parada', 24)->default('pendiente');
            $table->decimal('distancia_tramo_km', 6, 2)->nullable();
            $table->smallInteger('duracion_tramo_min')->nullable();
            $table->timestamp('hora_estimada_llegada')->nullable();
            $table->timestamp('hora_real_recoleccion')->nullable();
            $table->uuid('sync_uuid')->nullable()->unique();
            $table->boolean('sincronizado_offline')->default(false);
            $table->text('observaciones')->nullable();
            $table->timestamps();

            $table->unique(['ruta_id', 'orden_parada']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ruta_puntos');
    }
};
