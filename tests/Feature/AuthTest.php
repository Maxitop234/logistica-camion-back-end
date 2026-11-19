<?php

use App\Models\Administrador;
use App\Models\AuditLog;
use App\Models\Camion;
use App\Models\Chofer;
use App\Models\User;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;

function credenciales(User $user, string $password = 'password'): array
{
    return ['email' => $user->email, 'password' => $password, 'device_name' => 'pixel-7'];
}

test('el chofer inicia sesion y recibe token, rol y perfil descifrado', function () {
    $chofer = Chofer::factory()->create(['nombre_cifrado' => 'Juan Pérez']);

    $this->postJson('/api/v1/auth/login', credenciales($chofer->user))
        ->assertOk()
        ->assertJsonPath('role', 'chofer')
        ->assertJsonPath('user.perfil.nombre', 'Juan Pérez')
        ->assertJsonStructure(['token', 'token_type']);
});

test('el administrador inicia sesion y recibe su perfil', function () {
    $admin = Administrador::factory()->create(['nombre_completo' => 'María Soto']);

    $this->postJson('/api/v1/auth/login', credenciales($admin->user))
        ->assertOk()
        ->assertJsonPath('role', 'administrador')
        ->assertJsonPath('user.perfil.nombre', 'María Soto');
});

test('el email se normaliza a minusculas', function () {
    $user = User::factory()->create(['email' => 'ana@example.com']);

    $this->postJson('/api/v1/auth/login', [...credenciales($user), 'email' => 'ANA@Example.com'])
        ->assertOk();
});

test('valida los campos requeridos', function () {
    $this->postJson('/api/v1/auth/login', [])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['email', 'password', 'device_name']);
});

test('contrasena incorrecta devuelve 401 y registra failed_login', function () {
    $user = User::factory()->create();

    $this->postJson('/api/v1/auth/login', credenciales($user, 'mala'))->assertUnauthorized();

    $log = AuditLog::where('accion', 'failed_login')->first();
    expect($log->user_id)->toBe($user->id)
        ->and($log->detalles['motivo'])->toBe('credenciales_invalidas');
});

test('un email inexistente registra failed_login sin usuario', function () {
    $this->postJson('/api/v1/auth/login', [
        'email' => 'nadie@example.com', 'password' => 'x', 'device_name' => 'pc',
    ])->assertUnauthorized();

    expect(AuditLog::where('accion', 'failed_login')->first()->user_id)->toBeNull();
});

test('un usuario inactivo no puede entrar aunque la contrasena sea correcta', function () {
    $user = User::factory()->create(['is_active' => false]);

    $this->postJson('/api/v1/auth/login', credenciales($user))->assertForbidden();

    expect($user->tokens()->count())->toBe(0)
        ->and(AuditLog::where('accion', 'failed_login')->first()->detalles['motivo'])->toBe('usuario_inactivo');
});

test('tras 5 intentos fallidos el login responde 429 incluso con la clave correcta', function () {
    RateLimiter::clear('lock@example.com|127.0.0.1');
    $user = User::factory()->create(['email' => 'lock@example.com']);

    foreach (range(1, 5) as $i) {
        $this->postJson('/api/v1/auth/login', credenciales($user, 'mala'))->assertUnauthorized();
    }

    $this->postJson('/api/v1/auth/login', credenciales($user))->assertStatus(429);
});

test('un login exitoso registra login y nunca guarda contrasenas en audit_logs', function () {
    $user = User::factory()->create();

    $this->postJson('/api/v1/auth/login', credenciales($user))->assertOk();
    $this->postJson('/api/v1/auth/login', credenciales($user, 'secreto-invalido-123'))->assertUnauthorized();

    $volcado = AuditLog::all()->toJson();

    expect(AuditLog::where('accion', 'login')->count())->toBe(1)
        ->and($volcado)->not->toContain('secreto-invalido-123')
        ->and($volcado)->not->toContain('"password"');
});

test('logout revoca el token y registra el evento', function () {
    $user = User::factory()->create();
    $token = $this->postJson('/api/v1/auth/login', credenciales($user))->json('token');

    $this->withToken($token)->postJson('/api/v1/auth/logout')->assertOk();

    expect($user->tokens()->count())->toBe(0)
        ->and(AuditLog::where('accion', 'logout')->count())->toBe(1);

    $this->app['auth']->forgetGuards();
    $this->withToken($token)->getJson('/api/v1/auth/me')->assertUnauthorized();
});

test('me devuelve el chofer con su camion asignado', function () {
    $chofer = Chofer::factory()->create();
    $camion = Camion::factory()->create(['chofer_actual_id' => $chofer->id]);

    $this->actingAs($chofer->user, 'sanctum')
        ->getJson('/api/v1/auth/me')
        ->assertOk()
        ->assertJsonPath('data.camion.patente', $camion->patente)
        ->assertJsonPath('data.ruta_activa', null);
});

test('me devuelve camion nulo al chofer sin camion asignado', function () {
    $chofer = Chofer::factory()->create();

    $this->actingAs($chofer->user, 'sanctum')
        ->getJson('/api/v1/auth/me')
        ->assertOk()
        ->assertJsonPath('data.camion', null)
        ->assertJsonPath('data.ruta_activa', null);
});

test('me del administrador no incluye camion ni ruta activa', function () {
    $admin = Administrador::factory()->create();

    $this->actingAs($admin->user, 'sanctum')
        ->getJson('/api/v1/auth/me')
        ->assertOk()
        ->assertJsonPath('data.role', 'administrador')
        ->assertJsonMissingPath('data.camion')
        ->assertJsonMissingPath('data.ruta_activa');
});

test('me sin token devuelve 401 en json', function () {
    $this->getJson('/api/v1/auth/me')->assertUnauthorized();
});

test('un token de un usuario desactivado despues del login es rechazado y revocado', function () {
    $user = User::factory()->create();
    $token = $this->postJson('/api/v1/auth/login', credenciales($user))->json('token');

    $user->update(['is_active' => false]);
    $this->app['auth']->forgetGuards();

    $this->withToken($token)->getJson('/api/v1/auth/me')->assertForbidden();
    expect($user->tokens()->count())->toBe(0);
});

test('el middleware role permite los roles indicados y bloquea el resto', function () {
    Route::middleware(['auth:sanctum', 'role:administrador'])->get('/api/_solo-admin', fn () => ['ok' => true]);

    $admin = User::factory()->create(['role' => 'administrador']);
    $chofer = User::factory()->create(['role' => 'chofer']);

    $this->actingAs($admin, 'sanctum')->getJson('/api/_solo-admin')->assertOk();
    $this->actingAs($chofer, 'sanctum')->getJson('/api/_solo-admin')->assertForbidden();
});
