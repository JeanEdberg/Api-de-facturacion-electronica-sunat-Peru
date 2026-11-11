<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Traits\HandlesDocumentDownloads;
use App\Http\Controllers\Traits\SendsToSunat;
use App\Http\Requests\StoreRetentionRequest;
use App\Models\Retention;
use App\Services\DocumentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

/**
 * Comprobantes de retención (tipo 20).
 *
 * El régimen, la tasa y los documentos retenidos vienen en el payload; el
 * correlativo lo asigna el backend por sucursal (tipo '20').
 */
class RetentionController extends Controller
{
    use HandlesDocumentDownloads;
    use SendsToSunat;

    public function __construct(private DocumentService $documentService) {}

    public function index(Request $request): JsonResponse
    {
        $query = Retention::with(['company', 'branch', 'proveedor']);

        if ($request->filled('company_id')) {
            $query->where('company_id', $request->company_id);
        }
        if ($request->filled('branch_id')) {
            $query->where('branch_id', $request->branch_id);
        }
        if ($request->filled('estado_sunat')) {
            $query->where('estado_sunat', $request->estado_sunat);
        }
        if ($request->filled('fecha_desde') && $request->filled('fecha_hasta')) {
            $query->whereBetween('fecha_emision', [$request->fecha_desde, $request->fecha_hasta]);
        }

        $retenciones = $query->orderByDesc('created_at')->paginate($request->integer('per_page', 15));

        return response()->json([
            'success' => true,
            'data' => $retenciones,
            'message' => 'Retenciones obtenidas correctamente',
        ]);
    }

    public function store(StoreRetentionRequest $request): JsonResponse
    {
        try {
            $retention = $this->documentService->createRetention($request->validated());

            return response()->json([
                'success' => true,
                'data' => $retention->load(['company', 'branch', 'proveedor']),
                'message' => 'Retención creada correctamente',
            ], 201);
        } catch (Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al crear la retención: ' . $e->getMessage(),
            ], 422);
        }
    }

    public function show($id): JsonResponse
    {
        $retention = Retention::with(['company', 'branch', 'proveedor'])->findOrFail($id);

        return response()->json([
            'success' => true,
            'data' => $retention,
            'message' => 'Retención obtenida correctamente',
        ]);
    }

    public function sendToSunat($id): JsonResponse
    {
        $retention = Retention::findOrFail($id);

        return $this->sendDocumentToSunat(
            $retention,
            'Retención',
            fn (DocumentService $service, $document) => $service->sendRetentionToSunat($document)
        );
    }

    public function downloadXml($id)
    {
        return $this->downloadDocumentXml(Retention::findOrFail($id));
    }

    public function downloadCdr($id)
    {
        return $this->downloadDocumentCdr(Retention::findOrFail($id));
    }
}
