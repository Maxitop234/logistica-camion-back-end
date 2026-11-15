<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('camiones', function (Blueprint $table) {
            $table->id();
            $table->string('patente', 10)->unique();
            $table->string('marca');
            $table->string('modelo');
            $table->smallInteger('anio');
            $table->decimal('capacidad_kg', 8, 2);
            $table->enum('estado_operativo', ['disponible', 'en_ruta', 'mantenimiento', 'inactivo'])->default('disponible')->index();
            $table->foreignId('chofer_actual_id')->nullable()->constrained('choferes')->nullOnDelete();
            $table->decimal('ultima_latitud', 10, 7)->nullable();
            $table->decimal('ultima_longitud', 10, 7)->nullable();
            $table->decimal('velocidad_actual_kmh', 5, 2)->default(0);
            $table->timestamp('ultima_actualizacion_gps')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('camiones');
    }
};
