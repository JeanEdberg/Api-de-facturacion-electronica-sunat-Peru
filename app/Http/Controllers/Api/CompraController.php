<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreCompraRequest;
use App\Models\Compra;
use App\Models\Company;
use App\Services\CompraService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Registro de compras: comprobantes recibidos de proveedores.
 *
 * El listado mantiene la forma de paginador de Laravel ({ data: { data: [...] } })
 * que ya consumen las tablas del frontend.
 */
class CompraController extends Controller
{
    public function __construct(private CompraService $compraService)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $query = Compra::query();

        if ($request->filled('company_id')) {
            $query->where('company_id', $request->company_id);
        }

        if ($request->filled('estado_sunat')) {
            $query->where('estado_sunat', $request->estado_sunat);
        }

        if ($request->filled('proveedor_num_doc')) {
            $query->where('proveedor_num_doc', $request->proveedor_num_doc);
        }

        if ($request->filled('fecha_desde') && $request->filled('fecha_hasta')) {
            $query->whereBetween('fecha_emision', [$request->fecha_desde, $request->fecha_hasta]);
        }

        $compras = $query->orderByDesc('fecha_emision')
            ->orderByDesc('id')
            ->paginate((int) $request->get('per_page', 15));

        return response()->json([
            'success' => true,
            'data' => $compras,
            'message' => 'Compras obtenidas correctamente',
        ]);
    }

    public function store(StoreCompraRequest $request): JsonResponse
    {
        $compra = $this->compraService->registrar($request->validated());

        return response()->json([
            'success' => true,
            'data' => $compra,
            'message' => 'Compra registrada correctamente',
        ], 201);
    }

    public function show($id): JsonResponse
    {
        try {
            $compra = Compra::with('company')->findOrFail($id);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Compra no encontrada',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => $compra,
            'message' => 'Compra obtenida correctamente',
        ]);
    }

    public function destroy($id): JsonResponse
    {
        try {
            $compra = Compra::findOrFail($id);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Compra no encontrada',
            ], 404);
        }

        foreach (['xml_path', 'cdr_path', 'pdf_path'] as $campo) {
            if ($compra->{$campo}) {
                Storage::disk('public')->delete($compra->{$campo});
            }
        }

        $compra->delete();

        return response()->json([
            'success' => true,
            'message' => 'Compra eliminada correctamente',
        ]);
    }

    /**
     * Registra una compra a partir del XML UBL que envió el proveedor.
     */
    public function importarXml(Request $request): JsonResponse
    {
        $request->validate([
            'company_id' => 'required|exists:companies,id',
            'xml' => 'required|file|max:5120',
            'tipo_cambio' => 'nullable|numeric|min:0',
        ]);

        try {
            $company = Company::findOrFail($request->company_id);
            $extra = $request->filled('tipo_cambio') ? ['tipo_cambio' => $request->tipo_cambio] : [];

            $compra = $this->compraService->importarXml(
                $company,
                $request->file('xml'),
                $extra,
            );
        } catch (\RuntimeException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'No se pudo importar el XML',
                'error' => $e->getMessage(),
            ], 500);
        }

        return response()->json([
            'success' => true,
            'data' => $compra,
            'message' => 'Compra importada correctamente',
        ], 201);
    }

    /**
     * Adjunta un artefacto (xml|cdr|pdf) a una compra ya registrada.
     */
    public function adjuntar(Request $request, $id): JsonResponse
    {
        $request->validate([
            'tipo' => 'required|in:xml,cdr,pdf',
            'archivo' => 'required|file|max:10240',
        ]);

        try {
            $compra = Compra::findOrFail($id);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Compra no encontrada',
            ], 404);
        }

        $compra = $this->compraService->adjuntar($compra, $request->tipo, $request->file('archivo'));

        return response()->json([
            'success' => true,
            'data' => $compra,
            'message' => "Archivo {$request->tipo} adjuntado correctamente",
        ]);
    }

    public function downloadXml($id): JsonResponse|StreamedResponse
    {
        return $this->descargar($id, 'xml');
    }

    public function downloadCdr($id): JsonResponse|StreamedResponse
    {
        return $this->descargar($id, 'cdr');
    }

    public function downloadPdf($id): JsonResponse|StreamedResponse
    {
        return $this->descargar($id, 'pdf');
    }

    private function descargar($id, string $tipo): JsonResponse|StreamedResponse
    {
        try {
            $compra = Compra::findOrFail($id);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Compra no encontrada',
            ], 404);
        }

        $ruta = $this->compraService->rutaDescarga($compra, $tipo);

        if (! $ruta) {
            return response()->json([
                'success' => false,
                'message' => strtoupper($tipo) . ' no encontrado',
            ], 404);
        }

        return Storage::disk('public')->download($ruta);
    }
}
