<?php

use App\Models\Camion;
use App\Models\Chofer;
use App\Models\User;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->admin = User::factory()->create(['role' => 'administrador']);
});

function datosChofer(array $extra = []): array
{
    return [
        'email' => 'Nuevo@Example.com', 'password' => 'password123', 'nombre' => 'Juan Pérez',
        'rut' => '12.345.678-5', 'telefono' => '+56912345678',
        ...$extra,
    ];
}

test('solo el administrador accede al crud de choferes', function () {
    $this->getJson('/api/v1/choferes')->assertUnauthorized();

    $this->actingAs(User::factory()->create(['role' => 'chofer']), 'sanctum')
        ->postJson('/api/v1/choferes', datosChofer())->assertForbidden();
});

test('crea usuario con rol chofer y perfil con datos cifrados en una sola operacion', function () {
    $respuesta = $this->actingAs($this->admin, 'sanctum')
        ->postJson('/api/v1/choferes', datosChofer())
        ->assertCreated()
        ->assertJsonPath('data.nombre', 'Juan Pérez')
        ->assertJsonPath('data.email', 'nuevo@example.com')
        ->assertJsonPath('data.licencia_clase', 'A4');

    $user = User::where('email', 'nuevo@example.com')->first();
    $raw = DB::table('choferes')->where('id', $respuesta->json('data.id'))->first();

    expect($user->role)->toBe('chofer')
        ->and($user->is_active)->toBeTrue()
        ->and($raw->nombre_cifrado)->not->toContain('Juan')
        ->and($raw->rut_hash)->toBe(Chofer::hashRut('123456785'));
});

test('el chofer creado puede iniciar sesion', function () {
    $this->actingAs($this->admin, 'sanctum')->postJson('/api/v1/choferes', datosChofer())->assertCreated();

    $this->postJson('/api/v1/auth/login', [
        'email' => 'nuevo@example.com', 'password' => 'password123', 'device_name' => 'pixel',
    ])->assertOk()->assertJsonPath('role', 'chofer');
});

test('rechaza rut invalido, rut duplicado en otro formato y email repetido', function () {
    $this->actingAs($this->admin, 'sanctum')->postJson('/api/v1/choferes', datosChofer())->assertCreated();

    $this->actingAs($this->admin, 'sanctum')
        ->postJson('/api/v1/choferes', datosChofer(['email' => 'otro@example.com', 'rut' => '123456785']))
        ->assertJsonValidationErrors(['rut']);

    $this->actingAs($this->admin, 'sanctum')
        ->postJson('/api/v1/choferes', datosChofer(['email' => 'otro@example.com', 'rut' => '12.345.678-9']))
        ->assertJsonValidationErrors(['rut']);

    $this->actingAs($this->admin, 'sanctum')
        ->postJson('/api/v1/choferes', datosChofer(['rut' => '11.111.111-1']))
        ->assertJsonValidationErrors(['email']);
});

test('valida el digito verificador del rut', function (string $rut, bool $valido) {
    expect(Chofer::rutEsValido($rut))->toBe($valido);
})->with([
    ['12.345.678-5', true],
    ['11.111.111-1', true],
    ['123456785', true],
    ['12.345.678-9', false],
    ['abc', false],
]);

test('lista choferes con nombre descifrado y camion asignado', function () {
    $chofer = Chofer::factory()->create(['nombre_cifrado' => 'Ana Rojas']);
    Camion::factory()->create(['chofer_actual_id' => $chofer->id, 'patente' => 'ZZ1111']);

    $this->actingAs($this->admin, 'sanctum')
        ->getJson('/api/v1/choferes')
        ->assertOk()
        ->assertJsonPath('data.0.nombre', 'Ana Rojas')
        ->assertJsonPath('data.0.camion_actual.patente', 'ZZ1111');
});

test('actualiza nombre, licencia, telefono y disponibilidad', function () {
    $chofer = Chofer::factory()->create();

    $this->actingAs($this->admin, 'sanctum')
        ->putJson("/api/v1/choferes/{$chofer->id}", [
            'nombre' => 'Nombre Nuevo', 'licencia_clase' => 'A5', 'telefono' => null, 'disponible' => false,
        ])
        ->assertOk();

    $chofer = $chofer->fresh();
    expect($chofer->nombre_cifrado)->toBe('Nombre Nuevo')
        ->and($chofer->licencia_clase)->toBe('A5')
        ->and($chofer->telefono_cifrado)->toBeNull()
        ->and($chofer->disponible)->toBeFalse();
});

test('desactivar un chofer guarda motivo, inactiva su cuenta, revoca tokens y libera su camion', function () {
    $chofer = Chofer::factory()->create();
    $camion = Camion::factory()->create(['chofer_actual_id' => $chofer->id]);
    $chofer->user->createToken('pixel');

    $this->actingAs($this->admin, 'sanctum')
        ->deleteJson("/api/v1/choferes/{$chofer->id}", ['motivo' => 'Licencia médica'])
        ->assertNoContent();

    expect($chofer->user->fresh()->is_active)->toBeFalse()
        ->and($chofer->user->tokens()->count())->toBe(0)
        ->and($chofer->fresh()->disponible)->toBeFalse()
        ->and($chofer->fresh()->motivo_desactivacion)->toBe('Licencia médica')
        ->and($camion->fresh()->chofer_actual_id)->toBeNull();

    $token = $this->postJson('/api/v1/auth/login', [
        'email' => $chofer->user->email, 'password' => 'password', 'device_name' => 'pixel',
    ])
        ->assertOk()
        ->assertJsonPath('user.is_active', false)
        ->assertJsonPath('user.perfil.motivo_desactivacion', 'Licencia médica')
        ->json('token');

    $this->withToken($token)->getJson('/api/v1/chofer/licencia')->assertForbidden();
});
