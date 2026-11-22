<?php

use App\Models\Administrador;
use App\Models\AuditLog;
use App\Models\Chofer;
use App\Models\User;
use App\Notifications\RestablecerPasswordAdministrador;
use App\Services\Cuentas\GestorDeCuentas;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use PragmaRX\Google2FA\Google2FA;

const SEG_CLAVE_VALIDA = 'Zorro-Azul-4821';

beforeEach(fn () => config(['app.demo_mode' => false]));

function segAdmin(array $user = []): User
{
    return Administrador::factory()->create(['user_id' => User::factory()->create(['role' => 'administrador', ...$user])->id])->user;
}

function segLogin(User $user, string $password = 'password'): TestResponse
{
    return test()->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => $password, 'device_name' => 'test']);
}

function segDatosChofer(array $extra = []): array
{
    return ['email' => 'nuevo@example.com', 'password' => SEG_CLAVE_VALIDA, 'nombre' => 'Nuevo Chofer', 'rut' => '12.345.678-5', ...$extra];
}

// --- Choferes: acceso gestionado por el admin ---

test('crear chofer exige cambio de contraseña por defecto y rechaza claves comunes', function () {
    Sanctum::actingAs(segAdmin());

    $this->postJson('/api/v1/choferes', segDatosChofer(['password' => 'password123']))->assertUnprocessable()->assertJsonValidationErrors('password');
    $this->postJson('/api/v1/choferes', segDatosChofer(['password' => 'corta1']))->assertUnprocessable();
    $this->postJson('/api/v1/choferes', segDatosChofer())->assertCreated()->assertJsonPath('data.debe_cambiar_password', true);

    expect(User::where('email', 'nuevo@example.com')->first()->debe_cambiar_password)->toBeTrue();
    expect(AuditLog::where('accion', 'chofer_creado')->first()->detalles)->not->toHaveKey('password');
});

test('crear chofer puede no pedir el cambio', function () {
    Sanctum::actingAs(segAdmin());

    $this->postJson('/api/v1/choferes', segDatosChofer(['debe_cambiar_password' => false]))->assertCreated()->assertJsonPath('data.debe_cambiar_password', false);
});

test('cambiar el correo del chofer revoca sus tokens y audita', function () {
    $chofer = Chofer::factory()->create();
    $chofer->user->createToken('movil');
    Sanctum::actingAs(segAdmin());

    $this->putJson("/api/v1/choferes/{$chofer->id}", ['email' => 'otro@example.com'])->assertOk()->assertJsonPath('data.email', 'otro@example.com');

    expect($chofer->user->tokens()->count())->toBe(0);
    expect(AuditLog::where('accion', 'chofer_email_cambiado')->exists())->toBeTrue();
});

test('restablecer la contraseña del chofer la entrega una vez, fuerza el cambio y revoca tokens', function () {
    $chofer = Chofer::factory()->create();
    $chofer->user->createToken('movil');
    Sanctum::actingAs(segAdmin());

    $clave = $this->postJson("/api/v1/choferes/{$chofer->id}/restablecer-password")->assertOk()->json('password');

    expect(strlen($clave))->toBe(16);
    $user = $chofer->user->fresh();
    expect(Hash::check($clave, $user->password))->toBeTrue()->and($user->debe_cambiar_password)->toBeTrue()->and($user->tokens()->count())->toBe(0);
    $log = AuditLog::where('accion', 'chofer_password_restablecida')->first();
    expect(json_encode($log->detalles))->not->toContain($clave);
    $this->getJson("/api/v1/choferes/{$chofer->id}")->assertJsonMissingPath('data.password');
});

// --- Cambio propio y forzado ---

test('con cambio pendiente la API bloquea todo salvo me, logout y cambiar contraseña', function () {
    $user = Chofer::factory()->create()->user;
    $user->update(['debe_cambiar_password' => true]);
    $token = segLogin($user)->json('token');
    $h = ['Authorization' => "Bearer {$token}"];

    $this->getJson('/api/v1/chofer/puntos', $h)->assertForbidden()->assertJsonPath('code', 'cambio_password_requerido');
    $this->getJson('/api/v1/auth/me', $h)->assertOk()->assertJsonPath('data.debe_cambiar_password', true);
});

test('cambiar contraseña pide la actual, valida, revoca otras sesiones y levanta el bloqueo', function () {
    $user = Chofer::factory()->create()->user;
    $user->update(['debe_cambiar_password' => true]);
    $otra = $user->createToken('otro-telefono');
    $token = segLogin($user)->json('token');
    $h = ['Authorization' => "Bearer {$token}"];

    $this->postJson('/api/v1/auth/password', ['password_actual' => 'mala', 'password' => SEG_CLAVE_VALIDA, 'password_confirmation' => SEG_CLAVE_VALIDA], $h)->assertUnprocessable();
    $this->postJson('/api/v1/auth/password', ['password_actual' => 'password', 'password' => 'password123', 'password_confirmation' => 'password123'], $h)->assertUnprocessable();
    $this->postJson('/api/v1/auth/password', ['password_actual' => 'password', 'password' => SEG_CLAVE_VALIDA, 'password_confirmation' => 'distinta'], $h)->assertUnprocessable();
    $this->postJson('/api/v1/auth/password', ['password_actual' => 'password', 'password' => SEG_CLAVE_VALIDA, 'password_confirmation' => SEG_CLAVE_VALIDA], $h)->assertOk();

    $user->refresh();
    expect($user->debe_cambiar_password)->toBeFalse()->and(Hash::check(SEG_CLAVE_VALIDA, $user->password))->toBeTrue();
    expect($user->tokens()->count())->toBe(1);
    $this->getJson('/api/v1/chofer/puntos', $h)->assertOk();
});

test('la contraseña de 9 caracteres o que contiene el correo se rechaza', function () {
    $user = Chofer::factory()->create(['user_id' => User::factory()->create(['email' => 'marcela.rojas@example.com'])->id])->user;
    Sanctum::actingAs($user);

    $this->postJson('/api/v1/auth/password', ['password_actual' => 'password', 'password' => 'Abcdef123', 'password_confirmation' => 'Abcdef123'])->assertUnprocessable();
});

// --- Sesiones ---

test('el token del administrador dura 12 h y el del chofer 30 días', function () {
    $admin = segAdmin();
    $chofer = Chofer::factory()->create()->user;
    $this->travelTo(now()->startOfSecond());

    segLogin($admin);
    segLogin($chofer);

    expect(now()->diffInHours($admin->tokens()->first()->expires_at))->toBe(12.0);
    expect(now()->diffInDays($chofer->tokens()->first()->expires_at))->toBe(30.0);
});

test('un token vencido responde 401', function () {
    $user = Chofer::factory()->create()->user;
    $token = segLogin($user)->json('token');
    $this->travel(31)->days();

    $this->getJson('/api/v1/auth/me', ['Authorization' => "Bearer {$token}"])->assertUnauthorized();
});

// --- 2FA ---

function segActivarDosPasos(User $user): array
{
    Sanctum::actingAs($user);
    $secreto = test()->postJson('/api/v1/auth/dos-pasos/iniciar')->assertOk()->assertJsonStructure(['secreto', 'url_otpauth', 'qr_svg'])->json('secreto');
    $codigo = app(Google2FA::class)->getCurrentOtp($secreto);
    $codigos = test()->postJson('/api/v1/auth/dos-pasos/confirmar', ['codigo' => $codigo])->assertOk()->json('codigos_recuperacion');

    return [$secreto, $codigos];
}

test('activar 2FA entrega 8 códigos y el login pide el código', function () {
    $admin = segAdmin();
    [$secreto, $codigos] = segActivarDosPasos($admin);
    expect($codigos)->toHaveCount(8);
    $this->app['auth']->forgetGuards();

    $respuesta = segLogin($admin)->assertOk()->assertJsonPath('requiere_dos_pasos', true)->assertJsonMissingPath('token');
    $desafio = $respuesta->json('desafio');

    $this->postJson('/api/v1/auth/dos-pasos/desafio', ['desafio' => $desafio, 'codigo' => '000000'])->assertUnauthorized();
    Cache::flush();
    $this->postJson('/api/v1/auth/dos-pasos/desafio', ['desafio' => $desafio, 'codigo' => app(Google2FA::class)->getCurrentOtp($secreto)])->assertUnauthorized();
});

test('un código de recuperación sirve una sola vez', function () {
    $admin = segAdmin();
    [, $codigos] = segActivarDosPasos($admin);
    $this->app['auth']->forgetGuards();

    $desafio = segLogin($admin)->json('desafio');
    $this->postJson('/api/v1/auth/dos-pasos/desafio', ['desafio' => $desafio, 'codigo_recuperacion' => $codigos[0]])->assertOk()->assertJsonStructure(['token']);
    expect($admin->fresh()->recoveryCodes())->toHaveCount(7);

    $desafio = segLogin($admin)->json('desafio');
    $this->postJson('/api/v1/auth/dos-pasos/desafio', ['desafio' => $desafio, 'codigo_recuperacion' => $codigos[0]])->assertUnauthorized();
});

test('el código 2FA comparte el límite de intentos y anula el desafío', function () {
    $admin = segAdmin();
    segActivarDosPasos($admin);
    $this->app['auth']->forgetGuards();
    $desafio = segLogin($admin)->json('desafio');

    foreach (range(1, 5) as $_) {
        $this->postJson('/api/v1/auth/dos-pasos/desafio', ['desafio' => $desafio, 'codigo' => '111111'])->assertUnauthorized();
    }
    $this->postJson('/api/v1/auth/dos-pasos/desafio', ['desafio' => $desafio, 'codigo' => '111111'])
        ->assertUnauthorized()->assertJsonPath('message', 'La verificación expiró. Inicia sesión de nuevo.');

    $nuevo = segLogin($admin)->json('desafio');
    $this->postJson('/api/v1/auth/dos-pasos/desafio', ['desafio' => $nuevo, 'codigo' => '111111'])->assertStatus(429);
});

test('confirmar con código incorrecto no activa el 2FA y el chofer no puede usarlo', function () {
    $admin = segAdmin();
    Sanctum::actingAs($admin);
    $this->postJson('/api/v1/auth/dos-pasos/iniciar')->assertOk();
    $this->postJson('/api/v1/auth/dos-pasos/confirmar', ['codigo' => '123456'])->assertUnprocessable();
    expect($admin->fresh()->hasEnabledTwoFactorAuthentication())->toBeFalse();

    Sanctum::actingAs(Chofer::factory()->create()->user);
    $this->postJson('/api/v1/auth/dos-pasos/iniciar')->assertForbidden();
});

test('apagar el propio 2FA pide la contraseña', function () {
    $admin = segAdmin();
    segActivarDosPasos($admin);

    $this->deleteJson('/api/v1/auth/dos-pasos', ['password_actual' => 'mala'])->assertUnprocessable();
    $this->deleteJson('/api/v1/auth/dos-pasos', ['password_actual' => 'password'])->assertOk();
    expect($admin->fresh()->hasEnabledTwoFactorAuthentication())->toBeFalse();
});

// --- Demo ---

test('las cuentas demo protegidas no cambian contraseña ni activan 2FA', function () {
    config(['app.demo_mode' => true]);
    $admin = segAdmin(['cuenta_protegida' => true]);
    Sanctum::actingAs($admin);

    $this->postJson('/api/v1/auth/password', ['password_actual' => 'password', 'password' => SEG_CLAVE_VALIDA, 'password_confirmation' => SEG_CLAVE_VALIDA])
        ->assertForbidden()->assertJsonPath('message', 'No disponible en la demostración.');
    $this->postJson('/api/v1/auth/dos-pasos/iniciar')->assertForbidden();
    $this->getJson('/api/v1/auth/me')->assertJsonPath('data.cuenta_protegida', true);
});

test('en demo se crean choferes nuevos y se restablece su contraseña, no la de los sembrados', function () {
    config(['app.demo_mode' => true]);
    Sanctum::actingAs(segAdmin(['cuenta_protegida' => true]));

    $this->postJson('/api/v1/choferes', segDatosChofer())->assertCreated();
    $nuevo = Chofer::where('user_id', User::where('email', 'nuevo@example.com')->value('id'))->first();
    $this->postJson("/api/v1/choferes/{$nuevo->id}/restablecer-password")->assertOk();

    $sembrado = Chofer::factory()->create(['user_id' => User::factory()->create(['cuenta_protegida' => true])->id]);
    $this->postJson("/api/v1/choferes/{$sembrado->id}/restablecer-password")->assertForbidden();
});

test('la gestión de administradores no existe en demo', function () {
    config(['app.demo_mode' => true]);
    Sanctum::actingAs(segAdmin());

    $this->getJson('/api/v1/administradores')->assertForbidden();
});

test('los comandos de administradores se niegan en demo', function () {
    config(['app.demo_mode' => true]);
    Artisan::call('ecoruta:crear-admin');
    expect(Artisan::output())->toContain('APP_DEMO_MODE=true');

    $demo = segAdmin(['cuenta_protegida' => true]);
    expect(Artisan::call('ecoruta:restablecer-admin', ['email' => $demo->email]))->toBe(1);
});

// --- Administradores (modo real) ---

test('un administrador crea a otro con contraseña temporal confirmando la suya', function () {
    $yo = segAdmin();
    Sanctum::actingAs($yo);
    $datos = ['nombre' => 'Otra Admin', 'email' => 'otra@example.com'];

    $this->postJson('/api/v1/administradores', $datos)->assertUnprocessable();
    $this->postJson('/api/v1/administradores', [...$datos, 'password_actual' => 'mala'])->assertUnprocessable();
    $r = $this->postJson('/api/v1/administradores', [...$datos, 'password_actual' => 'password'])->assertCreated();

    $otra = User::where('email', 'otra@example.com')->first();
    expect($otra->role)->toBe('administrador')->and($otra->debe_cambiar_password)->toBeTrue()->and(Hash::check($r->json('password'), $otra->password))->toBeTrue();
});

test('restablecer a otro admin revoca sus sesiones; no se puede desactivar al último activo', function () {
    $yo = segAdmin();
    $otro = segAdmin();
    $otro->createToken('x');
    Sanctum::actingAs($yo);
    $adminOtro = $otro->administrador;

    $this->postJson("/api/v1/administradores/{$adminOtro->id}/restablecer-password", ['password_actual' => 'password'])->assertOk()->assertJsonStructure(['password']);
    expect($otro->tokens()->count())->toBe(0);

    $this->postJson("/api/v1/administradores/{$adminOtro->id}/desactivar", ['password_actual' => 'password'])->assertOk();
    $this->postJson("/api/v1/administradores/{$yo->administrador->id}/desactivar", ['password_actual' => 'password'])->assertUnprocessable();

    // El segundo admin se reactiva, el primero se desactiva a sí mismo: bloqueado; y el único activo no cae.
    $this->postJson("/api/v1/administradores/{$adminOtro->id}/reactivar", ['password_actual' => 'password'])->assertOk();
    $this->postJson("/api/v1/administradores/{$adminOtro->id}/desactivar", ['password_actual' => 'password'])->assertOk();
    expect(User::where('role', 'administrador')->where('is_active', true)->count())->toBe(1);
});

test('el último administrador activo no se puede desactivar', function () {
    $yo = segAdmin();
    $inactivo = segAdmin(['is_active' => false]);
    Sanctum::actingAs($yo);

    $this->postJson("/api/v1/administradores/{$yo->administrador->id}/desactivar", ['password_actual' => 'password'])->assertUnprocessable();
    expect(app(GestorDeCuentas::class)->esUltimoAdministradorActivo($yo))->toBeTrue();
    expect(app(GestorDeCuentas::class)->esUltimoAdministradorActivo($inactivo))->toBeFalse();
});

test('un admin desactiva el 2FA de otro con auditoría', function () {
    $yo = segAdmin();
    $otro = segAdmin();
    segActivarDosPasos($otro);
    Sanctum::actingAs($yo);

    $this->deleteJson("/api/v1/administradores/{$otro->administrador->id}/dos-pasos", ['password_actual' => 'password'])->assertOk();

    expect($otro->fresh()->hasEnabledTwoFactorAuthentication())->toBeFalse();
    expect(AuditLog::where('accion', 'dos_pasos_desactivado')->latest('id')->first()->detalles['origen'])->toBe('otro_administrador');
});

test('el chofer no accede a la gestión de administradores', function () {
    Sanctum::actingAs(Chofer::factory()->create()->user);

    $this->getJson('/api/v1/administradores')->assertForbidden();
});

test('ecoruta:crear-admin y restablecer-admin funcionan en modo real', function () {
    $this->artisan('ecoruta:crear-admin')
        ->expectsQuestion('Nombre completo', 'Ana Díaz')
        ->expectsQuestion('Correo de acceso', 'ana@example.com')
        ->expectsQuestion('Cargo (opcional)', '')
        ->assertSuccessful();
    $ana = User::where('email', 'ana@example.com')->first();
    expect($ana->debe_cambiar_password)->toBeTrue()->and($ana->role)->toBe('administrador');

    $ana->createToken('x');
    $this->artisan('ecoruta:restablecer-admin', ['email' => 'ana@example.com', '--sin-2fa' => true])->assertSuccessful();
    expect($ana->tokens()->count())->toBe(0);
    $this->artisan('ecoruta:restablecer-admin', ['email' => 'nadie@example.com'])->assertFailed();
});

// --- Recuperación por correo ---

test('sin correo configurado la recuperación no existe', function () {
    $this->getJson('/api/v1/auth/opciones')->assertJsonPath('recuperacion_por_correo', false);
    $this->postJson('/api/v1/auth/recuperacion/solicitar', ['email' => 'a@b.cl'])->assertNotFound();

    config(['ecoruta.recuperacion_por_correo.habilitada' => true, 'mail.default' => 'log']);
    $this->getJson('/api/v1/auth/opciones')->assertJsonPath('recuperacion_por_correo', false);
});

test('la recuperación por correo es neutra, solo para administradores y de un solo uso', function () {
    config(['ecoruta.recuperacion_por_correo.habilitada' => true, 'mail.default' => 'smtp']);
    Notification::fake();
    $admin = segAdmin();
    $chofer = Chofer::factory()->create()->user;
    $this->getJson('/api/v1/auth/opciones')->assertJsonPath('recuperacion_por_correo', true);

    $a = $this->postJson('/api/v1/auth/recuperacion/solicitar', ['email' => $admin->email])->assertOk()->json();
    $b = $this->postJson('/api/v1/auth/recuperacion/solicitar', ['email' => 'nadie@example.com'])->assertOk()->json();
    $c = $this->postJson('/api/v1/auth/recuperacion/solicitar', ['email' => $chofer->email])->assertOk()->json();
    expect($a)->toBe($b)->toBe($c);
    Notification::assertSentTo($admin, RestablecerPasswordAdministrador::class);
    Notification::assertNotSentTo($chofer, RestablecerPasswordAdministrador::class);

    $token = null;
    Notification::assertSentTo($admin, RestablecerPasswordAdministrador::class, function ($n) use (&$token) {
        $token = (new ReflectionProperty($n, 'token'))->getValue($n);

        return true;
    });
    $admin->createToken('x');
    Cache::flush();
    $cuerpo = ['email' => $admin->email, 'token' => $token, 'password' => SEG_CLAVE_VALIDA, 'password_confirmation' => SEG_CLAVE_VALIDA];
    $this->postJson('/api/v1/auth/recuperacion/restablecer', [...$cuerpo, 'token' => 'falso'])->assertUnprocessable();
    $this->postJson('/api/v1/auth/recuperacion/restablecer', $cuerpo)->assertOk();
    $this->postJson('/api/v1/auth/recuperacion/restablecer', $cuerpo)->assertUnprocessable();
    expect($admin->tokens()->count())->toBe(0);
    segLogin($admin, SEG_CLAVE_VALIDA)->assertOk();
});

test('la recuperación por correo tiene límite de intentos', function () {
    config(['ecoruta.recuperacion_por_correo.habilitada' => true, 'mail.default' => 'smtp']);
    Notification::fake();

    foreach (range(1, 5) as $_) {
        $this->postJson('/api/v1/auth/recuperacion/solicitar', ['email' => 'x@example.com'])->assertOk();
    }
    $this->postJson('/api/v1/auth/recuperacion/solicitar', ['email' => 'x@example.com'])->assertStatus(429);
});
