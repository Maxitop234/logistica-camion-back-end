<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('camiones', function (Blueprint $table) {
            $table->string('motivo_desactivacion', 255)->nullable()->after('chofer_actual_id');
            $table->timestamp('desactivado_en')->nullable()->after('motivo_desactivacion');
            $table->foreignId('desactivado_por_user_id')
                ->nullable()
                ->after('desactivado_en')
                ->constrained('users')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('camiones', function (Blueprint $table) {
            $table->dropConstrainedForeignId('desactivado_por_user_id');
            $table->dropColumn(['motivo_desactivacion', 'desactivado_en']);
        });
    }
};
