<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Traits\HandlesDocumentDownloads;
use App\Http\Controllers\Traits\SendsToSunat;
use App\Http\Requests\IndexDispatchGuideRequest;
use App\Http\Requests\StoreDispatchGuideRequest;
use App\Models\DispatchGuide;
use App\Services\DocumentService;
use App\Services\FileService;
use App\Services\PdfService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class DispatchGuideController extends Controller
{
    use HandlesDocumentDownloads;
    use SendsToSunat;

    public function __construct(
        private DocumentService $documentService,
        private PdfService $pdfService,
        private FileService $fileService,
    ) {}

    public function index(IndexDispatchGuideRequest $request): JsonResponse
    {
        $query = DispatchGuide::with(['company', 'branch', 'destinatario']);

        if ($request->filled('company_id')) {
            $query->where('company_id', $request->company_id);
        }
        if ($request->filled('branch_id')) {
            $query->where('branch_id', $request->branch_id);
        }
        if ($request->filled('estado_sunat')) {
            $query->where('estado_sunat', $request->estado_sunat);
        }
        if ($request->filled('cod_traslado')) {
            $query->where('cod_traslado', $request->cod_traslado);
        }
        if ($request->filled('mod_traslado')) {
            $query->where('mod_traslado', $request->mod_traslado);
        }
        if ($request->filled('fecha_desde') && $request->filled('fecha_hasta')) {
            $query->whereBetween('fecha_emision', [$request->fecha_desde, $request->fecha_hasta]);
        }

        $guias = $query->orderByDesc('created_at')->paginate($request->integer('per_page', 15));

        return response()->json([
            'success' => true,
            'data' => $guias,
            'message' => 'Guías de remisión obtenidas correctamente',
        ]);
    }

    public function store(StoreDispatchGuideRequest $request): JsonResponse
    {
        try {
            $guia = $this->documentService->createDispatchGuide($request->validated());

            return response()->json([
                'success' => true,
                'data' => $guia->load(['company', 'branch', 'destinatario']),
                'message' => 'Guía de remisión creada correctamente',
            ], 201);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al crear la guía de remisión',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function show($id): JsonResponse
    {
        $guia = DispatchGuide::with(['company', 'branch', 'destinatario'])->findOrFail($id);

        return response()->json([
            'success' => true,
            'data' => $guia,
            'message' => 'Guía de remisión obtenida correctamente',
        ]);
    }

    public function catalogsTransferReasons(): JsonResponse
    {
        $reasons = collect(DispatchGuide::MOTIVOS_TRASLADO)
            ->map(fn ($name, $code) => ['code' => $code, 'name' => $name])
            ->values()
            ->all();

        return response()->json([
            'success' => true,
            'data' => $reasons,
            'message' => 'Motivos de traslado obtenidos correctamente',
        ]);
    }

    public function catalogsTransportModes(): JsonResponse
    {
        $modes = collect(DispatchGuide::MODALIDADES_TRASLADO)
            ->map(fn ($name, $code) => ['code' => $code, 'name' => $name])
            ->values()
            ->all();

        return response()->json([
            'success' => true,
            'data' => $modes,
            'message' => 'Modalidades de transporte obtenidas correctamente',
        ]);
    }

    public function generatePdf($id, Request $request): JsonResponse
    {
        $guia = DispatchGuide::with(['company', 'branch', 'destinatario'])->findOrFail($id);
        $format = $request->get('format', 'A4');

        if (! $this->pdfService->isValidFormat($format)) {
            return response()->json([
                'success' => false,
                'message' => 'Formato no válido. Formatos disponibles: ' . implode(', ', $this->pdfService->getAvailableFormats()),
            ], 400);
        }

        try {
            $contenido = $this->pdfService->generateDispatchGuidePdf($guia, $format);
            $pdfPath = $this->fileService->savePdf($guia, $contenido, $format);
            $guia->update(['pdf_path' => $pdfPath]);

            return response()->json([
                'success' => true,
                'message' => 'PDF de guía de remisión generado correctamente',
                'data' => [
                    'id' => $guia->id,
                    'serie' => $guia->serie,
                    'correlativo' => $guia->correlativo,
                    'numero_documento' => $guia->numero_completo,
                    'fecha_emision' => optional($guia->fecha_emision)->format('Y-m-d'),
                    'fecha_traslado' => optional($guia->fecha_traslado)->format('Y-m-d'),
                    'pdf_path' => $pdfPath,
                    'pdf_url' => Storage::disk('public')->url($pdfPath),
                    'download_url' => Storage::disk('public')->url($pdfPath),
                    'estado_sunat' => $guia->estado_sunat,
                    'peso_total' => $guia->peso_total,
                    'modalidad_traslado' => $guia->modalidad_traslado_name,
                    'motivo_traslado' => $guia->motivo_traslado_name,
                    'destinatario' => [
                        'numero_documento' => optional($guia->destinatario)->numero_documento,
                        'razon_social' => optional($guia->destinatario)->razon_social,
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
        $guia = DispatchGuide::with(['company', 'branch', 'destinatario'])->findOrFail($id);

        return $this->sendDocumentToSunat(
            $guia,
            'Guía de remisión',
            fn (DocumentService $service, $document) => $service->sendDispatchGuideToSunat($document)
        );
    }

    public function downloadXml($id)
    {
        return $this->downloadDocumentXml(DispatchGuide::findOrFail($id));
    }

    public function downloadCdr($id)
    {
        return $this->downloadDocumentCdr(DispatchGuide::findOrFail($id));
    }

    public function downloadPdf($id, Request $request)
    {
        return $this->downloadDocumentStoredPdf(DispatchGuide::findOrFail($id), $request->get('format', 'A4'));
    }
}
