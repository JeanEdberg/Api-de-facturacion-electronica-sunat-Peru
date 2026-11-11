<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\Notificacion;
use App\Services\BuzonService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Buzón electrónico SUNAT / SUNAFIL.
 *
 * El listado conserva la forma de paginador de Laravel que consume la PWA
 * ({ data: { data: [...] } }) y añade `resumen` (pendientes y total) para el
 * encabezado del panel.
 */
class NotificacionController extends Controller
{
    public function __construct(private BuzonService $service)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'company_id' => ['nullable', 'integer', 'exists:companies,id'],
            'entidad' => ['nullable', 'string', 'in:SUNAT,SUNAFIL'],
            'estado' => ['nullable', 'string', 'in:pendiente,leida'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $companyId = $this->companyId($request, $data['company_id'] ?? null);

        $query = Notificacion::where('company_id', $companyId);
        if (! empty($data['entidad'])) {
            $query->where('entidad', $data['entidad']);
        }
        if (! empty($data['estado'])) {
            $query->where('estado', $data['estado']);
        }

        $paginador = $query->orderByDesc('fecha')
            ->orderByDesc('id')
            ->paginate((int) ($data['per_page'] ?? 15));

        return response()->json([
            'success' => true,
            'data' => $paginador,
            'resumen' => $this->service->resumen($companyId, $data['entidad'] ?? null),
            'message' => 'Notificaciones obtenidas correctamente',
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'company_id' => ['required', 'integer', 'exists:companies,id'],
            'entidad' => ['required', 'string', 'in:SUNAT,SUNAFIL'],
            'asunto' => ['required', 'string', 'max:255'],
            'codigo' => ['nullable', 'string', 'max:255'],
            'tipo' => ['nullable', 'string', 'max:100'],
            'fecha' => ['nullable', 'date'],
            'detalle' => ['nullable', 'string'],
            'url' => ['nullable', 'url', 'max:500'],
        ]);

        $notificacion = $this->service->registrar($data);

        return response()->json([
            'success' => true,
            'data' => $notificacion,
            'message' => 'Notificación registrada correctamente',
        ], 201);
    }

    public function marcarLeida($id): JsonResponse
    {
        try {
            $notificacion = Notificacion::findOrFail($id);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Notificación no encontrada',
            ], 404);
        }

        $notificacion = $this->service->marcarLeida($notificacion);

        return response()->json([
            'success' => true,
            'data' => $notificacion,
            'message' => 'Notificación marcada como leída',
        ]);
    }

    public function sincronizar(Request $request): JsonResponse
    {
        $data = $request->validate([
            'company_id' => ['nullable', 'integer', 'exists:companies,id'],
        ]);

        $companyId = $this->companyId($request, $data['company_id'] ?? null);
        $company = Company::findOrFail($companyId);

        try {
            $resultado = $this->service->sincronizar($company);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'No se pudo sincronizar el buzón',
                'error' => $e->getMessage(),
            ], 502);
        }

        return response()->json([
            'success' => true,
            'data' => $resultado,
            'message' => $resultado['importadas'] > 0
                ? "Sincronizado: {$resultado['importadas']} notificación(es)."
                : 'Buzón sincronizado: sin notificaciones nuevas.',
        ]);
    }

    private function companyId(Request $request, ?int $companyId): int
    {
        $companyId = $companyId ?? (int) ($request->user()?->company_id ?? 1);

        $user = $request->user();
        abort_if($user && ! $user->canAccessCompany($companyId), 403, 'No tienes acceso a esta empresa.');

        return $companyId;
    }
}
