<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ApiAudit
{
    public function handle(Request $request, Closure $next)
    {
        $response = $next($request);
        $user = $request->user();
        Log::info('api.request', ['actor' => $user?->id, 'method' => $request->method(), 'path' => $request->path(), 'status' => $response->getStatusCode()]);
        // Bitácora para el superadmin: toda acción del personal que cambia algo (nunca el cuerpo: puede traer contraseñas).
        if ($user?->isStaff() && ! $request->isMethodSafe()) {
            try {
                DB::table('audit_logs')->insert(['user_id' => $user->id, 'role' => $user->role, 'method' => $request->method(), 'path' => mb_substr($request->path(), 0, 255),
                    'status' => $response->getStatusCode(), 'ip' => $request->ip(), 'created_at' => now()]);
            } catch (\Throwable $e) {
                Log::error('audit.write_failed', ['message' => $e->getMessage()]);
            }
        }

        return $response;
    }
}
