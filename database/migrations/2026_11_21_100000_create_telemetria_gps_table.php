<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('telemetria_gps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ruta_id')->constrained('rutas')->cascadeOnDelete();
            $table->foreignId('camion_id')->constrained('camiones')->cascadeOnDelete();
            $table->foreignId('chofer_id')->constrained('choferes')->cascadeOnDelete();
            $table->uuid('ping_uuid')->unique();
            $table->decimal('latitud', 10, 7);
            $table->decimal('longitud', 10, 7);
            $table->decimal('velocidad_kmh', 6, 2)->default(0);
            $table->decimal('precision_m', 6, 2)->nullable();
            $table->boolean('es_simulado')->default(false);
            $table->timestamp('capturado_en');
            $table->timestamps();

            $table->index(['ruta_id', 'capturado_en']);
            $table->index(['camion_id', 'capturado_en']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('telemetria_gps');
    }
};
