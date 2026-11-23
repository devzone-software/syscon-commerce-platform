<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\IpUtils;

/**
 * Rutas /internal/: solo desde la red permitida (INTERNAL_ALLOWED_IPS, por defecto el mismo
 * servidor) y con el X-Service-Token. Un token filtrado no basta para usarlas desde Internet.
 */
class InternalToken
{
    public function handle(Request $request, Closure $next)
    {
        $allowed = config('commerce.internal_allowed_ips', []);
        abort_if($allowed !== [] && ! IpUtils::checkIp((string) $request->ip(), $allowed), 403, 'Acceso interno no permitido desde esta red');
        $secret = config('commerce.service_token');
        abort_unless(strlen($secret) >= 32 && hash_equals($secret, $request->header('X-Service-Token', '')), 401, 'Token interno inválido');

        return $next($request);
    }
}
