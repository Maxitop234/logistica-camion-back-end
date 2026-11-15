<?php

namespace Database\Factories;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AuditLog>
 */
class AuditLogFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'accion' => fake()->randomElement(['login', 'logout', 'failed_login', 'route_generated']),
            'ip_address' => fake()->ipv4(),
            'user_agent' => fake()->userAgent(),
            'detalles' => null,
        ];
    }
}
