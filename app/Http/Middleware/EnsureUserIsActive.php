<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Un token de Sanctum sigue siendo válido aunque la cuenta se desactive
 * después de emitirlo: el login rechaza a los inactivos, pero la sesión ya
 * abierta continuaría emitiendo comprobantes. Aquí se corta en cada petición.
 */
class EnsureUserIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && !$user->active) {
            // actingAs() usa un TransientToken, que no se persiste ni se borra.
            $token = $user->currentAccessToken();
            if ($token instanceof \Laravel\Sanctum\PersonalAccessToken) {
                $token->delete();
            }

            return response()->json([
                'message' => 'Tu cuenta está desactivada. Contacta al administrador.',
                'status' => 'error',
            ], 401);
        }

        return $next($request);
    }
}
