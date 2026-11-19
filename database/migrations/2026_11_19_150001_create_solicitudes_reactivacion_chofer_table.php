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
        Schema::create('solicitudes_reactivacion_chofer', function (Blueprint $table) {
            $table->id();
            $table->foreignId('chofer_id')->constrained('choferes')->cascadeOnDelete();
            $table->unsignedInteger('ciclo_desactivacion')->default(1);
            $table->text('motivo_desactivacion')->nullable();
            $table->text('motivo');
            $table->string('estado', 32)->default('pendiente')->index();
            $table->foreignId('resuelta_por_user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->timestamp('resuelta_en')->nullable();
            $table->text('motivo_rechazo')->nullable();
            $table->timestamps();

            $table->index(['chofer_id', 'ciclo_desactivacion', 'estado'], 'idx_reactivacion_chofer_ciclo_estado');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('solicitudes_reactivacion_chofer');
    }
};
