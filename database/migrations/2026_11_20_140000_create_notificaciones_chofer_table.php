<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notificaciones_chofer', function (Blueprint $table) {
            $table->id();
            $table->foreignId('chofer_id')->constrained('choferes')->cascadeOnDelete();
            $table->string('tipo', 64);
            $table->string('titulo', 160);
            $table->text('mensaje');
            $table->timestamp('leida_en')->nullable();
            $table->timestamps();

            $table->index(['chofer_id', 'leida_en', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notificaciones_chofer');
    }
};
