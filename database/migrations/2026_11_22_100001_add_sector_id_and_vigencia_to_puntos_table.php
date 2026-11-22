<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * BE-03: los puntos pasan a pertenecer a un sector real (tabla `sectores`), el
 * estado de llenado admite "nunca medido" (`null`) y se agrega su vigencia
 * (ADR-0013), los campos de importación (BE-04) y la ubicación PostGIS.
 *
 * La columna de texto `sector` (lista cerrada de 5 sectores) se reemplaza por
 * `sector_id`; los puntos que quedan sin sector se marcan para revisión.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('puntos', function (Blueprint $table) {
            $table->dropIndex(['sector']);
            $table->dropColumn('sector');
        });

        Schema::table('puntos', function (Blueprint $table) {
            $table->foreignId('sector_id')
                ->nullable()
                ->after('tipo_punto')
                ->constrained('sectores')
                ->restrictOnDelete();
            $table->string('tipo_material', 16)->nullable()->after('sector_id');
            $table->string('estado_llenado', 16)->nullable()->default(null)->change();
            $table->smallInteger('porcentaje_llenado')->nullable()->default(null)->change();
            $table->timestampTz('estado_vigente_hasta')->nullable()->after('origen_actualizacion');
            $table->string('fuente_ubicacion', 16)->default('manual')->after('longitud');
            $table->boolean('requiere_revision')->default(false)->after('fuente_ubicacion');
            $table->text('nota')->nullable()->after('requiere_revision');
            $table->string('codigo_externo', 64)->nullable()->unique()->after('nota');

            $table->index('sector_id');
        });

        DB::table('puntos')->whereNull('sector_id')->update(['requiere_revision' => true]);

        if (DB::getDriverName() === 'pgsql') {
            Schema::table('puntos', function (Blueprint $table) {
                $table->geography('ubicacion', subtype: 'point', srid: 4326)
                    ->storedAs('ST_SetSRID(ST_MakePoint(longitud::float8, latitud::float8), 4326)::geography')
                    ->after('longitud');
                $table->spatialIndex('ubicacion');
            });
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            Schema::table('puntos', function (Blueprint $table) {
                $table->dropSpatialIndex(['ubicacion']);
                $table->dropColumn('ubicacion');
            });
        }

        DB::table('puntos')->whereNull('estado_llenado')->update(['estado_llenado' => 'vacio']);
        DB::table('puntos')->whereNull('porcentaje_llenado')->update(['porcentaje_llenado' => 0]);

        Schema::table('puntos', function (Blueprint $table) {
            $table->dropIndex(['sector_id']);
            $table->dropConstrainedForeignId('sector_id');
            $table->dropUnique(['codigo_externo']);
            $table->dropColumn([
                'tipo_material',
                'estado_vigente_hasta',
                'fuente_ubicacion',
                'requiere_revision',
                'nota',
                'codigo_externo',
            ]);
            $table->string('estado_llenado', 16)->nullable(false)->default('vacio')->change();
            $table->smallInteger('porcentaje_llenado')->nullable(false)->default(0)->change();
            $table->string('sector', 32)->nullable()->after('tipo_punto');
            $table->index('sector');
        });
    }
};
