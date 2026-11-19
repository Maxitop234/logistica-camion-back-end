<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rutas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('camion_id')->constrained('camiones')->restrictOnDelete();
            $table->foreignId('chofer_id')->constrained('choferes')->restrictOnDelete();
            $table->foreignId('creado_por_admin_id')->nullable()->constrained('administradores')->nullOnDelete();
            $table->date('fecha_programada');
            $table->string('estado', 24)->default('planificada');
            $table->smallInteger('total_puntos_evaluados')->default(0);
            $table->smallInteger('puntos_incluidos_count')->default(0);
            $table->smallInteger('puntos_excluidos_count')->default(0);
            $table->decimal('distancia_estimada_km', 6, 2)->default(0);
            $table->smallInteger('duracion_estimada_min')->default(0);
            $table->text('encoded_polyline')->nullable();
            $table->timestamp('hora_inicio_real')->nullable();
            $table->timestamp('hora_termino_real')->nullable();
            $table->timestamps();

            $table->index(['fecha_programada', 'estado']);
            $table->index('chofer_id');
            $table->index('camion_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rutas');
    }
};
