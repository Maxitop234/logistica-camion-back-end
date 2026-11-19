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
            $table->string('licencia_estado', 32)->default('pendiente')->index();
            $table->string('licencia_archivo_path')->nullable();
            $table->string('licencia_archivo_nombre')->nullable();
            $table->string('licencia_archivo_sha256', 64)->nullable();
            $table->timestamp('licencia_subida_en')->nullable();
            $table->foreignId('licencia_verificada_por_user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->timestamp('licencia_verificada_en')->nullable();
            $table->boolean('licencia_borrado_habilitado')->default(false);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('choferes', function (Blueprint $table) {
            $table->dropConstrainedForeignId('licencia_verificada_por_user_id');
            $table->dropIndex(['licencia_estado']);
            $table->dropColumn([
                'licencia_estado',
                'licencia_archivo_path',
                'licencia_archivo_nombre',
                'licencia_archivo_sha256',
                'licencia_subida_en',
                'licencia_verificada_en',
                'licencia_borrado_habilitado',
            ]);
        });
    }
};
