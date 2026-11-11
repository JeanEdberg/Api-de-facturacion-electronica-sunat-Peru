<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Compra;
use App\Services\CompraService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

class CompraController extends Controller
{
    public function __construct(private CompraService $service) {}

    /**
     * GET /api/v1/compras?company_id=&fecha_desde=&fecha_hasta=&estado_sunat=&per_page=
     */
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'company_id' => 'nullable|integer|exists:companies,id',
            'fecha_desde' => 'nullable|date',
            'fecha_hasta' => 'nullable|date',
            'estado_sunat' => 'nullable|string',
        ]);

        $companyId = $this->resolverEmpresa($request);
        if ($companyId instanceof JsonResponse) {
            return $companyId;
        }

        $query = Compra::query()->with('company')->where('company_id', $companyId);

        if ($request->filled('fecha_desde')) {
            $query->whereDate('fecha_emision', '>=', $request->fecha_desde);
        }
        if ($request->filled('fecha_hasta')) {
            $query->whereDate('fecha_emision', '<=', $request->fecha_hasta);
        }
        if ($request->filled('estado_sunat')) {
            $query->where('estado_sunat', $request->estado_sunat);
        }

        $compras = $query
            ->orderByDesc('fecha_emision')
            ->orderByDesc('id')
            ->paginate($request->integer('per_page', 50));

        return response()->json([
            'success' => true,
            'message' => 'Compras obtenidas correctamente.',
            'data' => $compras,
        ]);
    }

    /**
     * POST /api/v1/compras  (alta manual)
     */
    public function store(Request $request): JsonResponse
    {
        $data = $this->validar($request);

        $companyId = $this->resolverEmpresa($request, $data['company_id'] ?? null);
        if ($companyId instanceof JsonResponse) {
            return $companyId;
        }

        try {
            $compra = $this->service->guardar($companyId, $data);

            return response()->json([
                'success' => true,
                'message' => $compra->wasRecentlyCreated
                    ? 'Compra registrada correctamente.'
                    : 'Compra actualizada correctamente.',
                'data' => $compra->fresh()->load('company'),
            ], $compra->wasRecentlyCreated ? 201 : 200);
        } catch (\Throwable $e) {
            Log::error('Error al registrar compra', ['error' => $e->getMessage()]);

            return response()->json([
                'success' => false,
                'message' => 'Error al registrar la compra.',
            ], 500);
        }
    }

    /**
     * POST /api/v1/compras/importar-xml  (multipart: xml + company_id)
     */
    public function importarXml(Request $request): JsonResponse
    {
        $request->validate([
            'company_id' => 'required|integer|exists:companies,id',
            'xml' => 'required|file|mimetypes:text/xml,application/xml,text/plain,application/octet-stream|max:2048',
        ]);

        $companyId = $this->resolverEmpresa($request, $request->integer('company_id'));
        if ($companyId instanceof JsonResponse) {
            return $companyId;
        }

        try {
            $contenido = file_get_contents($request->file('xml')->getRealPath());
            $datos = $this->service->parsearXml($contenido);
            $compra = $this->service->guardar($companyId, $datos);

            return response()->json([
                'success' => true,
                'message' => 'Comprobante importado desde el XML.',
                'data' => $compra->fresh()->load('company'),
            ], $compra->wasRecentlyCreated ? 201 : 200);
        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        } catch (\Throwable $e) {
            Log::error('Error al importar compra desde XML', ['error' => $e->getMessage()]);

            return response()->json([
                'success' => false,
                'message' => 'Error al importar el comprobante.',
            ], 500);
        }
    }

    /**
     * GET /api/v1/compras/{id}
     */
    public function show(Request $request, int $id): JsonResponse
    {
        $compra = Compra::with('company')->find($id);

        if (! $compra) {
            return response()->json(['success' => false, 'message' => 'Compra no encontrada.'], 404);
        }

        if (! $request->user()->canAccessCompany($compra->company_id)) {
            return $this->sinPermiso();
        }

        return response()->json([
            'success' => true,
            'message' => 'Compra obtenida correctamente.',
            'data' => $compra,
        ]);
    }

    /**
     * DELETE /api/v1/compras/{id}
     */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $compra = Compra::find($id);

        if (! $compra) {
            return response()->json(['success' => false, 'message' => 'Compra no encontrada.'], 404);
        }

        if (! $request->user()->canAccessCompany($compra->company_id)) {
            return $this->sinPermiso();
        }

        $compra->delete();

        return response()->json([
            'success' => true,
            'message' => 'Compra eliminada correctamente.',
        ]);
    }

    /**
     * GET /api/v1/compras/{id}/download-{xml|cdr|pdf}
     */
    public function download(Request $request, int $id, string $formato)
    {
        $compra = Compra::find($id);

        if (! $compra) {
            return response()->json(['success' => false, 'message' => 'Compra no encontrada.'], 404);
        }

        if (! $request->user()->canAccessCompany($compra->company_id)) {
            return $this->sinPermiso();
        }

        $fileService = app(\App\Services\FileService::class);
        $download = match ($formato) {
            'xml' => $fileService->downloadXml($compra),
            'cdr' => $fileService->downloadCdr($compra),
            'pdf' => $fileService->downloadPdf($compra),
            default => null,
        };

        if (! $download) {
            return response()->json([
                'success' => false,
                'message' => strtoupper($formato) . ' no encontrado.',
            ], 404);
        }

        return $download;
    }

    /**
     * POST /api/v1/compras/{id}/adjuntos  (multipart: xml|cdr|pdf)
     *
     * Permite adjuntar los artefactos que el proveedor no envió (por ejemplo,
     * cuando solo entregó el PDF). El tipo se deduce del campo del formulario.
     */
    public function subirAdjunto(Request $request, int $id): JsonResponse
    {
        $compra = Compra::find($id);

        if (! $compra) {
            return response()->json(['success' => false, 'message' => 'Compra no encontrada.'], 404);
        }

        if (! $request->user()->canAccessCompany($compra->company_id)) {
            return $this->sinPermiso();
        }

        $request->validate([
            'tipo' => 'required|in:xml,cdr,pdf',
            'archivo' => 'required|file|max:10240',
        ]);

        $tipo = $request->string('tipo')->toString();
        $archivo = $request->file('archivo');

        try {
            $fileService = app(\App\Services\FileService::class);
            $contenido = file_get_contents($archivo->getRealPath());

            $path = match ($tipo) {
                'xml' => $fileService->saveXml($compra, $contenido),
                'cdr' => $fileService->saveCdr($compra, $contenido),
                'pdf' => $fileService->savePdf($compra, $contenido),
            };

            $compra->update(["{$tipo}_path" => $path]);

            return response()->json([
                'success' => true,
                'message' => strtoupper($tipo) . ' adjuntado correctamente.',
                'data' => [
                    'id' => $compra->id,
                    "{$tipo}_path" => $path,
                ],
            ], 201);
        } catch (\Throwable $e) {
            Log::error('Error al adjuntar archivo a compra', ['error' => $e->getMessage()]);

            return response()->json([
                'success' => false,
                'message' => 'Error al adjuntar el archivo.',
            ], 500);
        }
    }

    /**
     * @return array<string,mixed>
     */
    protected function validar(Request $request): array
    {
        return $request->validate([
            'company_id' => 'required|integer|exists:companies,id',
            'tipo_documento' => ['required', Rule::in(['01', '03', '07', '08', '20', '40'])],
            'serie' => 'nullable|string|max:10',
            'correlativo' => 'nullable|string|max:12',
            'numero_completo' => 'nullable|string|max:20',
            'fecha_emision' => 'required|date',
            'fecha_vencimiento' => 'nullable|date',
            'moneda' => 'nullable|string|size:3',
            'tipo_cambio' => 'nullable|numeric|min:0',
            'proveedor_tipo_doc' => 'nullable|string|max:1',
            'proveedor_num_doc' => 'required|string|max:15',
            'proveedor_razon_social' => 'required|string|max:255',
            'mto_oper_gravadas' => 'nullable|numeric|min:0',
            'mto_oper_exoneradas' => 'nullable|numeric|min:0',
            'mto_oper_inafectas' => 'nullable|numeric|min:0',
            'mto_oper_exportacion' => 'nullable|numeric|min:0',
            'mto_oper_gratuitas' => 'nullable|numeric|min:0',
            'mto_igv' => 'nullable|numeric|min:0',
            'mto_isc' => 'nullable|numeric|min:0',
            'mto_icbper' => 'nullable|numeric|min:0',
            'mto_otros_tributos' => 'nullable|numeric|min:0',
            'mto_imp_venta' => 'required|numeric|min:0',
            'estado_sunat' => 'nullable|string|max:20',
            'observacion' => 'nullable|string|max:255',
        ]);
    }

    /**
     * Resuelve y autoriza la empresa objetivo. Devuelve el id o una respuesta 403.
     *
     * @return int|JsonResponse
     */
    protected function resolverEmpresa(Request $request, ?int $companyId = null): int|JsonResponse
    {
        $companyId ??= $request->filled('company_id')
            ? $request->integer('company_id')
            : $request->user()->company_id;

        if ($companyId === null) {
            return response()->json([
                'success' => false,
                'message' => 'Indica la empresa (company_id).',
            ], 422);
        }

        if (! $request->user()->canAccessCompany($companyId)) {
            return $this->sinPermiso();
        }

        return $companyId;
    }

    protected function sinPermiso(): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => 'No tienes permisos para acceder a esta empresa.',
        ], 403);
    }
}
