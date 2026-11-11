<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Traits\HandlesDocumentDownloads;
use App\Http\Controllers\Traits\HandlesPdfGeneration;
use App\Http\Controllers\Traits\SendsToSunat;
use App\Services\DocumentService;
use App\Services\FileService;
use App\Models\Invoice;
use App\Http\Requests\StoreInvoiceRequest;
use App\Http\Requests\IndexInvoiceRequest;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class InvoiceController extends Controller
{
    use HandlesDocumentDownloads;
    use HandlesPdfGeneration;
    use SendsToSunat;

    protected $documentService;
    protected $fileService;

    public function __construct(DocumentService $documentService, FileService $fileService)
    {
        $this->documentService = $documentService;
        $this->fileService = $fileService;
    }

    public function index(IndexInvoiceRequest $request): JsonResponse
    {
        try {
            $query = Invoice::with(['company', 'branch', 'client']);

            // Filtros
            if ($request->has('company_id')) {
                $query->where('company_id', $request->company_id);
            }

            if ($request->has('branch_id')) {
                $query->where('branch_id', $request->branch_id);
            }

            if ($request->has('estado_sunat')) {
                $query->where('estado_sunat', $request->estado_sunat);
            }

            if ($request->has('fecha_desde') && $request->has('fecha_hasta')) {
                $query->whereBetween('fecha_emision', [
                    $request->fecha_desde,
                    $request->fecha_hasta
                ]);
            }

            // Paginación
            $perPage = $request->get('per_page', 15);
            $invoices = $query->orderBy('created_at', 'desc')->paginate($perPage);

            return response()->json([
                'success' => true,
                'data' => $invoices,
                'message' => 'Facturas obtenidas correctamente'
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener las facturas',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function store(StoreInvoiceRequest $request): JsonResponse
    {
        try {
            $validated = $request->validated();

            // Crear la factura
            $invoice = $this->documentService->createInvoice($validated);

            return response()->json([
                'success' => true,
                'data' => $invoice->load(['company', 'branch', 'client']),
                'message' => 'Factura creada correctamente'
            ], 201);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al crear la factura',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function show($id): JsonResponse
    {
        try {
            $invoice = Invoice::with(['company', 'branch', 'client'])->findOrFail($id);

            return response()->json([
                'success' => true,
                'data' => $invoice,
                'message' => 'Factura obtenida correctamente'
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Factura no encontrada',
                'error' => $e->getMessage()
            ], 404);
        }
    }

    public function sendToSunat($id): JsonResponse
    {
        $invoice = Invoice::with(['company', 'branch', 'client'])->findOrFail($id);

        return $this->sendDocumentToSunat(
            $invoice,
            'Factura',
            fn (DocumentService $service, $document) => $service->sendToSunat($document, 'invoice')
        );
    }

    public function downloadXml($id)
    {
        return $this->downloadDocumentXml(Invoice::findOrFail($id));
    }

    public function downloadCdr($id)
    {
        return $this->downloadDocumentCdr(Invoice::findOrFail($id));
    }

    public function downloadPdf($id, Request $request)
    {
        return $this->downloadDocumentStoredPdf(Invoice::findOrFail($id), $request->get('format', 'A4'));
    }


    public function generatePdf($id, Request $request)
    {
        $invoice = Invoice::with(['company', 'branch', 'client'])->findOrFail($id);
        return $this->generateDocumentPdf($invoice, 'invoice', $request);
    }

    protected function processInvoiceDetails(array $detalles, string $tipoOperacion = '0101'): array
    {
        // Para exportaciones (0200), no se debe calcular IGV
        $isExportacion = $tipoOperacion === '0200';

        foreach ($detalles as &$detalle) {
            $cantidad = $detalle['cantidad'];
            $valorUnitario = $detalle['mto_valor_unitario'];
            $porcentajeIgv = $isExportacion ? 0 : ($detalle['porcentaje_igv'] ?? 0);
            $tipAfeIgv = $isExportacion ? '40' : ($detalle['tip_afe_igv'] ?? '10'); // 40 = Exportación

            // Actualizar tipo de afectación para exportaciones
            $detalle['tip_afe_igv'] = $tipAfeIgv;
            $detalle['porcentaje_igv'] = $porcentajeIgv;

            // Calcular valor de venta
            $valorVenta = $cantidad * $valorUnitario;
            $detalle['mto_valor_venta'] = $valorVenta;

            // Para exportaciones - según ejemplo de Greenter
            if ($isExportacion) {
                $detalle['mto_base_igv'] = $valorVenta; // Base IGV = valor venta en exportaciones
                $detalle['igv'] = 0;
                $detalle['total_impuestos'] = 0;
                $detalle['mto_precio_unitario'] = $valorUnitario;
            } else {
                // Calcular base imponible IGV
                $baseIgv = in_array($tipAfeIgv, ['10', '17']) ? $valorVenta : 0;
                $detalle['mto_base_igv'] = $baseIgv;

                // Calcular IGV
                $igv = ($baseIgv * $porcentajeIgv) / 100;
                $detalle['igv'] = $igv;

                // Calcular impuestos totales del item
                $detalle['total_impuestos'] = $igv;

                // Calcular precio unitario (incluye impuestos)
                $detalle['mto_precio_unitario'] = ($valorVenta + $igv) / $cantidad;
            }
        }

        return $detalles;
    }
}