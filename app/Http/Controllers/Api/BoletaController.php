<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Traits\HandlesDocumentDownloads;
use App\Http\Controllers\Traits\HandlesPdfGeneration;
use App\Http\Controllers\Traits\SendsToSunat;
use App\Http\Requests\Boleta\IndexBoletaRequest;
use App\Http\Requests\Boleta\StoreBoletaRequest;
use App\Models\Boleta;
use App\Services\DocumentService;
use App\Services\FileService;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class BoletaController extends Controller
{
    use HandlesDocumentDownloads;
    use HandlesPdfGeneration;
    use SendsToSunat;

    protected DocumentService $documentService;
    protected FileService $fileService;

    public function __construct(DocumentService $documentService, FileService $fileService)
    {
        $this->documentService = $documentService;
        $this->fileService = $fileService;
    }

    /**
     * Listar boletas con filtros
     */
    public function index(IndexBoletaRequest $request): JsonResponse
    {
        try {
            $query = Boleta::with(['company', 'branch', 'client']);
            $this->applyFilters($query, $request);
            
            $perPage = $request->get('per_page', 15);
            $boletas = $query->orderBy('created_at', 'desc')->paginate($perPage);

            return response()->json([
                'success' => true,
                'data' => $boletas->items(),
                'pagination' => $this->getPaginationData($boletas)
            ]);

        } catch (Exception $e) {
            return $this->errorResponse('Error al listar boletas', $e);
        }
    }

    /**
     * Crear nueva boleta
     */
    public function store(StoreBoletaRequest $request): JsonResponse
    {
        try {
            $validated = $request->validated();
            $boleta = $this->documentService->createBoleta($validated);

            return response()->json([
                'success' => true,
                'data' => $boleta->load(['company', 'branch', 'client']),
                'message' => 'Boleta creada correctamente'
            ], 201);

        } catch (Exception $e) {
            return $this->errorResponse('Error al crear la boleta', $e);
        }
    }

    /**
     * Obtener boleta específica
     */
    public function show(string $id): JsonResponse
    {
        try {
            $boleta = Boleta::with(['company', 'branch', 'client'])->findOrFail($id);
            
            return response()->json([
                'success' => true,
                'data' => $boleta
            ]);
        } catch (Exception $e) {
            return $this->notFoundResponse('Boleta no encontrada');
        }
    }

    /**
     * Enviar boleta a SUNAT
     */
    public function sendToSunat(string $id): JsonResponse
    {
        $boleta = Boleta::with(['company', 'branch', 'client'])->findOrFail($id);

        return $this->sendDocumentToSunat(
            $boleta,
            'Boleta',
            fn (DocumentService $service, $document) => $service->sendToSunat($document, 'boleta')
        );
    }

    /**
     * Descargar XML de boleta
     */
    public function downloadXml(string $id)
    {
        return $this->downloadDocumentXml(Boleta::findOrFail($id));
    }

    /**
     * Descargar CDR de boleta
     */
    public function downloadCdr(string $id)
    {
        return $this->downloadDocumentCdr(Boleta::findOrFail($id));
    }

    /**
     * Descargar PDF de boleta
     */
    public function downloadPdf(string $id, Request $request)
    {
        return $this->downloadDocumentStoredPdf(Boleta::findOrFail($id), $request->get('format', 'A4'));
    }

    /**
     * Generar PDF de boleta
     */
    public function generatePdf(string $id, Request $request): Response
    {
        try {
            $boleta = Boleta::with(['company', 'branch', 'client'])->findOrFail($id);
            return $this->generateDocumentPdf($boleta, 'boleta', $request);
        } catch (Exception $e) {
            return $this->errorResponse('Error al generar PDF', $e);
        }
    }

    /**
     * Aplicar filtros a la consulta
     */
    private function applyFilters($query, Request $request): void
    {
        $filters = [
            'company_id' => 'where',
            'branch_id' => 'where',
            'estado_sunat' => 'where',
            'fecha_desde' => 'whereDate|>=',
            'fecha_hasta' => 'whereDate|<='
        ];

        foreach ($filters as $field => $operation) {
            if ($request->has($field)) {
                $parts = explode('|', $operation);
                $method = $parts[0];
                $operator = $parts[1] ?? null;

                if ($operator) {
                    $query->$method('fecha_emision', $operator, $request->$field);
                } else {
                    $query->$method($field, $request->$field);
                }
            }
        }
    }

    /**
     * Obtener datos de paginación
     */
    private function getPaginationData($paginator): array
    {
        return [
            'current_page' => $paginator->currentPage(),
            'last_page' => $paginator->lastPage(),
            'per_page' => $paginator->perPage(),
            'total' => $paginator->total(),
        ];
    }

    /**
     * Respuesta de error estandarizada
     */
    private function errorResponse(string $message, Exception $e): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => $message . ': ' . $e->getMessage()
        ], 500);
    }

    /**
     * Respuesta de no encontrado
     */
    private function notFoundResponse(string $message): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => $message
        ], 404);
    }
}