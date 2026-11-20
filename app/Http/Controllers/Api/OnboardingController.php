<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;

/**
 * Onboarding self-service: una empresa se registra sola y queda lista para
 * emitir, sin que un super admin tenga que crearla a mano.
 *
 * Crea la empresa, su sucursal principal, el usuario administrador y (si se
 * envían) las credenciales SOL y el certificado digital propios de la empresa.
 */
class OnboardingController extends Controller
{
    public function register(Request $request): JsonResponse
    {
        $request->validate([
            // Usuario administrador de la empresa
            'name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:users,email',
            'password' => ['required', Password::min(8)->letters()->numbers()],

            // Empresa
            'ruc' => 'required|string|size:11|unique:companies,ruc',
            'razon_social' => 'required|string|max:255',
            'nombre_comercial' => 'nullable|string|max:255',
            'direccion' => 'required|string|max:255',
            'ubigeo' => 'required|string|size:6',
            'distrito' => 'required|string|max:100',
            'provincia' => 'required|string|max:100',
            'departamento' => 'required|string|max:100',
            'telefono' => 'nullable|string|max:20',
            'email_empresa' => 'nullable|email|max:255',

            // Accesos SUNAT (opcionales: pueden completarse después)
            'usuario_sol' => 'nullable|string|max:50',
            'clave_sol' => 'nullable|string|max:100',
            'certificado_pem' => 'nullable|file|mimes:pem,crt,cer,txt|max:2048',
            'certificado_password' => 'nullable|string|max:100',
        ]);

        // El rol company_admin debe existir; se crea en el seeder de roles.
        $rolAdmin = Role::where('name', 'company_admin')->first();

        if (! $rolAdmin) {
            return response()->json([
                'success' => false,
                'message' => 'El sistema no está inicializado (faltan los roles). Ejecuta el seeder de roles y permisos.',
            ], 409);
        }

        try {
            $resultado = DB::transaction(function () use ($request, $rolAdmin) {
                $company = Company::create([
                    'ruc' => $request->ruc,
                    'razon_social' => $request->razon_social,
                    'nombre_comercial' => $request->nombre_comercial,
                    'direccion' => $request->direccion,
                    'ubigeo' => $request->ubigeo,
                    'distrito' => $request->distrito,
                    'provincia' => $request->provincia,
                    'departamento' => $request->departamento,
                    'telefono' => $request->telefono,
                    'email' => $request->email_empresa,
                    'usuario_sol' => $request->usuario_sol,
                    'clave_sol' => $request->clave_sol,
                    'certificado_pem' => $this->leerCertificado($request),
                    'certificado_password' => $request->certificado_password,
                    'modo_produccion' => false,
                    'activo' => true,
                ]);

                $branch = Branch::create([
                    'company_id' => $company->id,
                    'codigo' => '0000',
                    'nombre' => 'Sucursal Principal',
                    'direccion' => $company->direccion,
                    'ubigeo' => $company->ubigeo,
                    'distrito' => $company->distrito,
                    'provincia' => $company->provincia,
                    'departamento' => $company->departamento,
                    'activo' => true,
                ]);

                $user = User::create([
                    'name' => $request->name,
                    'email' => $request->email,
                    'password' => Hash::make($request->password),
                    'role_id' => $rolAdmin->id,
                    'company_id' => $company->id,
                    'user_type' => 'user',
                    'active' => true,
                    'email_verified_at' => now(),
                ]);

                return ['company' => $company, 'branch' => $branch, 'user' => $user];
            });
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'No se pudo completar el registro: ' . $e->getMessage(),
            ], 500);
        }

        $user = $resultado['user'];
        $abilities = $user->role ? $user->role->getAllPermissions() : ['*'];
        $token = $user->createToken('API_ACCESS_TOKEN', $abilities)->plainTextToken;

        return response()->json([
            'success' => true,
            'message' => 'Registro completado. Ya puedes emitir comprobantes.',
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $rolAdmin->display_name,
                'company_id' => $resultado['company']->id,
            ],
            'company' => [
                'id' => $resultado['company']->id,
                'ruc' => $resultado['company']->ruc,
                'razon_social' => $resultado['company']->razon_social,
                'has_certificate' => $resultado['company']->hasCertificado(),
                'branch_id' => $resultado['branch']->id,
            ],
            'access_token' => $token,
            'token_type' => 'Bearer',
        ], 201);
    }

    private function leerCertificado(Request $request): ?string
    {
        if (! $request->hasFile('certificado_pem')) {
            return null;
        }

        $contenido = file_get_contents($request->file('certificado_pem')->getRealPath());

        if ($contenido === false || ! str_contains($contenido, 'BEGIN CERTIFICATE')) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'certificado_pem' => 'El archivo no parece un certificado PEM válido (falta -----BEGIN CERTIFICATE-----).',
            ]);
        }

        return $contenido;
    }
}
