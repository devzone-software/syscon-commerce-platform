<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Illuminate\Http\Request;

class JwtAuthenticate
{
    public function handle(Request $request, Closure $next)
    {
        $secret = config('commerce.jwt_secret');
        abort_if(strlen($secret) < 32, 503, 'JWT_SECRET no configurado');
        try {
            $claims = JWT::decode($request->bearerToken() ?? '', new Key($secret, 'HS256'));
            if (($claims->iss ?? '') !== 'syscon-auth') {
                throw new \RuntimeException;
            }
            $user = User::find($claims->sub ?? '');
            // Una suspensión corta el acceso de inmediato, sin esperar a que venza el token.
            if (! $user || $user->status !== 'ACTIVE') {
                throw new \RuntimeException;
            }
        } catch (\Throwable $e) {
            abort(401, 'Token inválido o expirado');
        }
        $request->setUserResolver(fn () => $user);

        return $next($request);
    }
}
