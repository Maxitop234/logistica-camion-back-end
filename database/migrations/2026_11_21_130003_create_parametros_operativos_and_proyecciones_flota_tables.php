<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('parametros_operativos', function (Blueprint $table) {
            $table->id();
            $table->string('clave', 64)->unique();
            $table->decimal('valor', 12, 2);
            $table->string('unidad', 32);
            $table->boolean('es_referencia')->default(true);
            $table->foreignId('actualizado_por_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('proyecciones_flota', function (Blueprint $table) {
            $table->id();
            $table->date('fecha_objetivo')->unique();
            $table->unsignedInteger('puntos_proyectados');
            $table->unsignedInteger('camiones_proyectados');
            $table->unsignedInteger('puntos_reales_listos')->nullable();
            $table->decimal('porcentaje_acierto', 5, 2)->nullable();
            $table->timestamp('calculada_en');
            $table->timestamp('evaluada_en')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('proyecciones_flota');
        Schema::dropIfExists('parametros_operativos');
    }
};
