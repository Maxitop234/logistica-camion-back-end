<?php

namespace App\Console\Commands;

use App\Models\AuditLog;
use App\Models\User;
use App\Services\Cuentas\GestorDeCuentas;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

use function Laravel\Prompts\text;

class CrearAdminCommand extends Command
{
    protected $signature = 'ecoruta:crear-admin';

    protected $description = 'Crea un administrador (solo ambiente real) con contraseña temporal y cambio obligatorio.';

    public function handle(GestorDeCuentas $cuentas): int
    {
        if (config('app.demo_mode')) {
            $this->error('Con APP_DEMO_MODE=true no se crean administradores: la demostración usa una cuenta fija sembrada por DemoSeeder y no debe tener cuentas reales.');

            return self::FAILURE;
        }

        $nombre = text('Nombre completo', required: true);
        $email = strtolower(trim(text('Correo de acceso', required: true, validate: fn (string $valor) => Validator::make(
            ['email' => strtolower(trim($valor))],
            ['email' => ['email', 'max:255', 'unique:users,email']],
        )->errors()->first('email') ?: null)));
        $cargo = text('Cargo (opcional)') ?: null;

        $temporal = $cuentas->generarPassword();

        $user = DB::transaction(function () use ($nombre, $email, $cargo, $temporal): User {
            $user = User::create([
                'name' => $nombre,
                'email' => $email,
                'password' => $temporal,
                'role' => 'administrador',
                'is_active' => true,
                'debe_cambiar_password' => true,
                'password_cambiada_en' => now(),
            ]);
            $user->administrador()->create(['nombre_completo' => $nombre, 'cargo' => $cargo]);

            return $user;
        });

        AuditLog::create(['user_id' => $user->id, 'accion' => 'administrador_creado_por_comando']);

        $this->info("Administrador creado. Contraseña temporal (debe cambiarla al ingresar):\n");
        $this->line($temporal);

        return self::SUCCESS;
    }
}
