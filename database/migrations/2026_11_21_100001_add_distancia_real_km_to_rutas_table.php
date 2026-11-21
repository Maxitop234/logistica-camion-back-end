<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rutas', function (Blueprint $table) {
            $table->decimal('distancia_real_km', 8, 2)->default(0)->after('distancia_estimada_km');
        });
    }

    public function down(): void
    {
        Schema::table('rutas', function (Blueprint $table) {
            $table->dropColumn('distancia_real_km');
        });
    }
};
