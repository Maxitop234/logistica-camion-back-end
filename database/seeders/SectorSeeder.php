<?php

namespace Database\Seeders;

use App\Models\Sector;
use Illuminate\Database\Seeder;

/**
 * Los 12 sectores reales de Somos Terranova en Temuco, según la planilla
 * "Georreferenciación de rutas Temuco, enero a mayo 2026" (cierre de mayo).
 *
 * `dias_recoleccion` son los días marcados en la planilla (ISO, 1 = lunes) y
 * `puntos_esperados` los puntos de mayo. Donde la frecuencia declarada no
 * coincide con los días marcados se conserva tal cual: mandan los días.
 *
 * Idempotente: actualiza por nombre y no toca los puntos.
 */
class SectorSeeder extends Seeder
{
    /**
     * @var array<int, array{nombre: string, dias: array<int, int>, periodicidad: int, puntos_esperados: int, descripcion: string|null}>
     */
    public const SECTORES = [
        ['nombre' => 'Villa San Andres, Fdo Las Mariposas', 'dias' => [2, 4], 'periodicidad' => 2, 'puntos_esperados' => 23, 'descripcion' => null],
        ['nombre' => 'S.Martin, Av.Alemania, Thiers, P.Dreves', 'dias' => [4, 5], 'periodicidad' => 1, 'puntos_esperados' => 25, 'descripcion' => null],
        ['nombre' => 'Labranza', 'dias' => [4], 'periodicidad' => 1, 'puntos_esperados' => 40, 'descripcion' => null],
        ['nombre' => 'Pedro de Valdivia', 'dias' => [2, 3], 'periodicidad' => 2, 'puntos_esperados' => 62, 'descripcion' => null],
        ['nombre' => 'Fundó del Carmen más Luis Duran', 'dias' => [2, 3], 'periodicidad' => 2, 'puntos_esperados' => 55, 'descripcion' => null],
        ['nombre' => 'Poniente', 'dias' => [1, 3, 5], 'periodicidad' => 3, 'puntos_esperados' => 51, 'descripcion' => null],
        ['nombre' => 'Amanecer y Amanecer Alto', 'dias' => [1, 5], 'periodicidad' => 3, 'puntos_esperados' => 66, 'descripcion' => 'Santa Elene del Maipo Bajo, Vista Verde, Juan Pablo II, Laquelarren'],
        ['nombre' => 'Pueblo Nuevo, Valparaiso, Barros Arana', 'dias' => [3, 4], 'periodicidad' => 2, 'puntos_esperados' => 36, 'descripcion' => null],
        ['nombre' => 'Las Quilas, centro', 'dias' => [2, 5], 'periodicidad' => 2, 'puntos_esperados' => 31, 'descripcion' => null],
        ['nombre' => 'Langdon, Turingia, V.Los Ríos', 'dias' => [1, 3, 5], 'periodicidad' => 3, 'puntos_esperados' => 40, 'descripcion' => null],
        ['nombre' => 'Avenida Alemania, aragon, Torre Molino', 'dias' => [1, 4], 'periodicidad' => 2, 'puntos_esperados' => 25, 'descripcion' => null],
        ['nombre' => 'Avenida Estadio, Javierra carrera', 'dias' => [1, 3, 5], 'periodicidad' => 3, 'puntos_esperados' => 24, 'descripcion' => null],
    ];

    public function run(): void
    {
        foreach (self::SECTORES as $sector) {
            Sector::updateOrCreate(
                ['nombre' => $sector['nombre']],
                [
                    'dias_recoleccion' => $sector['dias'],
                    'periodicidad_semanal' => $sector['periodicidad'],
                    'puntos_esperados' => $sector['puntos_esperados'],
                    'descripcion' => $sector['descripcion'],
                    'activo' => true,
                ],
            );
        }
    }
}
