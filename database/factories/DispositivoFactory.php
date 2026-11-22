<?php

namespace Database\Factories;

use App\Models\Dispositivo;
use App\Models\Punto;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Dispositivo>
 */
class DispositivoFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'punto_id' => Punto::factory(),
            'codigo_hardware' => 'ECO-'.strtoupper(fake()->unique()->bothify('????####')),
            'tipo_sensor' => Dispositivo::TIPO_JSN_SR04T,
            'conectividad' => 'wifi',
            'altura_contenedor_cm' => 120,
            'distancia_lleno_cm' => 25,
            'intervalo_lectura_seg' => 3600,
            'estado' => Dispositivo::ESTADO_ACTIVO,
            'vinculado_en' => now(),
        ];
    }

    /**
     * Dispositivo activo con un token conocido por la prueba.
     */
    public function conToken(string $token): static
    {
        return $this->state(fn () => [
            'token_hash' => Dispositivo::hashToken($token),
            'token_entregado_en' => now(),
        ]);
    }

    public function pendiente(): static
    {
        return $this->state(fn () => [
            'punto_id' => null,
            'estado' => Dispositivo::ESTADO_PENDIENTE,
            'vinculado_en' => null,
            'anunciado_en' => now(),
        ]);
    }
}
