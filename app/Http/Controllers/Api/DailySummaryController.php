<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Traits\HandlesDocumentDownloads;
use App\Http\Controllers\Traits\SendsToSunat;
use App\Http\Requests\Boleta\CreateDailySummaryRequest;
use App\Http\Requests\IndexDailySummaryRequest;
use App\Models\DailySummary;
use App\Services\DocumentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

/**
 * Resúmenes diarios de boletas (comunicación de bajas/altas).
 *
 * Agrupa las boletas PENDIENTES de una fecha, las envía a SUNAT como resumen
 * y consulta el ticket hasta obtener el CDR.
 */
class DailySummaryController extends Controller
{
    use HandlesDocumentDownloads;
    use SendsToSunat;

    public function __construct(private DocumentService $documentService) {}

    public function index(IndexDailySummaryRequest $request): JsonResponse
    {
        $query = DailySummary::with(['company', 'branch']);

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
            $query->whereBetween('fecha_resumen', [$request->fecha_desde, $request->fecha_hasta]);
        }

        $resumenes = $query->orderByDesc('created_at')->paginate($request->integer('per_page', 15));

        return response()->json([
            'success' => true,
            'data' => $resumenes,
            'message' => 'Resúmenes diarios obtenidos correctamente',
        ]);
    }

    public function store(CreateDailySummaryRequest $request): JsonResponse
    {
        try {
            $summary = $this->documentService->createSummaryFromBoletas($request->validated());

            return response()->json([
                'success' => true,
                'data' => $summary->load(['company', 'branch', 'boletas']),
                'message' => 'Resumen diario creado correctamente',
            ], 201);
        } catch (Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al crear el resumen diario: ' . $e->getMessage(),
            ], 422);
        }
    }

    public function show($id): JsonResponse
    {
        $summary = DailySummary::with(['company', 'branch', 'boletas'])->findOrFail($id);

        return response()->json([
            'success' => true,
            'data' => $summary,
            'message' => 'Resumen diario obtenido correctamente',
        ]);
    }

    public function sendToSunat($id): JsonResponse
    {
        $summary = DailySummary::findOrFail($id);

        return $this->sendDocumentToSunat(
            $summary,
            'Resumen diario',
            fn (DocumentService $service, $document) => $service->sendDailySummaryToSunat($document)
        );
    }

    public function checkStatus($id): JsonResponse
    {
        $summary = DailySummary::findOrFail($id);

        if ($summary->estado_sunat === 'ACEPTADO') {
            return response()->json([
                'success' => true,
                'data' => $summary->load(['company', 'branch', 'boletas']),
                'message' => 'El resumen ya fue aceptado por SUNAT',
            ]);
        }

        $result = $this->documentService->checkSummaryStatus($summary);

        if ($result['success']) {
            return response()->json([
                'success' => true,
                'data' => $result['document']->load(['company', 'branch', 'boletas']),
                'message' => 'Estado del resumen consultado correctamente',
            ]);
        }

        return response()->json([
            'success' => false,
            'message' => 'Error al consultar estado: ' . ($result['error'] ?? 'Error desconocido'),
        ], 400);
    }

    public function downloadXml($id)
    {
        return $this->downloadDocumentXml(DailySummary::findOrFail($id));
    }

    public function downloadCdr($id)
    {
        return $this->downloadDocumentCdr(DailySummary::findOrFail($id));
    }

    /**
     * Boletas PENDIENTES de una fecha que aún no entran en ningún resumen.
     */
    public function pending(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'company_id' => 'required|exists:companies,id',
            'branch_id' => 'required|exists:branches,id',
            'fecha_emision' => 'required|date',
        ]);

        $boletas = \App\Models\Boleta::with(['client'])
            ->where('company_id', $validated['company_id'])
            ->where('branch_id', $validated['branch_id'])
            ->whereDate('fecha_emision', $validated['fecha_emision'])
            ->where('estado_sunat', 'PENDIENTE')
            ->whereNull('daily_summary_id')
            ->orderBy('correlativo')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $boletas,
            'total' => $boletas->count(),
            'message' => 'Boletas pendientes obtenidas correctamente',
        ]);
    }
}
