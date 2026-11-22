<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\ScopesByCompany;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreRetentionRequest;
use App\Models\Retention;
use App\Services\DocumentService;
use App\Services\FileService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Comprobantes de retención (tipo 20).
 *
 * El agente de retención emite un comprobante por los pagos en los que
 * descuenta el régimen de retención del IGV.
 */
class RetentionController extends Controller
{
    use ScopesByCompany;

    public function __construct(
        private DocumentService $documentService,
        private FileService $fileService,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $query = Retention::with(['company', 'branch', 'proveedor']);
        $this->scopeToUserCompany($query, $request);

        if ($request->filled('branch_id')) {
            $query->where('branch_id', $request->branch_id);
        }
        if ($request->filled('estado_sunat')) {
            $query->where('estado_sunat', $request->estado_sunat);
        }
        if ($request->filled('fecha_desde') && $request->filled('fecha_hasta')) {
            $query->whereBetween('fecha_emision', [$request->fecha_desde, $request->fecha_hasta]);
        }

        $retenciones = $query->orderByDesc('fecha_emision')
            ->orderByDesc('id')
            ->paginate($request->integer('per_page', 15));

        return response()->json([
            'success' => true,
            'data' => $retenciones,
            'message' => 'Retenciones obtenidas correctamente',
        ]);
    }

    public function store(StoreRetentionRequest $request): JsonResponse
    {
        $validated = $request->validated();

        if (! $this->userCanAccessCompany($request->user(), (int) $validated['company_id'])) {
            return $this->forbiddenCompany();
        }

        try {
            $retencion = $this->documentService->createRetention($validated);

            return response()->json([
                'success' => true,
                'data' => $retencion->load(['company', 'branch', 'proveedor']),
                'message' => 'Retención creada correctamente',
            ], 201);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al crear la retención',
                'error' => $e->getMessage(),
            ], 400);
        }
    }

    public function show(int $id): JsonResponse
    {
        $retencion = Retention::with(['company', 'branch', 'proveedor'])->findOrFail($id);

        return response()->json([
            'success' => true,
            'data' => $retencion,
            'message' => 'Retención obtenida correctamente',
        ]);
    }

    public function sendToSunat(int $id): JsonResponse
    {
        $retencion = Retention::with(['company', 'branch', 'proveedor'])->findOrFail($id);

        if ($retencion->estado_sunat === 'ACEPTADO') {
            return response()->json([
                'success' => false,
                'message' => 'La retención ya fue aceptada por SUNAT',
            ], 400);
        }

        $result = $this->documentService->sendRetentionToSunat($retencion);

        return response()->json([
            'success' => $result['success'],
            'data' => $result['document'] ?? $retencion->fresh(),
            'message' => $result['success']
                ? 'Retención enviada correctamente a SUNAT'
                : 'Error al enviar a SUNAT: ' . ($result['error'] ?? 'desconocido'),
        ], $result['success'] ? 200 : 400);
    }

    public function downloadXml(int $id)
    {
        return $this->downloadArtifact(Retention::findOrFail($id), 'xml');
    }

    public function downloadCdr(int $id)
    {
        return $this->downloadArtifact(Retention::findOrFail($id), 'cdr');
    }

    protected function downloadArtifact(Retention $retencion, string $tipo)
    {
        $download = match ($tipo) {
            'xml' => $this->fileService->downloadXml($retencion),
            'cdr' => $this->fileService->downloadCdr($retencion),
            default => null,
        };

        if (! $download) {
            return response()->json([
                'success' => false,
                'message' => strtoupper($tipo) . ' no encontrado',
            ], 404);
        }

        return $download;
    }
}
