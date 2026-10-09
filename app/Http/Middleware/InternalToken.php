<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class InternalToken
{
    public function handle(Request $request, Closure $next)
    {
        $secret = config('commerce.service_token');
        abort_unless(strlen($secret) >= 32 && hash_equals($secret, $request->header('X-Service-Token', '')), 401, 'Token interno inválido');

        return $next($request);
    }
}
