<?php

namespace App\Console\Commands;

use App\Models\AuditLog;
use App\Models\User;
use App\Services\Cuentas\GestorDeCuentas;
use App\Services\Cuentas\VerificadorDeDosPasos;
use Illuminate\Console\Command;

class RestablecerAdminCommand extends Command
{
    protected $signature = 'ecoruta:restablecer-admin
        {email : Correo del administrador}
        {--sin-2fa : Desactiva también su verificación en dos pasos}';

    protected $description = 'Restablece la contraseña de un administrador con una temporal y cambio obligatorio. Solo con acceso al servidor.';

    private function stringArgument(string $nombre): string
    {
        $valor = $this->argument($nombre);

        return is_string($valor) ? $valor : '';
    }

    public function handle(GestorDeCuentas $cuentas, VerificadorDeDosPasos $dosPasos): int
    {
        $user = User::where('email', strtolower(trim($this->stringArgument('email'))))
            ->where('role', 'administrador')
            ->first();

        if (! $user) {
            $this->error('No existe un administrador con ese correo.');

            return self::FAILURE;
        }

        if ($user->esCuentaDemoProtegida()) {
            $this->error(GestorDeCuentas::MENSAJE_DEMO.' Esta cuenta de la demostración tiene credenciales fijas.');

            return self::FAILURE;
        }

        $temporal = $cuentas->generarPassword();
        $cuentas->fijarPassword($user, $temporal, true);
        if ($this->option('sin-2fa')) {
            $dosPasos->desactivar($user);
        }

        AuditLog::create([
            'user_id' => $user->id,
            'accion' => 'password_restablecida_por_comando',
            'detalles' => ['sin_2fa' => (bool) $this->option('sin-2fa')],
        ]);

        $this->info('Contraseña temporal (se pedirá cambiarla al ingresar y se cerraron sus sesiones):');
        $this->line($temporal);

        return self::SUCCESS;
    }
}
