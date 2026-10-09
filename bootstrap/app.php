<?php

use App\Http\Middleware\ApiAudit;
use App\Http\Middleware\InternalToken;
use App\Http\Middleware\JwtAuthenticate;
use App\Http\Middleware\Permission;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(web: __DIR__.'/../routes/web.php', api: __DIR__.'/../routes/api.php', commands: __DIR__.'/../routes/console.php', health: '/up')
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias(['jwt' => JwtAuthenticate::class, 'permission' => Permission::class, 'internal' => InternalToken::class]);
        $middleware->appendToGroup('api', ApiAudit::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(fn (Request $r) => $r->is('api/*') || $r->expectsJson());
        $exceptions->render(function (Throwable $e, Request $r) {
            if (! $r->is('api/*')) {
                return null;
            }
            $status = match (true) {
                $e instanceof ValidationException => 422,
                $e instanceof ModelNotFoundException => 404,
                $e instanceof UniqueConstraintViolationException => 409,
                $e instanceof HttpExceptionInterface => $e->getStatusCode(), default => 500,
            };
            $message = $status >= 500 && ! config('app.debug') ? 'Servicio no disponible' : ($e->getMessage() ?: 'Error');
            if ($e instanceof UniqueConstraintViolationException) {
                $message = 'Registro duplicado';
            }

            return response()->json(['success' => false, 'message' => $message, 'data' => $e instanceof ValidationException ? $e->errors() : null], $status);
        });
    })->create();
