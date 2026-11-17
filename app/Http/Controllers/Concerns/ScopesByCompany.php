<?php

namespace App\Http\Controllers\Concerns;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Restringe las consultas a las empresas que el usuario puede ver.
 *
 * El super admin ve todas las empresas. Cualquier otro usuario solo ve la
 * empresa a la que está vinculado, aunque pida otro `company_id` en la
 * petición. Esto evita que un administrador de empresa lea datos de otra.
 */
trait ScopesByCompany
{
    /**
     * Aplica el alcance por empresa a una consulta con columna `company_id`.
     *
     * @param  string  $column  Columna a comparar (por defecto `company_id`).
     */
    protected function scopeToUserCompany(Builder $query, ?Request $request = null, string $column = 'company_id'): Builder
    {
        $request = $request ?? request();
        $user = $request->user();

        if (! $user) {
            return $query->whereRaw('1 = 0');
        }

        if ($user->hasRole('super_admin')) {
            $requested = $request->input('company_id');

            return $requested ? $query->where($column, $requested) : $query;
        }

        return $query->where($column, $user->company_id);
    }

    /**
     * Verifica que el usuario pueda operar sobre la empresa indicada.
     */
    protected function userCanAccessCompany(?User $user, int $companyId): bool
    {
        return $user ? $user->canAccessCompany($companyId) : false;
    }

    /**
     * Respuesta 403 cuando se intenta acceder a una empresa ajena.
     */
    protected function forbiddenCompany(): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => 'No tienes acceso a esta empresa.',
        ], 403);
    }
}
