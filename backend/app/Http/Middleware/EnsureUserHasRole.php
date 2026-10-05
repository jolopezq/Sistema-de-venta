<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserHasRole
{
    /**
     * Permite el acceso solo a los roles indicados. Uso: ->middleware('role:admin,cashier')
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();

        if (!$user || !in_array($user->role, $roles, true)) {
            return response()->json([
                'success' => false,
                'message' => 'Acceso denegado. Tu rol no tiene permiso para esta acción.',
                'errors' => [],
                'code' => 403,
            ], 403);
        }

        return $next($request);
    }
}
