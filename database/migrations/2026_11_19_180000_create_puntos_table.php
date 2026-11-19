<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('puntos')) {
            return;
        }

        Schema::create('puntos', function (Blueprint $table) {
            $table->id();
            $table->string('nombre');
            $table->string('direccion');
            $table->string('tipo_punto', 32)->default('punto_calle');
            $table->decimal('latitud', 10, 7);
            $table->decimal('longitud', 10, 7);
            $table->string('estado_llenado', 16)->default('vacio');
            $table->smallInteger('porcentaje_llenado')->default(0);
            $table->decimal('peso_actual_kg', 6, 2)->nullable();
            $table->timestamp('ultima_actualizacion_estado')->nullable();
            $table->boolean('activo')->default(true);
            $table->timestamps();

            $table->index(['activo', 'estado_llenado']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('puntos');
    }
};
