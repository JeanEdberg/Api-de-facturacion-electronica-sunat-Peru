<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Traits\HandlesDocumentDownloads;
use App\Http\Controllers\Traits\SendsToSunat;
use App\Http\Requests\IndexCreditNoteRequest;
use App\Http\Requests\StoreCreditNoteRequest;
use App\Models\CreditNote;
use App\Services\DocumentService;
use App\Services\FileService;
use App\Services\PdfService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class CreditNoteController extends Controller
{
    use HandlesDocumentDownloads;
    use SendsToSunat;

    public function __construct(
        private DocumentService $documentService,
        private PdfService $pdfService,
        private FileService $fileService,
    ) {}

    public function index(IndexCreditNoteRequest $request): JsonResponse
    {
        $query = CreditNote::with(['company', 'branch', 'client']);

        if ($request->filled('company_id')) {
            $query->where('company_id', $request->company_id);
        }
        if ($request->filled('branch_id')) {
            $query->where('branch_id', $request->branch_id);
        }
        if ($request->filled('estado_sunat')) {
            $query->where('estado_sunat', $request->estado_sunat);
        }
        if ($request->filled('tipo_doc_afectado')) {
            $query->where('tipo_doc_afectado', $request->tipo_doc_afectado);
        }
        if ($request->filled('fecha_desde') && $request->filled('fecha_hasta')) {
            $query->whereBetween('fecha_emision', [$request->fecha_desde, $request->fecha_hasta]);
        }

        $notas = $query->orderByDesc('created_at')->paginate($request->integer('per_page', 15));

        return response()->json([
            'success' => true,
            'data' => $notas,
            'message' => 'Notas de crédito obtenidas correctamente',
        ]);
    }

    public function store(StoreCreditNoteRequest $request): JsonResponse
    {
        try {
            $nota = $this->documentService->createCreditNote($request->validated());

            return response()->json([
                'success' => true,
                'data' => $nota->load(['company', 'branch', 'client']),
                'message' => 'Nota de crédito creada correctamente',
            ], 201);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al crear la nota de crédito',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function show($id): JsonResponse
    {
        $nota = CreditNote::with(['company', 'branch', 'client'])->findOrFail($id);

        return response()->json([
            'success' => true,
            'data' => $nota,
            'message' => 'Nota de crédito obtenida correctamente',
        ]);
    }

    public function catalogsMotivos(): JsonResponse
    {
        $motivos = collect(CreditNote::MOTIVOS)
            ->map(fn ($name, $code) => ['code' => $code, 'name' => $name])
            ->values()
            ->all();

        return response()->json([
            'success' => true,
            'data' => $motivos,
            'message' => 'Motivos de nota de crédito obtenidos correctamente',
        ]);
    }

    public function generatePdf($id, Request $request): JsonResponse
    {
        $nota = CreditNote::with(['company', 'branch', 'client'])->findOrFail($id);
        $format = $request->get('format', 'A4');

        if (! $this->pdfService->isValidFormat($format)) {
            return response()->json([
                'success' => false,
                'message' => 'Formato no válido. Formatos disponibles: ' . implode(', ', $this->pdfService->getAvailableFormats()),
            ], 400);
        }

        try {
            $contenido = $this->pdfService->generateCreditNotePdf($nota, $format);
            $pdfPath = $this->fileService->savePdf($nota, $contenido, $format);
            $nota->update(['pdf_path' => $pdfPath]);

            return response()->json([
                'success' => true,
                'message' => 'PDF de nota de crédito generado correctamente',
                'data' => [
                    'id' => $nota->id,
                    'serie' => $nota->serie,
                    'correlativo' => $nota->correlativo,
                    'numero_documento' => $nota->numero_completo,
                    'fecha_emision' => optional($nota->fecha_emision)->format('Y-m-d'),
                    'pdf_path' => $pdfPath,
                    'pdf_url' => Storage::disk('public')->url($pdfPath),
                    'download_url' => Storage::disk('public')->url($pdfPath),
                    'estado_sunat' => $nota->estado_sunat,
                    'mto_imp_venta' => $nota->mto_imp_venta,
                    'moneda' => $nota->moneda,
                    'client' => [
                        'numero_documento' => optional($nota->client)->numero_documento,
                        'razon_social' => optional($nota->client)->razon_social,
                    ],
                ],
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al generar PDF',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function sendToSunat($id): JsonResponse
    {
        $nota = CreditNote::with(['company', 'branch', 'client'])->findOrFail($id);

        return $this->sendDocumentToSunat(
            $nota,
            'Nota de crédito',
            fn (DocumentService $service, $document) => $service->sendToSunat($document, 'credit_note')
        );
    }

    public function downloadXml($id)
    {
        return $this->downloadDocumentXml(CreditNote::findOrFail($id));
    }

    public function downloadCdr($id)
    {
        return $this->downloadDocumentCdr(CreditNote::findOrFail($id));
    }

    public function downloadPdf($id, Request $request)
    {
        return $this->downloadDocumentStoredPdf(CreditNote::findOrFail($id), $request->get('format', 'A4'));
    }
}
