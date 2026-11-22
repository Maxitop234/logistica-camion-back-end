<?php

namespace Database\Factories;

use App\Models\Sector;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Sector>
 */
class SectorFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'nombre' => 'Sector de prueba '.fake()->unique()->numberBetween(1, 1_000_000),
            'dias_recoleccion' => [2, 4],
            'periodicidad_semanal' => 2,
            'puntos_esperados' => 20,
            'descripcion' => null,
            'activo' => true,
        ];
    }

    /**
     * @param  array<int, int>  $dias  Días ISO (1 = lunes).
     */
    public function dias(array $dias): static
    {
        return $this->state(fn () => [
            'dias_recoleccion' => $dias,
            'periodicidad_semanal' => count($dias),
        ]);
    }

    public function inactivo(): static
    {
        return $this->state(fn () => [
            'activo' => false,
        ]);
    }
}
