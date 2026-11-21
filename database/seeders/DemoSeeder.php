<?php

namespace Database\Seeders;

use App\Models\Administrador;
use App\Models\Camion;
use App\Models\Chofer;
use App\Models\Punto;
use App\Models\User;
use Illuminate\Database\Seeder;
use RuntimeException;

class DemoSeeder extends Seeder
{
    /**
     * Seeder exclusivo del ambiente de demostración (`APP_DEMO_MODE=true`).
     * Crea una única cuenta administradora («Administración EcoRuta»), los 2
     * choferes de demostración (Nelson Ponce y Maximiliano González), 3 camiones
     * de demostración y 16 puntos en direcciones reales únicas de Temuco y
     * Padre Las Casas cubriendo los 5 sectores.
     */
    public function run(): void
    {
        if (! config('app.demo_mode')) {
            throw new RuntimeException(
                'DemoSeeder está bloqueado porque APP_DEMO_MODE=false. '
                .'Activa APP_DEMO_MODE=true solo en el ambiente de demostración; '
                .'para el ambiente de la empresa usa DatabaseSeeder.'
            );
        }

        $adminEmail = trim((string) config('app.demo.admin_email', ''));
        $adminPassword = (string) config('app.demo.admin_password', '');
        $choferPassword = (string) config('app.demo.chofer_password', '');

        if ($adminEmail === '' || $adminPassword === '' || $choferPassword === '') {
            throw new RuntimeException(
                'Faltan variables de entorno requeridas para el ambiente de demostración: '
                .'define DEMO_ADMIN_EMAIL, DEMO_ADMIN_PASSWORD y DEMO_CHOFER_PASSWORD en .env.'
            );
        }

        $admin = $this->crearAdministradorDemo($adminEmail, $adminPassword);
        $choferes = $this->crearChoferesDemo($choferPassword, $admin->user_id);
        $this->crearCamionesDemo($choferes);
        $this->crearPuntosDemo();
    }

    private function crearAdministradorDemo(string $email, string $password): Administrador
    {
        $user = User::updateOrCreate(
            ['email' => $email],
            [
                'name' => 'Administración EcoRuta',
                'password' => $password,
                'role' => 'administrador',
                'is_active' => true,
            ],
        );

        return Administrador::updateOrCreate(
            ['user_id' => $user->id],
            [
                'nombre_completo' => 'Administración EcoRuta',
                'cargo' => 'Administración General',
                'telefono' => '+56940000001',
            ],
        );
    }

    /**
     * @return array<int, Chofer>
     */
    private function crearChoferesDemo(string $password, int $verificadoPorUserId): array
    {
        $datos = [
            [
                'email' => 'nelson.ponce@demo.ecoruta.cl',
                'nombre' => 'Nelson Ponce',
                'rut' => '16.458.912-K',
                'licencia' => 'A4',
                'telefono' => '+56940000011',
                'avatar' => [
                    'peinado' => 'corto',
                    'color_pelo' => 'negro',
                    'tono_piel' => 'medio',
                    'lentes' => false,
                ],
            ],
            [
                'email' => 'maximiliano.gonzalez@demo.ecoruta.cl',
                'nombre' => 'Maximiliano González',
                'rut' => '17.892.341-5',
                'licencia' => 'A5',
                'telefono' => '+56940000012',
                'avatar' => [
                    'peinado' => 'ondas',
                    'color_pelo' => 'castano',
                    'tono_piel' => 'medio_claro',
                    'lentes' => true,
                ],
            ],
        ];

        $choferes = [];
        foreach ($datos as $item) {
            $user = User::updateOrCreate(
                ['email' => $item['email']],
                [
                    'name' => $item['nombre'],
                    'password' => $password,
                    'role' => 'chofer',
                    'is_active' => true,
                ],
            );

            $choferes[] = Chofer::updateOrCreate(
                ['user_id' => $user->id],
                [
                    'nombre_cifrado' => $item['nombre'],
                    'rut_hash' => Chofer::hashRut($item['rut']),
                    'licencia_clase' => $item['licencia'],
                    'telefono_cifrado' => $item['telefono'],
                    'disponible' => true,
                    'licencia_estado' => Chofer::LICENCIA_VERIFICADA,
                    'licencia_verificada_por_user_id' => $verificadoPorUserId,
                    'licencia_verificada_en' => now()->subMonths(4),
                    'avatar_config' => $item['avatar'],
                ],
            );
        }

        return $choferes;
    }

    /**
     * @param  array<int, Chofer>  $choferes
     */
    private function crearCamionesDemo(array $choferes): void
    {
        $datos = [
            ['DM1001', 'Hino', '500 (Demo)', 2022, 8000, 4.6, 95000, 'disponible', $choferes[0]->id],
            ['DM2002', 'Isuzu', 'NPR (Demo)', 2021, 6000, 5.2, 82000, 'disponible', $choferes[1]->id],
            ['DM3003', 'Mercedes-Benz', 'Atego (Demo)', 2023, 9500, 4.2, 105000, 'disponible', null],
        ];

        foreach ($datos as [$patente, $marca, $modelo, $anio, $capacidad, $rendimiento, $arriendo, $estado, $choferId]) {
            Camion::updateOrCreate(
                ['patente' => $patente],
                [
                    'marca' => $marca,
                    'modelo' => $modelo,
                    'anio' => $anio,
                    'capacidad_kg' => $capacidad,
                    'rendimiento_km_l' => $rendimiento,
                    'costo_arriendo_diario' => $arriendo,
                    'estado_operativo' => $estado,
                    'chofer_actual_id' => $choferId,
                ],
            );
        }
    }

    private function crearPuntosDemo(): void
    {
        // 16 puntos en direcciones reales y únicas de Temuco y Padre Las Casas,
        // cubriendo los 5 sectores sin direcciones repetidas.
        $puntos = [
            // Centro (3)
            ['Plaza Aníbal Pinto', 'Arturo Prat 696, Temuco', Punto::TIPO_CALLE, Punto::SECTOR_CENTRO, -38.7359, -72.5904, 64, 'ESP32-DEMO-01'],
            ['Hospital Regional de Temuco', 'Manuel Montt 115, Temuco', Punto::TIPO_CALLE, Punto::SECTOR_CENTRO, -38.7368, -72.5965, 84, 'ESP32-DEMO-02'],
            ['Mercado Municipal de Temuco', 'Aldunate 490, Temuco', Punto::TIPO_CALLE, Punto::SECTOR_CENTRO, -38.7386, -72.5878, 92, 'ESP32-DEMO-03'],
            // Poniente (4)
            ['Portal Temuco', 'Av. Alemania 0671, Temuco', Punto::TIPO_CALLE, Punto::SECTOR_PONIENTE, -38.7355, -72.6155, 95, 'ESP32-DEMO-04'],
            ['Universidad de La Frontera', 'Av. Francisco Salazar 01145, Temuco', Punto::TIPO_CALLE, Punto::SECTOR_PONIENTE, -38.7480, -72.6170, 88, 'ESP32-DEMO-05'],
            ['Parque Estadio Germán Becker', 'Av. Pablo Neruda 01110, Temuco', Punto::TIPO_CALLE, Punto::SECTOR_PONIENTE, -38.7422, -72.6205, 76, 'ESP32-DEMO-06'],
            ['Villa Los Ríos', 'Av. San Martín 01200, Temuco', Punto::TIPO_CONDOMINIO, Punto::SECTOR_PONIENTE, -38.7415, -72.6060, 58, 'ESP32-DEMO-07'],
            // Oriente (3)
            ['Feria Pinto', 'Av. Barros Arana 1150, Temuco', Punto::TIPO_CALLE, Punto::SECTOR_ORIENTE, -38.7400, -72.5765, 94, 'ESP32-DEMO-08'],
            ['Sector Santa Rosa', 'Av. Prieto Norte 0850, Temuco', Punto::TIPO_CALLE, Punto::SECTOR_ORIENTE, -38.7310, -72.5730, 32, 'ESP32-DEMO-09'],
            ['Costanera del Cautín', 'Av. Los Poetas 0450, Temuco', Punto::TIPO_CALLE, Punto::SECTOR_ORIENTE, -38.7510, -72.5785, 22, null],
            // Norte (3)
            ['Condominio Los Pablos', 'Av. Los Pablos 01950, Temuco', Punto::TIPO_CONDOMINIO, Punto::SECTOR_NORTE, -38.7180, -72.6050, 91, 'ESP32-DEMO-10'],
            ['Sector Pueblo Nuevo', 'Av. Rudecindo Ortega 01650, Temuco', Punto::TIPO_CALLE, Punto::SECTOR_NORTE, -38.7205, -72.5800, 62, 'ESP32-DEMO-11'],
            ['Sector Pedro de Valdivia', 'Av. Pedro de Valdivia 01850, Temuco', Punto::TIPO_CONDOMINIO, Punto::SECTOR_NORTE, -38.7155, -72.6250, 29, 'ESP32-DEMO-12'],
            // Padre Las Casas (3)
            ['Municipalidad de Padre Las Casas', 'Maquehue 1441, Padre Las Casas', Punto::TIPO_CALLE, Punto::SECTOR_PADRE_LAS_CASAS, -38.7700, -72.5950, 78, 'ESP32-DEMO-13'],
            ['Pulmahue Padre Las Casas', 'Villa Alegre 0950, Padre Las Casas', Punto::TIPO_CONDOMINIO, Punto::SECTOR_PADRE_LAS_CASAS, -38.7745, -72.6080, 45, 'ESP32-DEMO-14'],
            ['Parque Corcolén Padre Las Casas', 'Av. Guido Beck de Ramberga 0720, Padre Las Casas', Punto::TIPO_CALLE, Punto::SECTOR_PADRE_LAS_CASAS, -38.7662, -72.5872, 18, null],
        ];

        $ahora = now();
        foreach ($puntos as [$nombre, $direccion, $tipo, $sector, $lat, $lng, $porcentaje, $sensorCodigo]) {
            $tieneSensor = $sensorCodigo !== null;
            Punto::updateOrCreate(
                ['nombre' => $nombre],
                [
                    'nombre_automatico' => false,
                    'direccion' => $direccion,
                    'tipo_punto' => $tipo,
                    'sector' => $sector,
                    'latitud' => $lat,
                    'longitud' => $lng,
                    'estado_llenado' => Punto::clasificarEstado($porcentaje),
                    'porcentaje_llenado' => $porcentaje,
                    'ultima_actualizacion_estado' => $ahora,
                    'origen_actualizacion' => $tieneSensor ? Punto::ORIGEN_SENSOR_IOT : Punto::ORIGEN_MANUAL,
                    'altura_contenedor_cm' => 120.0,
                    'sensor_codigo' => $sensorCodigo,
                    'bateria_voltaje' => $tieneSensor ? 3.85 : null,
                    'ultimo_heartbeat_iot' => $tieneSensor ? $ahora->copy()->subMinutes(20) : null,
                    'activo' => true,
                ],
            );
        }
    }
}
