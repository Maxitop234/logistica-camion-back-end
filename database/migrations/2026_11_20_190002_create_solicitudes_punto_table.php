<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('solicitudes_punto', function (Blueprint $table) {
            $table->id();
            $table->foreignId('chofer_id')->constrained('choferes')->cascadeOnDelete();
            $table->string('nombre');
            $table->string('direccion');
            $table->string('tipo_punto', 32)->default('calle');
            $table->decimal('latitud', 10, 7);
            $table->decimal('longitud', 10, 7);
            $table->string('observacion', 500)->nullable();
            $table->string('estado', 24)->default('pendiente')->index();
            $table->foreignId('punto_creado_id')
                ->nullable()
                ->constrained('puntos')
                ->nullOnDelete();
            $table->foreignId('resuelta_por_user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->timestamp('resuelta_en')->nullable();
            $table->string('motivo_rechazo', 500)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('solicitudes_punto');
    }
};
