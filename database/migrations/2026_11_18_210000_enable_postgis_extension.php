<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Habilita PostGIS para las columnas geográficas de puntos y rutas (ADR-0003).
     */
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('CREATE EXTENSION IF NOT EXISTS postgis');
    }

    /**
     * La extensión se mantiene al revertir: otras bases o esquemas pueden depender de ella.
     */
    public function down(): void
    {
        //
    }
};
