<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('camiones', function (Blueprint $table) {
            $table->decimal('rendimiento_km_l', 5, 2)->nullable()->after('capacidad_kg');
            $table->unsignedInteger('costo_arriendo_diario')->nullable()->after('rendimiento_km_l');
        });
    }

    public function down(): void
    {
        Schema::table('camiones', function (Blueprint $table) {
            $table->dropColumn(['rendimiento_km_l', 'costo_arriendo_diario']);
        });
    }
};
