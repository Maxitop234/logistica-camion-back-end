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
        Schema::table('choferes', function (Blueprint $table) {
            $table->text('motivo_desactivacion')->nullable();
            $table->timestamp('desactivado_en')->nullable();
            $table->foreignId('desactivado_por_user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->unsignedInteger('ciclo_desactivacion')->default(0);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('choferes', function (Blueprint $table) {
            $table->dropConstrainedForeignId('desactivado_por_user_id');
            $table->dropColumn([
                'motivo_desactivacion',
                'desactivado_en',
                'ciclo_desactivacion',
            ]);
        });
    }
};
