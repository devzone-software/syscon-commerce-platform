<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class Permission
{
    public function handle(Request $request, Closure $next, string $permission)
    {
        abort_if($request->user()?->must_change_password, 403, 'Debes cambiar tu contraseña temporal antes de continuar');
        abort_unless(in_array($permission, $request->user()?->permissions() ?? [], true), 403, 'Acceso denegado');

        return $next($request);
    }
}
