<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Traits\HandlesDocumentDownloads;
use App\Http\Requests\IndexVoidedDocumentRequest;
use App\Http\Requests\StoreVoidedDocumentRequest;
use App\Models\VoidedDocument;
use App\Services\DocumentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

/**
 * Comunicación de baja (anulación) de comprobantes.
 *
 * Agrupa documentos ACEPTADOS de una fecha, arma el resumen RA, lo envía a
 * SUNAT y consulta el ticket hasta obtener el CDR.
 */
class VoidedDocumentController extends Controller
{
    use HandlesDocumentDownloads;

    public function __construct(private DocumentService $documentService) {}

    public function index(IndexVoidedDocumentRequest $request): JsonResponse
    {
        $query = VoidedDocument::with(['company', 'branch']);

        if ($request->filled('company_id')) {
            $query->where('company_id', $request->company_id);
        }
        if ($request->filled('branch_id')) {
            $query->where('branch_id', $request->branch_id);
        }
        if ($request->filled('estado_sunat')) {
            $query->where('estado_sunat', $request->estado_sunat);
        }
        if ($request->filled('fecha_referencia')) {
            $query->whereDate('fecha_referencia', $request->fecha_referencia);
        }
        if ($request->filled('fecha_desde') && $request->filled('fecha_hasta')) {
            $query->whereBetween('fecha_emision', [$request->fecha_desde, $request->fecha_hasta]);
        }

        $bajas = $query->orderByDesc('created_at')->paginate($request->integer('per_page', 15));

        return response()->json([
            'success' => true,
            'data' => $bajas,
            'message' => 'Comunicaciones de baja obtenidas correctamente',
        ]);
    }

    public function store(StoreVoidedDocumentRequest $request): JsonResponse
    {
        try {
            $voided = $this->documentService->createVoidedDocument($request->validated());

            return response()->json([
                'success' => true,
                'data' => $voided->load(['company', 'branch']),
                'message' => 'Comunicación de baja creada correctamente',
            ], 201);
        } catch (Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al crear la comunicación de baja: ' . $e->getMessage(),
            ], 422);
        }
    }

    public function show($id): JsonResponse
    {
        $voided = VoidedDocument::with(['company', 'branch'])->findOrFail($id);

        return response()->json([
            'success' => true,
            'data' => $voided,
            'message' => 'Comunicación de baja obtenida correctamente',
        ]);
    }

    public function sendToSunat($id): JsonResponse
    {
        $voided = VoidedDocument::findOrFail($id);

        $result = $this->documentService->sendVoidedDocumentToSunat($voided);

        if ($result['success']) {
            return response()->json([
                'success' => true,
                'data' => $result['document'],
                'ticket' => $result['ticket'],
                'message' => 'Comunicación de baja enviada correctamente a SUNAT',
            ]);
        }

        return response()->json([
            'success' => false,
            'data' => $result['document'] ?? null,
            'message' => 'Error al enviar la comunicación de baja a SUNAT',
            'error' => $result['error'] ?? null,
        ], 400);
    }

    public function checkStatus($id): JsonResponse
    {
        $voided = VoidedDocument::findOrFail($id);

        if ($voided->estado_sunat === 'ACEPTADO') {
            return response()->json([
                'success' => true,
                'data' => $voided->load(['company', 'branch']),
                'message' => 'La comunicación de baja ya fue aceptada por SUNAT',
            ]);
        }

        $result = $this->documentService->checkVoidedDocumentStatus($voided);

        if ($result['success']) {
            return response()->json([
                'success' => true,
                'data' => $result['document']->load(['company', 'branch']),
                'message' => 'Estado de la comunicación consultado correctamente',
            ]);
        }

        return response()->json([
            'success' => false,
            'message' => 'Error al consultar estado: ' . ($result['error'] ?? 'Error desconocido'),
        ], 400);
    }

    public function downloadXml($id)
    {
        return $this->downloadDocumentXml(VoidedDocument::findOrFail($id));
    }

    public function downloadCdr($id)
    {
        return $this->downloadDocumentCdr(VoidedDocument::findOrFail($id));
    }

    /**
     * Documentos ACEPTADOS de una fecha, candidatos a anular.
     */
    public function candidates(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'company_id' => 'required|exists:companies,id',
            'branch_id' => 'required|exists:branches,id',
            'fecha_referencia' => 'required|date',
            'tipo_documento' => 'nullable|string|in:01,03,07,08,09',
        ]);

        $documents = $this->documentService->getDocumentsForVoiding(
            $validated['company_id'],
            $validated['branch_id'],
            $validated['fecha_referencia'],
            $validated['tipo_documento'] ?? null,
        );

        return response()->json([
            'success' => true,
            'data' => $documents,
            'total' => count($documents),
            'message' => 'Documentos candidatos obtenidos correctamente',
        ]);
    }
}
