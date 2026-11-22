<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // La cuenta debe cambiar su contraseña antes de usar la app.
            $table->boolean('debe_cambiar_password')->default(false)->after('is_active');
            // Cuenta sembrada por DemoSeeder: no puede cambiar contraseña ni activar 2FA en la demo.
            $table->boolean('cuenta_protegida')->default(false)->after('debe_cambiar_password');
            $table->timestampTz('password_cambiada_en')->nullable()->after('cuenta_protegida');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['debe_cambiar_password', 'cuenta_protegida', 'password_cambiada_en']);
        });
    }
};
