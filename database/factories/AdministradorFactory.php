<?php

namespace Database\Factories;

use App\Models\Administrador;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Administrador>
 */
class AdministradorFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory()->state(['role' => 'administrador']),
            'nombre_completo' => fake()->name(),
            'cargo' => fake()->jobTitle(),
            'telefono' => fake()->numerify('+569########'),
        ];
    }
}
