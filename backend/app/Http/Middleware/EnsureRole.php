<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Restringe una ruta a ciertos roles.
 *
 * Se usa como `->middleware('role:super_admin,admin')`.
 *
 * Esta es la defensa real. Los guards de Angular solo evitan mostrar lo que no
 * corresponde; quien llame la API directamente choca acá. En la intranet esa
 * distinción no se respetaba: veinte acciones no comprobaban acceso.
 */
class EnsureRole
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();

        if (! $user || ! $user->active) {
            return response()->json(['message' => 'No autenticado.'], 401);
        }

        if (! in_array($user->role->value, $roles, true)) {
            return response()->json([
                'message' => 'No tenés permisos para realizar esta acción.',
            ], 403);
        }

        return $next($request);
    }
}
