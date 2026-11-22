<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\ScopesByCompany;
use App\Http\Controllers\Controller;
use App\Services\CargaMasivaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Emisión masiva desde CSV/XLSX.
 */
class BulkController extends Controller
{
    use ScopesByCompany;

    public function __construct(private CargaMasivaService $cargaMasivaService)
    {
    }

    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'company_id' => ['required', 'integer'],
            'branch_id' => ['nullable', 'integer'],
            'archivo' => ['required', 'file', 'mimes:csv,txt,xlsx', 'max:10240'],
            'enviar_sunat' => ['nullable'],
        ]);

        if (! $this->userCanAccessCompany($request->user(), (int) $request->company_id)) {
            return $this->forbiddenCompany();
        }

        $resultado = $this->cargaMasivaService->procesar(
            $request->file('archivo'),
            (int) $request->company_id,
            $request->filled('branch_id') ? (int) $request->branch_id : null,
            $request->boolean('enviar_sunat'),
        );

        $emitidos = count($resultado['emitidos']);
        $errores = count($resultado['errores']);

        return response()->json([
            'success' => $errores === 0,
            'data' => $resultado,
            'message' => $errores === 0
                ? "Se emitieron {$emitidos} comprobante(s) correctamente"
                : "Se emitieron {$emitidos} comprobante(s) y {$errores} fila(s) fallaron",
        ], $errores === 0 ? 200 : 207);
    }

    public function plantilla(): Response
    {
        return response($this->cargaMasivaService->plantilla(), 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="plantilla-carga-masiva.csv"',
        ]);
    }
}
