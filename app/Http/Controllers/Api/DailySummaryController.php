<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\ScopesByCompany;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Traits\HandlesPdfGeneration;
use App\Models\Boleta;
use App\Models\DailySummary;
use App\Services\DocumentService;
use App\Services\FileService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Resumen diario de boletas (RC).
 *
 * Es el mecanismo por el que SUNAT acepta las boletas emitidas con envío por
 * resumen: se agrupan las pendientes de una fecha, se envía el resumen, se
 * obtiene un ticket y se consulta el estado hasta llegar al CDR.
 */
class DailySummaryController extends Controller
{
    use ScopesByCompany;
    use HandlesPdfGeneration;

    public function __construct(
        private DocumentService $documentService,
        private FileService $fileService,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $query = DailySummary::with(['company', 'branch']);
        $this->scopeToUserCompany($query, $request);

        if ($request->filled('branch_id')) {
            $query->where('branch_id', $request->branch_id);
        }
        if ($request->filled('estado_sunat')) {
            $query->where('estado_sunat', $request->estado_sunat);
        }
        if ($request->filled('fecha_desde') && $request->filled('fecha_hasta')) {
            $query->whereBetween('fecha_resumen', [$request->fecha_desde, $request->fecha_hasta]);
        }

        $resumenes = $query->orderByDesc('fecha_resumen')
            ->orderByDesc('id')
            ->paginate($request->integer('per_page', 15));

        return response()->json([
            'success' => true,
            'data' => $resumenes,
            'message' => 'Resúmenes diarios obtenidos correctamente',
        ]);
    }

    /**
     * Boletas pendientes de una fecha, listas para entrar en un resumen.
     */
    public function pending(Request $request): JsonResponse
    {
        $request->validate([
            'company_id' => ['required', 'integer'],
            'branch_id' => ['required', 'integer'],
            'fecha_emision' => ['required', 'date'],
        ]);

        if (! $this->userCanAccessCompany($request->user(), (int) $request->company_id)) {
            return $this->forbiddenCompany();
        }

        $boletas = Boleta::with('client')
            ->where('company_id', $request->company_id)
            ->where('branch_id', $request->branch_id)
            ->whereDate('fecha_emision', $request->fecha_emision)
            ->where('estado_sunat', 'PENDIENTE')
            ->whereNull('daily_summary_id')
            ->orderBy('id')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $boletas,
            'total' => $boletas->count(),
            'message' => 'Boletas pendientes obtenidas correctamente',
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'company_id' => ['required', 'integer'],
            'branch_id' => ['required', 'integer'],
            'fecha_resumen' => ['required', 'date'],
            'usuario_creacion' => ['nullable', 'string'],
        ]);

        if (! $this->userCanAccessCompany($request->user(), (int) $validated['company_id'])) {
            return $this->forbiddenCompany();
        }

        try {
            $resumen = $this->documentService->createSummaryFromBoletas($validated);

            return response()->json([
                'success' => true,
                'data' => $resumen->load(['company', 'branch']),
                'message' => 'Resumen diario creado correctamente',
            ], 201);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al crear el resumen diario',
                'error' => $e->getMessage(),
            ], 400);
        }
    }

    public function show(int $id): JsonResponse
    {
        $resumen = DailySummary::with(['company', 'branch', 'boletas'])->findOrFail($id);

        return response()->json([
            'success' => true,
            'data' => $resumen,
            'message' => 'Resumen diario obtenido correctamente',
        ]);
    }

    public function sendToSunat(int $id): JsonResponse
    {
        $resumen = DailySummary::with(['company', 'branch'])->findOrFail($id);

        if ($resumen->estado_sunat === 'ACEPTADO') {
            return response()->json([
                'success' => false,
                'message' => 'El resumen ya fue aceptado por SUNAT',
            ], 400);
        }

        $result = $this->documentService->sendDailySummaryToSunat($resumen);

        return response()->json([
            'success' => $result['success'],
            'data' => $result['document'] ?? $resumen->fresh(),
            'message' => $result['success']
                ? 'Resumen enviado correctamente a SUNAT'
                : 'Error al enviar a SUNAT: ' . ($result['error'] ?? 'desconocido'),
        ], $result['success'] ? 200 : 400);
    }

    public function checkStatus(int $id): JsonResponse
    {
        $resumen = DailySummary::findOrFail($id);

        if (! $resumen->ticket) {
            return response()->json([
                'success' => false,
                'message' => 'El resumen todavía no tiene ticket de SUNAT',
            ], 400);
        }

        $result = $this->documentService->checkSummaryStatus($resumen);

        return response()->json([
            'success' => true,
            'data' => $result['document'] ?? $resumen->fresh(),
            'message' => 'Estado consultado correctamente',
        ]);
    }

    public function downloadXml(int $id)
    {
        return $this->downloadArtifact(DailySummary::findOrFail($id), 'xml');
    }

    public function downloadCdr(int $id)
    {
        return $this->downloadArtifact(DailySummary::findOrFail($id), 'cdr');
    }

    public function downloadPdf(int $id, Request $request)
    {
        return $this->downloadDocumentPdf(DailySummary::findOrFail($id), $request);
    }

    protected function downloadArtifact(DailySummary $resumen, string $tipo)
    {
        $download = match ($tipo) {
            'xml' => $this->fileService->downloadXml($resumen),
            'cdr' => $this->fileService->downloadCdr($resumen),
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
