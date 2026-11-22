<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Administrador;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\Cuentas\GestorDeCuentas;
use App\Services\Cuentas\VerificadorDeDosPasos;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Gestión de administradores (solo ambiente real). Toda acción sobre otra
 * cuenta exige confirmar la contraseña de quien la ejecuta y queda auditada.
 */
class AdministradorController extends Controller
{
    public function __construct(
        private readonly GestorDeCuentas $cuentas,
        private readonly VerificadorDeDosPasos $dosPasos,
    ) {}

    public function index(): JsonResponse
    {
        $datos = Administrador::with('user')->orderBy('id')->get()
            ->map(fn (Administrador $admin): array => $this->resumen($admin))
            ->all();

        return response()->json(['data' => $datos]);
    }

    public function store(Request $request): JsonResponse
    {
        $datos = $request->validate([
            'nombre' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'cargo' => ['nullable', 'string', 'max:255'],
            'telefono' => ['nullable', 'string', 'max:20'],
            'password_actual' => ['required', 'string'],
        ]);

        if ($falla = $this->confirmarPropia($request, $datos['password_actual'])) {
            return $falla;
        }

        $temporal = $this->cuentas->generarPassword();

        $admin = DB::transaction(function () use ($datos, $temporal): Administrador {
            $user = User::create([
                'name' => $datos['nombre'],
                'email' => strtolower(trim($datos['email'])),
                'password' => $temporal,
                'role' => 'administrador',
                'is_active' => true,
                'debe_cambiar_password' => true,
                'password_cambiada_en' => now(),
            ]);

            return $user->administrador()->create([
                'nombre_completo' => $datos['nombre'],
                'cargo' => $datos['cargo'] ?? null,
                'telefono' => $datos['telefono'] ?? null,
            ]);
        });

        AuditLog::registrar('administrador_creado', $request->user(), $request, ['administrador_id' => $admin->id]);

        return response()->json([
            'data' => $this->resumen($admin->load('user')),
            'password' => $temporal,
        ], 201);
    }

    public function restablecerPassword(Request $request, Administrador $administrador): JsonResponse
    {
        if ($falla = $this->validarAccion($request, $administrador)) {
            return $falla;
        }

        if ($administrador->user_id === $request->user()->id) {
            return response()->json(['message' => 'Cambia tu propia contraseña desde Ajustes.'], 422);
        }

        $temporal = $this->cuentas->generarPassword();
        $this->cuentas->fijarPassword($administrador->user, $temporal, true);
        AuditLog::registrar('administrador_password_restablecida', $request->user(), $request, [
            'administrador_id' => $administrador->id,
            'sesiones_revocadas' => true,
        ]);

        return response()->json(['password' => $temporal, 'debe_cambiar_password' => true]);
    }

    public function desactivar(Request $request, Administrador $administrador): JsonResponse
    {
        if ($falla = $this->validarAccion($request, $administrador)) {
            return $falla;
        }

        if ($administrador->user_id === $request->user()->id) {
            return response()->json(['message' => 'No puedes desactivar tu propia cuenta.'], 422);
        }

        if ($this->cuentas->esUltimoAdministradorActivo($administrador->user)) {
            return response()->json(['message' => 'No se puede desactivar al último administrador activo.'], 409);
        }

        $administrador->user->update(['is_active' => false]);
        $this->cuentas->revocarTokens($administrador->user);
        AuditLog::registrar('administrador_desactivado', $request->user(), $request, ['administrador_id' => $administrador->id]);

        return response()->json(['data' => $this->resumen($administrador->fresh('user'))]);
    }

    public function reactivar(Request $request, Administrador $administrador): JsonResponse
    {
        if ($falla = $this->validarAccion($request, $administrador)) {
            return $falla;
        }

        $administrador->user->update(['is_active' => true]);
        AuditLog::registrar('administrador_reactivado', $request->user(), $request, ['administrador_id' => $administrador->id]);

        return response()->json(['data' => $this->resumen($administrador->fresh('user'))]);
    }

    public function desactivarDosPasos(Request $request, Administrador $administrador): JsonResponse
    {
        if ($falla = $this->validarAccion($request, $administrador)) {
            return $falla;
        }

        $this->dosPasos->desactivar($administrador->user);
        $this->cuentas->revocarTokens($administrador->user);
        AuditLog::registrar('dos_pasos_desactivado', $request->user(), $request, [
            'origen' => 'otro_administrador',
            'administrador_id' => $administrador->id,
        ]);

        return response()->json(['data' => $this->resumen($administrador->fresh('user'))]);
    }

    /**
     * Valida la contraseña propia enviada en la petición y que el objetivo no sea una cuenta demo.
     */
    private function validarAccion(Request $request, Administrador $administrador): ?JsonResponse
    {
        $request->validate(['password_actual' => ['required', 'string']]);

        if ($falla = $this->confirmarPropia($request, (string) $request->input('password_actual'))) {
            return $falla;
        }

        if ($administrador->user->esCuentaDemoProtegida()) {
            return response()->json(['message' => GestorDeCuentas::MENSAJE_DEMO], 403);
        }

        return null;
    }

    private function confirmarPropia(Request $request, string $password): ?JsonResponse
    {
        if (! Hash::check($password, $request->user()->password)) {
            return response()->json([
                'message' => 'Tu contraseña no es correcta.',
                'errors' => ['password_actual' => ['Tu contraseña no es correcta.']],
            ], 422);
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    private function resumen(Administrador $admin): array
    {
        $user = $admin->user;

        return [
            'id' => $admin->id,
            'user_id' => $admin->user_id,
            'nombre' => $admin->nombre_completo,
            'cargo' => $admin->cargo,
            'email' => $user->email,
            'is_active' => $user->is_active,
            'debe_cambiar_password' => $user->debe_cambiar_password,
            'dos_pasos_activo' => $user->hasEnabledTwoFactorAuthentication(),
        ];
    }
}
