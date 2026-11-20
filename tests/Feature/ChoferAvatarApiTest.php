<?php

use App\Models\Administrador;
use App\Models\Chofer;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

function crearChoferParaAvatar(bool $activo = true): Chofer
{
    $user = User::factory()->create([
        'role' => 'chofer',
        'is_active' => $activo,
    ]);

    return Chofer::factory()->for($user)->create();
}

function crearAdminParaAvatar(): User
{
    $user = User::factory()->create(['role' => 'administrador']);
    Administrador::factory()->for($user)->create();

    return $user;
}

test('auth me y listado de choferes exponen el avatar por defecto cuando es null', function () {
    $chofer = crearChoferParaAvatar();
    $admin = crearAdminParaAvatar();

    Sanctum::actingAs($chofer->user);
    $this->getJson('/api/v1/auth/me')
        ->assertOk()
        ->assertJsonPath('data.perfil.avatar', Chofer::AVATAR_DEFAULT);

    Sanctum::actingAs($admin);
    $this->getJson('/api/v1/choferes')
        ->assertOk()
        ->assertJsonPath('data.0.avatar', Chofer::AVATAR_DEFAULT);
});

test('el chofer puede personalizar su avatar y se refleja en me y en choferes del administrador', function () {
    $chofer = crearChoferParaAvatar();
    $otroChofer = crearChoferParaAvatar();
    $admin = crearAdminParaAvatar();

    $payload = [
        'peinado' => 'ondas',
        'color_pelo' => 'pelirrojo',
        'tono_piel' => 'moreno',
        'lentes' => true,
    ];

    Sanctum::actingAs($chofer->user);
    $this->putJson('/api/v1/chofer/avatar', $payload)
        ->assertOk()
        ->assertJsonPath('data', $payload);

    expect($chofer->fresh()->avatar_config)->toBe($payload);
    expect($otroChofer->fresh()->avatar_config)->toBeNull();

    $this->getJson('/api/v1/auth/me')
        ->assertOk()
        ->assertJsonPath('data.perfil.avatar', $payload);

    Sanctum::actingAs($admin);
    $respuesta = $this->getJson('/api/v1/choferes')->assertOk();
    $datos = collect($respuesta->json('data'));

    expect($datos->firstWhere('id', $chofer->id)['avatar'])->toBe($payload);
    expect($datos->firstWhere('id', $otroChofer->id)['avatar'])->toBe(Chofer::AVATAR_DEFAULT);
});

test('rechaza valores invalidos o incompletos al actualizar el avatar', function () {
    $chofer = crearChoferParaAvatar();
    Sanctum::actingAs($chofer->user);

    $this->putJson('/api/v1/chofer/avatar', [
        'peinado' => 'mohicano',
        'color_pelo' => 'azul',
        'tono_piel' => 'verde',
        'lentes' => 'si',
    ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['peinado', 'color_pelo', 'tono_piel', 'lentes']);

    $this->putJson('/api/v1/chofer/avatar', [])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['peinado', 'color_pelo', 'tono_piel', 'lentes']);
});

test('controla autenticacion, rol y estado activo en el endpoint de avatar', function () {
    $payload = [
        'peinado' => 'mono',
        'color_pelo' => 'negro',
        'tono_piel' => 'oscuro',
        'lentes' => false,
    ];

    $this->putJson('/api/v1/chofer/avatar', $payload)->assertUnauthorized();

    $admin = crearAdminParaAvatar();
    Sanctum::actingAs($admin);
    $this->putJson('/api/v1/chofer/avatar', $payload)->assertForbidden();

    $choferInactivo = crearChoferParaAvatar(activo: false);
    Sanctum::actingAs($choferInactivo->user);
    $this->putJson('/api/v1/chofer/avatar', $payload)->assertForbidden();
});
