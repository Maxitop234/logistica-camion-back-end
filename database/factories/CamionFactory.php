<?php

namespace Database\Factories;

use App\Models\Camion;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Camion>
 */
class CamionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'patente' => strtoupper(fake()->unique()->bothify('????##')),
            'marca' => fake()->randomElement(['Hino', 'Isuzu', 'Mercedes-Benz', 'Volvo']),
            'modelo' => fake()->bothify('Serie ###'),
            'anio' => fake()->numberBetween(2015, 2026),
            'capacidad_kg' => fake()->randomFloat(2, 3000, 12000),
            'estado_operativo' => 'disponible',
            'chofer_actual_id' => null,
            'ultima_latitud' => null,
            'ultima_longitud' => null,
            'velocidad_actual_kmh' => 0,
            'ultima_actualizacion_gps' => null,
        ];
    }

    /**
     * Indicate that the truck is on a route.
     */
    public function enRuta(): static
    {
        return $this->state(fn (array $attributes) => [
            'estado_operativo' => 'en_ruta',
        ]);
    }
}
