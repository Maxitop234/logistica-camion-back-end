<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lecturas_puntos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('punto_id')->constrained('puntos')->cascadeOnDelete();
            $table->string('lectura_uuid', 64)->nullable()->unique();
            $table->string('origen', 24)->default('manual');
            $table->decimal('distancia_cm', 6, 2)->nullable();
            $table->smallInteger('porcentaje_llenado');
            $table->string('estado_llenado', 16);
            $table->decimal('bateria_voltaje', 4, 2)->nullable();
            $table->foreignId('registrada_por_user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->timestamp('medido_en')->nullable();
            $table->timestamps();

            $table->index(['punto_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lecturas_puntos');
    }
};
