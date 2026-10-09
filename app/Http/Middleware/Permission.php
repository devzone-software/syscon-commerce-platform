<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class Permission
{
    public function handle(Request $request, Closure $next, string $permission)
    {
        abort_unless(in_array($permission, $request->user()?->permissions() ?? [], true), 403, 'Acceso denegado');

        return $next($request);
    }
}
