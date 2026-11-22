<?php

namespace App\Console\Commands;

use App\Services\Iot\SimuladorDeSensores;
use Illuminate\Console\Command;

class SimularSensoresCommand extends Command
{
    protected $signature = 'ecoruta:simular-sensores';

    protected $description = 'Completa las lecturas pendientes de los sensores simulados de la demo (solo APP_DEMO_MODE=true).';

    public function handle(SimuladorDeSensores $simulador): int
    {
        if (! config('app.demo_mode')) {
            $this->error('ecoruta:simular-sensores está bloqueado porque APP_DEMO_MODE=false.');

            return self::FAILURE;
        }

        $resultado = $simulador->completarHasta(now(), true);
        $this->info("{$resultado['lecturas']} lecturas enviadas por {$resultado['sensores']} sensores.");

        return self::SUCCESS;
    }
}
