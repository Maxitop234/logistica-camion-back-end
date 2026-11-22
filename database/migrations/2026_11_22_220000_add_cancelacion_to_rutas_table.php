<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rutas', function (Blueprint $table) {
            $table->text('motivo_cancelacion')->nullable();
            $table->timestampTz('cancelada_en')->nullable();
            $table->foreignId('cancelada_por_admin_id')->nullable()->constrained('administradores')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('rutas', function (Blueprint $table) {
            $table->dropConstrainedForeignId('cancelada_por_admin_id');
            $table->dropColumn(['motivo_cancelacion', 'cancelada_en']);
        });
    }
};
