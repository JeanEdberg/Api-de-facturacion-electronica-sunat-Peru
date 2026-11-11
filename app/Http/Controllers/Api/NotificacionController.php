<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Notificacion;
use App\Services\NotificacionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class NotificacionController extends Controller
{
    public function __construct(private NotificacionService $service) {}

    /**
     * GET /api/v1/notificaciones?company_id=&entidad=&estado=
     */
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'company_id' => 'nullable|integer|exists:companies,id',
            'entidad' => 'nullable|in:SUNAT,SUNAFIL',
            'estado' => 'nullable|in:pendiente,leida,archivada',
        ]);

        // integer() devuelve 0 si el parámetro no viene: hay que distinguirlo de
        // 'sin filtro' para no negar el listado global del super admin.
        $companyId = $request->filled('company_id') ? $request->integer('company_id') : null;

        if (! $this->autorizado($request, $companyId)) {
            return $this->sinPermiso();
        }

        $query = Notificacion::query()->with('company');

        if ($request->filled('company_id')) {
            $query->where('company_id', $request->integer('company_id'));
        } elseif (! $request->user()->hasRole('super_admin')) {
            // Usuario normal: solo las notificaciones de su empresa.
            $query->where('company_id', $request->user()->company_id);
        }

        if ($request->filled('entidad')) {
            $query->where('entidad', $request->entidad);
        }

        if ($request->filled('estado')) {
            $query->where('estado', $request->estado);
        }

        $notificaciones = $query
            ->orderByDesc('fecha')
            ->orderByDesc('id')
            ->paginate($request->integer('per_page', 50));

        return response()->json([
            'success' => true,
            'message' => 'Notificaciones obtenidas correctamente.',
            'data' => $notificaciones,
            'resumen' => [
                'pendientes' => $this->contar($request, Notificacion::ESTADO_PENDIENTE, $request->entidad),
                'total' => $this->contar($request, null, $request->entidad),
            ],
        ]);
    }

    /**
     * POST /api/v1/notificaciones  (alta manual)
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'company_id' => 'required|integer|exists:companies,id',
            'entidad' => 'required|in:SUNAT,SUNAFIL',
            'codigo' => 'nullable|string|max:100',
            'asunto' => 'required|string|max:255',
            'tipo' => 'nullable|string|max:100',
            'fecha' => 'nullable|date',
            'detalle' => 'nullable|string',
            'url' => 'nullable|string|max:500',
            'tiene_adjunto' => 'nullable|boolean',
        ]);

        if (! $this->autorizado($request, (int) $data['company_id'])) {
            return $this->sinPermiso();
        }

        try {
            $normalizado = $this->service->normalizar($data, $data['entidad']);

            $notificacion = Notificacion::updateOrCreate(
                [
                    'company_id' => $data['company_id'],
                    'entidad' => $data['entidad'],
                    'codigo' => $normalizado['codigo'],
                ],
                $normalizado + [
                    'company_id' => $data['company_id'],
                    'entidad' => $data['entidad'],
                ],
            );

            return response()->json([
                'success' => true,
                'message' => $notificacion->wasRecentlyCreated
                    ? 'Notificación registrada correctamente.'
                    : 'Notificación actualizada correctamente.',
                'data' => $notificacion->fresh()->load('company'),
            ], $notificacion->wasRecentlyCreated ? 201 : 200);
        } catch (\Throwable $e) {
            Log::error('Error al registrar notificación', [
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error al registrar la notificación.',
            ], 500);
        }
    }

    /**
     * POST /api/v1/notificaciones/{id}/marcar-leida
     */
    public function marcarLeida(Request $request, int $id): JsonResponse
    {
        $notificacion = Notificacion::find($id);

        if (! $notificacion) {
            return response()->json(['success' => false, 'message' => 'Notificación no encontrada.'], 404);
        }

        if (! $this->autorizado($request, $notificacion->company_id)) {
            return $this->sinPermiso();
        }

        $notificacion->update([
            'estado' => Notificacion::ESTADO_LEIDA,
            'leida_at' => now(),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Notificación marcada como leída.',
            'data' => $notificacion->fresh(),
        ]);
    }

    /**
     * POST /api/v1/notificaciones/sincronizar
     */
    public function sincronizar(Request $request): JsonResponse
    {
        $data = $request->validate([
            'company_id' => 'required|integer|exists:companies,id',
        ]);

        if (! $this->autorizado($request, (int) $data['company_id'])) {
            return $this->sinPermiso();
        }

        $company = \App\Models\Company::findOrFail($data['company_id']);
        $resultado = $this->service->sincronizar($company);

        return response()->json([
            'success' => true,
            'message' => 'Buzón sincronizado.',
            'data' => $resultado,
        ]);
    }

    protected function autorizado(Request $request, ?int $companyId): bool
    {
        if ($companyId === null) {
            return true;
        }

        return $request->user()->canAccessCompany($companyId);
    }

    protected function sinPermiso(): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => 'No tienes permisos para acceder a esta empresa.',
        ], 403);
    }

    protected function contar(Request $request, ?string $estado, ?string $entidad = null): int
    {
        $query = Notificacion::query();

        if ($request->filled('company_id')) {
            $query->where('company_id', $request->integer('company_id'));
        } elseif (! $request->user()->hasRole('super_admin')) {
            $query->where('company_id', $request->user()->company_id);
        }

        if ($entidad !== null && $entidad !== '') {
            $query->where('entidad', $entidad);
        }

        if ($estado !== null) {
            $query->where('estado', $estado);
        }

        return $query->count();
    }
}
