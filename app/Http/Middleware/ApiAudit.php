<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class ApiAudit
{
    public function handle(Request $request, Closure $next)
    {
        $response = $next($request);
        Log::info('api.request', ['actor' => $request->user()?->id, 'method' => $request->method(), 'path' => $request->path(), 'status' => $response->getStatusCode()]);

        return $response;
    }
}
