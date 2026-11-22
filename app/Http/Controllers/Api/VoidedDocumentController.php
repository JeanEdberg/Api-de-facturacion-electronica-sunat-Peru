<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\ScopesByCompany;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreVoidedDocumentRequest;
use App\Models\Boleta;
use App\Models\Invoice;
use App\Models\VoidedDocument;
use App\Services\DocumentService;
use App\Services\FileService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Comunicación de baja (RA).
 *
 * Anula comprobantes ya emitidos dentro del plazo que permite SUNAT. El
 * listado de candidatos sólo devuelve documentos ACEPTADOS de la fecha de
 * referencia, porque no tiene sentido anular lo que nunca llegó a SUNAT.
 */
class VoidedDocumentController extends Controller
{
    use ScopesByCompany;

    public function __construct(
        private DocumentService $documentService,
        private FileService $fileService,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $query = VoidedDocument::with(['company', 'branch']);
        $this->scopeToUserCompany($query, $request);

        if ($request->filled('branch_id')) {
            $query->where('branch_id', $request->branch_id);
        }
        if ($request->filled('estado_sunat')) {
            $query->where('estado_sunat', $request->estado_sunat);
        }
        if ($request->filled('fecha_desde') && $request->filled('fecha_hasta')) {
            $query->whereBetween('fecha_referencia', [$request->fecha_desde, $request->fecha_hasta]);
        }

        $bajas = $query->orderByDesc('fecha_referencia')
            ->orderByDesc('id')
            ->paginate($request->integer('per_page', 15));

        return response()->json([
            'success' => true,
            'data' => $bajas,
            'message' => 'Comunicaciones de baja obtenidas correctamente',
        ]);
    }

    /**
     * Documentos ACEPTADOS de una fecha que todavía pueden anularse.
     */
    public function candidates(Request $request): JsonResponse
    {
        $request->validate([
            'company_id' => ['required', 'integer'],
            'branch_id' => ['required', 'integer'],
            'fecha_referencia' => ['required', 'date'],
            'tipo_documento' => ['nullable', 'string', 'max:2'],
        ]);

        if (! $this->userCanAccessCompany($request->user(), (int) $request->company_id)) {
            return $this->forbiddenCompany();
        }

        $tipos = $request->filled('tipo_documento')
            ? [$request->tipo_documento]
            : ['01', '03'];

        $documentos = collect();

        if (in_array('01', $tipos, true)) {
            $documentos = $documentos->concat(
                Invoice::with('client')
                    ->where('company_id', $request->company_id)
                    ->where('branch_id', $request->branch_id)
                    ->whereDate('fecha_emision', $request->fecha_referencia)
                    ->where('estado_sunat', 'ACEPTADO')
                    ->get()
                    ->map(fn ($d) => $this->mapearCandidato($d, '01'))
            );
        }

        if (in_array('03', $tipos, true)) {
            $documentos = $documentos->concat(
                Boleta::with('client')
                    ->where('company_id', $request->company_id)
                    ->where('branch_id', $request->branch_id)
                    ->whereDate('fecha_emision', $request->fecha_referencia)
                    ->where('estado_sunat', 'ACEPTADO')
                    ->get()
                    ->map(fn ($d) => $this->mapearCandidato($d, '03'))
            );
        }

        return response()->json([
            'success' => true,
            'data' => $documentos->values()->all(),
            'total' => $documentos->count(),
            'message' => 'Documentos anulables obtenidos correctamente',
        ]);
    }

    protected function mapearCandidato($documento, string $tipo): array
    {
        return [
            'id' => $documento->id,
            'tipo_documento' => $tipo,
            'serie' => $documento->serie,
            'correlativo' => $documento->correlativo,
            'numero_completo' => $documento->numero_completo,
            'fecha_emision' => optional($documento->fecha_emision)->format('Y-m-d'),
            'cliente_numero' => optional($documento->client)->numero_documento,
            'cliente_razon_social' => optional($documento->client)->razon_social,
            'mto_imp_venta' => $documento->mto_imp_venta,
            'moneda' => $documento->moneda,
            'estado_sunat' => $documento->estado_sunat,
        ];
    }

    public function store(StoreVoidedDocumentRequest $request): JsonResponse
    {
        $validated = $request->validated();

        if (! $this->userCanAccessCompany($request->user(), (int) $validated['company_id'])) {
            return $this->forbiddenCompany();
        }

        try {
            $baja = $this->documentService->createVoidedDocument($validated);

            return response()->json([
                'success' => true,
                'data' => $baja->load(['company', 'branch']),
                'message' => 'Comunicación de baja creada correctamente',
            ], 201);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al crear la comunicación de baja',
                'error' => $e->getMessage(),
            ], 400);
        }
    }

    public function show(int $id): JsonResponse
    {
        $baja = VoidedDocument::with(['company', 'branch'])->findOrFail($id);

        return response()->json([
            'success' => true,
            'data' => $baja,
            'message' => 'Comunicación de baja obtenida correctamente',
        ]);
    }

    public function sendToSunat(int $id): JsonResponse
    {
        $baja = VoidedDocument::with(['company', 'branch'])->findOrFail($id);

        if (in_array($baja->estado_sunat, ['ACEPTADO', 'ENVIADO'], true)) {
            return response()->json([
                'success' => false,
                'message' => 'La comunicación ya fue enviada a SUNAT',
            ], 400);
        }

        $result = $this->documentService->sendVoidedDocumentToSunat($baja);

        return response()->json([
            'success' => $result['success'],
            'data' => $result['document'] ?? $baja->fresh(),
            'message' => $result['success']
                ? 'Comunicación de baja enviada correctamente a SUNAT'
                : 'Error al enviar a SUNAT: ' . (is_object($result['error'] ?? null)
                    ? ($result['error']->message ?? 'desconocido')
                    : ($result['error'] ?? 'desconocido')),
        ], $result['success'] ? 200 : 400);
    }

    public function checkStatus(int $id): JsonResponse
    {
        $baja = VoidedDocument::findOrFail($id);

        if (! $baja->ticket) {
            return response()->json([
                'success' => false,
                'message' => 'La comunicación todavía no tiene ticket de SUNAT',
            ], 400);
        }

        $result = $this->documentService->checkVoidedDocumentStatus($baja);

        return response()->json([
            'success' => true,
            'data' => $result['document'] ?? $baja->fresh(),
            'message' => 'Estado consultado correctamente',
        ]);
    }

    public function downloadXml(int $id)
    {
        return $this->downloadArtifact(VoidedDocument::findOrFail($id), 'xml');
    }

    public function downloadCdr(int $id)
    {
        return $this->downloadArtifact(VoidedDocument::findOrFail($id), 'cdr');
    }

    protected function downloadArtifact(VoidedDocument $baja, string $tipo)
    {
        $download = match ($tipo) {
            'xml' => $this->fileService->downloadXml($baja),
            'cdr' => $this->fileService->downloadCdr($baja),
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
