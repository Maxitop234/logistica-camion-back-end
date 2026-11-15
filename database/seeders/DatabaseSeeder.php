<?php

namespace Database\Seeders;

use App\Models\Administrador;
use App\Models\Camion;
use App\Models\Chofer;
use App\Models\User;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    private const PASSWORD = 'password';

    /**
     * Seed the application's database with sample data (no real Terranova data).
     */
    public function run(): void
    {
        $this->crearAdministrador();

        $choferes = $this->crearChoferes();

        $this->crearCamiones($choferes);
    }

    private function crearAdministrador(): void
    {
        $user = User::updateOrCreate(
            ['email' => 'admin@ecoruta.test'],
            ['name' => 'Administrador', 'password' => self::PASSWORD, 'role' => 'administrador', 'is_active' => true],
        );

        Administrador::updateOrCreate(
            ['user_id' => $user->id],
            ['nombre_completo' => 'Administrador de Ejemplo', 'cargo' => 'Jefe de Operaciones', 'telefono' => '+56911111111'],
        );
    }

    /**
     * @return array<int, Chofer>
     */
    private function crearChoferes(): array
    {
        $datos = [
            ['chofer1@ecoruta.test', 'Pedro Ejemplo Soto', '15.234.567-4', 'A4'],
            ['chofer2@ecoruta.test', 'Camila Ejemplo Rojas', '16.345.678-8', 'A4'],
            ['chofer3@ecoruta.test', 'Luis Ejemplo Muñoz', '17.456.789-1', 'A5'],
            ['chofer4@ecoruta.test', 'Daniela Ejemplo Vega', '18.567.890-3', 'A4'],
        ];

        $choferes = [];
        foreach ($datos as $i => [$email, $nombre, $rut, $licencia]) {
            $user = User::updateOrCreate(
                ['email' => $email],
                ['name' => 'Chofer', 'password' => self::PASSWORD, 'role' => 'chofer', 'is_active' => true],
            );

            $choferes[] = Chofer::updateOrCreate(
                ['user_id' => $user->id],
                [
                    'nombre_cifrado' => $nombre,
                    'rut_hash' => Chofer::hashRut($rut),
                    'licencia_clase' => $licencia,
                    'telefono_cifrado' => '+5692222222'.$i,
                    'disponible' => true,
                ],
            );
        }

        return $choferes;
    }

    /**
     * @param  array<int, Chofer>  $choferes
     */
    private function crearCamiones(array $choferes): void
    {
        $datos = [
            ['AA1111', 'Hino', '500', 2021, 8000, 'disponible'],
            ['BB2222', 'Isuzu', 'NPR', 2020, 5500, 'disponible'],
            ['CC3333', 'Mercedes-Benz', 'Atego', 2022, 10000, 'disponible'],
            ['DD4444', 'Volvo', 'FL', 2019, 9000, 'mantenimiento'],
        ];

        foreach ($datos as $i => [$patente, $marca, $modelo, $anio, $capacidad, $estado]) {
            Camion::updateOrCreate(
                ['patente' => $patente],
                [
                    'marca' => $marca,
                    'modelo' => $modelo,
                    'anio' => $anio,
                    'capacidad_kg' => $capacidad,
                    'estado_operativo' => $estado,
                    'chofer_actual_id' => $estado === 'disponible' ? $choferes[$i]->id : null,
                ],
            );
        }
    }
}
