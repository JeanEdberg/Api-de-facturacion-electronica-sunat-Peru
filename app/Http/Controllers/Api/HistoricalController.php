<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\ScopesByCompany;
use App\Http\Controllers\Controller;
use App\Services\ImportacionHistoricaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Importación de comprobantes de períodos anteriores.
 */
class HistoricalController extends Controller
{
    use ScopesByCompany;

    public function __construct(private ImportacionHistoricaService $historicoService)
    {
    }

    public function importar(Request $request): JsonResponse
    {
        $request->validate([
            'company_id' => ['required', 'integer'],
            'branch_id' => ['nullable', 'integer'],
            'tipo' => ['nullable', 'in:ventas,compras'],
            'archivo' => ['required', 'file', 'mimes:csv,txt,xlsx', 'max:10240'],
        ]);

        if (! $this->userCanAccessCompany($request->user(), (int) $request->company_id)) {
            return $this->forbiddenCompany();
        }

        $resultado = $this->historicoService->importar(
            $request->file('archivo'),
            (int) $request->company_id,
            $request->filled('branch_id') ? (int) $request->branch_id : null,
            (string) $request->get('tipo', 'ventas'),
        );

        $errores = count($resultado['errores']);

        return response()->json([
            'success' => $errores === 0,
            'data' => $resultado,
            'message' => $errores === 0
                ? "Importación completada: {$resultado['importados']} comprobante(s)"
                : "Importación completada con {$errores} fila(s) con error",
        ], $errores === 0 ? 200 : 207);
    }

    public function plantilla(Request $request): Response
    {
        $tipo = $request->get('tipo') === 'compras' ? 'compras' : 'ventas';

        return response($this->historicoService->plantilla($tipo), 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => "attachment; filename=\"plantilla-historico-{$tipo}.csv\"",
        ]);
    }
}
