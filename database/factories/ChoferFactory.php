<?php

namespace Database\Factories;

use App\Models\Chofer;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Chofer>
 */
class ChoferFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $rut = fake()->unique()->numerify('########').fake()->randomElement(['0', '1', '2', '3', '4', '5', '6', '7', '8', '9', 'K']);

        return [
            'user_id' => User::factory()->state(['role' => 'chofer']),
            'nombre_cifrado' => fake()->name(),
            'rut_hash' => Chofer::hashRut($rut),
            'licencia_clase' => 'A4',
            'telefono_cifrado' => fake()->numerify('+569########'),
            'disponible' => true,
        ];
    }

    /**
     * Indicate that the driver is not available.
     */
    public function noDisponible(): static
    {
        return $this->state(fn (array $attributes) => [
            'disponible' => false,
        ]);
    }
}
