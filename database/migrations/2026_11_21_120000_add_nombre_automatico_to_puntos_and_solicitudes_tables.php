<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('puntos', function (Blueprint $table) {
            // Los puntos ya existentes conservan nombres escritos a mano (`false`).
            $table->boolean('nombre_automatico')->default(false)->after('nombre');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE puntos ALTER COLUMN nombre_automatico SET DEFAULT true');
        }

        Schema::table('solicitudes_punto', function (Blueprint $table) {
            $table->boolean('nombre_automatico')->default(true)->after('nombre');
        });
    }

    public function down(): void
    {
        Schema::table('solicitudes_punto', function (Blueprint $table) {
            $table->dropColumn('nombre_automatico');
        });

        Schema::table('puntos', function (Blueprint $table) {
            $table->dropColumn('nombre_automatico');
        });
    }
};
