<?php

namespace Database\Factories;

use App\Models\Punto;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Punto>
 */
class PuntoFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'nombre' => 'Punto '.fake()->streetName(),
            'direccion' => fake()->streetAddress(),
            'tipo_punto' => fake()->randomElement(['condominio', 'punto_calle', 'empresa', 'municipio']),
            'latitud' => fake()->randomFloat(6, -38.775, -38.710),
            'longitud' => fake()->randomFloat(6, -72.640, -72.560),
            'estado_llenado' => Punto::ESTADO_ALTO,
            'porcentaje_llenado' => 82,
            'ultima_actualizacion_estado' => now(),
            'activo' => true,
        ];
    }

    public function lleno(): static
    {
        return $this->state(fn () => [
            'estado_llenado' => Punto::ESTADO_LLENO,
            'porcentaje_llenado' => 95,
        ]);
    }

    public function medio(): static
    {
        return $this->state(fn () => [
            'estado_llenado' => Punto::ESTADO_MEDIO,
            'porcentaje_llenado' => 52,
        ]);
    }

    public function bajo(): static
    {
        return $this->state(fn () => [
            'estado_llenado' => Punto::ESTADO_BAJO,
            'porcentaje_llenado' => 25,
        ]);
    }
}
