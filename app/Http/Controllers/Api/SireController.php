<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Services\SireService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Propuesta del SIRE (RVIE ventas / RCE compras) para un periodo.
 *
 * El archivo que se descarga es el mismo que el usuario sube al portal del SIRE;
 * con `?preview=1` se devuelve el contenido en JSON para revisarlo antes.
 */
class SireController extends Controller
{
    public function __construct(private SireService $service) {}

    /**
     * GET /api/v1/sire/ventas?company_id=&periodo=AAAA-MM
     */
    public function ventas(Request $request): JsonResponse|Response
    {
        return $this->responder($request, 'ventas');
    }

    /**
     * GET /api/v1/sire/compras?company_id=&periodo=AAAA-MM
     */
    public function compras(Request $request): JsonResponse|Response
    {
        return $this->responder($request, 'compras');
    }

    private function responder(Request $request, string $libro): JsonResponse|Response
    {
        $request->validate([
            'company_id' => 'nullable|integer|exists:companies,id',
            'periodo' => 'required|string|min:6|max:10',
        ]);

        $company = $this->resolverEmpresa($request);
        if ($company instanceof JsonResponse) {
            return $company;
        }

        $propuesta = $this->service->{$libro}($company, $request->string('periodo'));

        if ($request->boolean('preview')) {
            return response()->json([
                'success' => true,
                'message' => 'Propuesta SIRE generada.',
                'data' => [
                    'filename' => $propuesta['filename'],
                    'registros' => $propuesta['registros'],
                    'contenido' => $propuesta['content'],
                ],
            ]);
        }

        return response($propuesta['content'], 200, [
            'Content-Type' => 'text/plain; charset=windows-1252',
            'Content-Disposition' => 'attachment; filename="' . $propuesta['filename'] . '"',
        ]);
    }

    /**
     * Empresa destino: la enviada (si el usuario tiene acceso) o la del usuario.
     */
    private function resolverEmpresa(Request $request): Company|JsonResponse
    {
        $user = $request->user();
        $companyId = $request->integer('company_id') ?: $user?->company_id;

        if (! $companyId) {
            return response()->json(['success' => false, 'message' => 'No se pudo determinar la empresa.'], 422);
        }

        if (method_exists($user, 'canAccessCompany') && ! $user->canAccessCompany($companyId)) {
            return response()->json(['success' => false, 'message' => 'No autorizado para esa empresa.'], 403);
        }

        return Company::findOrFail($companyId);
    }
}
