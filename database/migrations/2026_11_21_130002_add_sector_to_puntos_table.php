<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('puntos', function (Blueprint $table) {
            $table->string('sector', 32)->nullable()->after('tipo_punto');
            $table->index('sector');
        });
    }

    public function down(): void
    {
        Schema::table('puntos', function (Blueprint $table) {
            $table->dropIndex(['sector']);
            $table->dropColumn('sector');
        });
    }
};
