<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('choferes', function (Blueprint $table) {
            $table->json('avatar_config')->nullable()->after('ciclo_desactivacion');
        });
    }

    public function down(): void
    {
        Schema::table('choferes', function (Blueprint $table) {
            $table->dropColumn('avatar_config');
        });
    }
};
