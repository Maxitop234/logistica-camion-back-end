<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('choferes', function (Blueprint $table) {
            $table->string('licencia_motivo_rechazo', 255)->nullable()->after('licencia_borrado_habilitado');
            $table->foreignId('licencia_rechazada_por_user_id')
                ->nullable()
                ->after('licencia_motivo_rechazo')
                ->constrained('users')
                ->nullOnDelete();
            $table->timestamp('licencia_rechazada_en')->nullable()->after('licencia_rechazada_por_user_id');
        });
    }

    public function down(): void
    {
        Schema::table('choferes', function (Blueprint $table) {
            $table->dropConstrainedForeignId('licencia_rechazada_por_user_id');
            $table->dropColumn(['licencia_motivo_rechazo', 'licencia_rechazada_en']);
        });
    }
};
