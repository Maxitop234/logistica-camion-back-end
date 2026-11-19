<?php

namespace Database\Seeders;

use App\Models\Administrador;
use App\Models\Camion;
use App\Models\Chofer;
use App\Models\Punto;
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

        $this->crearPuntos();
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

    private function crearPuntos(): void
    {
        $puntos = [
            ['Condominio Los Pablos', 'Av. Pablo Neruda 01450, Temuco', 'condominio', -38.7180, -72.6050, 'lleno', 94],
            ['Punto limpio Av. Alemania', 'Av. Alemania 0671, Temuco', 'punto_calle', -38.7330, -72.6100, 'alto', 82],
            ['Universidad de La Frontera', 'Av. Francisco Salazar 01145, Temuco', 'empresa', -38.7480, -72.6170, 'lleno', 91],
            ['Feria Pinto', 'Av. Barros Arana 1150, Temuco', 'punto_calle', -38.7400, -72.5850, 'alto', 79],
            ['Portal Temuco', 'Av. Alemania 0671, sector poniente', 'empresa', -38.7355, -72.6155, 'lleno', 96],
            ['Parque Estadio Germán Becker', 'Av. Pablo Neruda 01110, Temuco', 'municipio', -38.7422, -72.6205, 'alto', 76],
            ['Hospital Regional de Temuco', 'Manuel Montt 115, Temuco', 'municipio', -38.7368, -72.5965, 'alto', 85],
            ['Plaza Aníbal Pinto', 'Bulnes con Claro Solar, Temuco', 'municipio', -38.7359, -72.5904, 'medio', 52],
            ['Municipalidad de Padre Las Casas', 'Maquehue 1441, Padre Las Casas', 'municipio', -38.7700, -72.5950, 'vacio', 8],
            ['Sector Pueblo Nuevo', 'Av. Javiera Carrera 1300, Temuco', 'punto_calle', -38.7230, -72.5800, 'medio', 61],
            ['Sector Pedro de Valdivia', 'Av. Pedro de Valdivia 01850, Temuco', 'condominio', -38.7155, -72.6250, 'bajo', 28],
            ['Sector Amanecer', 'Av. Recabarren 02650, Temuco', 'punto_calle', -38.7580, -72.6280, 'medio', 49],
            ['Sector Santa Rosa', 'Av. Prieto Norte 0850, Temuco', 'punto_calle', -38.7310, -72.5730, 'bajo', 22],
            ['Costanera del Cautín', 'Av. Los Poetas 0450, Temuco', 'punto_calle', -38.7510, -72.5920, 'vacio', 5],
            ['Villa Los Ríos', 'Av. San Martín 01200, Temuco', 'condominio', -38.7415, -72.6060, 'medio', 58],
            ['Pulmahue Padre Las Casas', 'Villa Alegre 0950, Padre Las Casas', 'condominio', -38.7745, -72.6080, 'bajo', 31],
        ];

        foreach ($puntos as [$nombre, $direccion, $tipo, $lat, $lng, $estado, $porcentaje]) {
            Punto::updateOrCreate(
                ['nombre' => $nombre],
                [
                    'direccion' => $direccion,
                    'tipo_punto' => $tipo,
                    'latitud' => $lat,
                    'longitud' => $lng,
                    'estado_llenado' => $estado,
                    'porcentaje_llenado' => $porcentaje,
                    'ultima_actualizacion_estado' => now(),
                    'activo' => true,
                ],
            );
        }
    }
}
