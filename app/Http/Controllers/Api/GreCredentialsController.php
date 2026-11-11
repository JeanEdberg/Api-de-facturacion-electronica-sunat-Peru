<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\CopyGreCredentialsRequest;
use App\Http\Requests\GreEnvironmentRequest;
use App\Http\Requests\UpdateGreCredentialsRequest;
use App\Models\Company;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class GreCredentialsController extends Controller
{
    /** Credenciales GRE de prueba publicadas por SUNAT. */
    public const DEFAULTS = [
        'beta' => [
            'client_id' => 'test-85e5b0ae-255c-4891-a595-0b98c65c9854',
            'client_secret' => 'test-Hty/M6QshYvPgItX2P0+Kw==',
            'ruc_proveedor' => '20161515648',
            'usuario_sol' => 'MODDATOS',
            'clave_sol' => 'MODDATOS',
        ],
        'produccion' => [
            'client_id' => null,
            'client_secret' => null,
            'ruc_proveedor' => null,
            'usuario_sol' => null,
            'clave_sol' => null,
        ],
    ];

    public function show($companyId): JsonResponse
    {
        $company = Company::findOrFail($companyId);
        $modo = $company->modo_produccion ? 'produccion' : 'beta';

        return response()->json([
            'success' => true,
            'data' => [
                'company_id' => $company->id,
                'company_name' => $company->razon_social,
                'modo_actual' => $modo,
                'credenciales_configuradas' => $company->hasGreCredentials(),
                'credenciales' => [
                    'beta' => $company->greCredentialsForMode('beta'),
                    'produccion' => $company->greCredentialsForMode('produccion'),
                ],
            ],
            'message' => 'Credenciales GRE obtenidas correctamente',
        ]);
    }

    public function update(UpdateGreCredentialsRequest $request, $companyId): JsonResponse
    {
        $company = Company::findOrFail($companyId);
        $modo = $request->input('modo');

        $company->setGreCredentials($modo, [
            'client_id' => $request->input('client_id'),
            'client_secret' => $request->input('client_secret'),
            'ruc_proveedor' => $request->input('ruc_proveedor'),
            'usuario_sol' => $request->input('usuario_sol'),
            'clave_sol' => $request->input('clave_sol'),
        ]);

        return response()->json([
            'success' => true,
            'data' => $company->fresh()->greCredentialsForMode($modo),
            'message' => "Credenciales GRE para {$modo} actualizadas correctamente",
        ]);
    }

    public function testConnection(Request $request, $companyId): JsonResponse
    {
        $request->validate(['modo' => 'nullable|string|in:beta,produccion']);

        $company = Company::findOrFail($companyId);
        $modo = $request->input('modo') ?? ($company->modo_produccion ? 'produccion' : 'beta');

        if (! $company->hasGreCredentials()) {
            return response()->json([
                'success' => false,
                'message' => 'Las credenciales GRE no están configuradas para esta empresa',
            ], 400);
        }

        $credenciales = $company->greCredentialsForMode($modo);

        return response()->json([
            'success' => true,
            'message' => 'Credenciales GRE verificadas correctamente',
            'data' => [
                'company_id' => $company->id,
                'modo' => $modo,
                'client_id' => $credenciales['client_id'],
                'ruc_proveedor' => $credenciales['ruc_proveedor'],
                'timestamp' => now()->toIso8601String(),
            ],
        ]);
    }

    public function clear(GreEnvironmentRequest $request, $companyId): JsonResponse
    {
        $company = Company::findOrFail($companyId);
        $modo = $request->input('modo');

        $company->clearGreCredentials($modo);

        return response()->json([
            'success' => true,
            'message' => "Credenciales GRE para {$modo} han sido limpiadas",
        ]);
    }

    public function copy(CopyGreCredentialsRequest $request, $companyId): JsonResponse
    {
        $company = Company::findOrFail($companyId);
        $origen = $request->input('origen');
        $destino = $request->input('destino');

        $copiado = $company->copyGreCredentials($origen, $destino);

        if (! $copiado) {
            return response()->json([
                'success' => false,
                'message' => "No hay credenciales GRE configuradas en {$origen}",
            ], 400);
        }

        return response()->json([
            'success' => true,
            'message' => "Credenciales copiadas de {$origen} a {$destino}",
        ]);
    }

    public function defaults(string $modo): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => [
                'modo' => $modo,
                'credenciales_default' => self::DEFAULTS[$modo],
                'descripcion' => $modo === 'beta'
                    ? 'Credenciales de prueba publicadas por SUNAT (solo ambiente beta).'
                    : 'En producción debe usar sus propias credenciales GRE.',
            ],
        ]);
    }
}
