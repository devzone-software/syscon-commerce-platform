<?php

use App\Modules\Payments\SandboxController;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => response()->json(['application' => 'SYSCON Commerce Platform', 'framework' => 'Laravel', 'health' => '/up']));
Route::get('/v3/api-docs', fn () => response()->file(base_path('docs/openapi.json'), ['Content-Type' => 'application/json']));
// Página de la pasarela simulada (solo con PAYMENT_SANDBOX=true).
Route::get('/sandbox/checkout/{id}', [SandboxController::class, 'checkout'])->whereUuid('id');
Route::get('/sandbox/checkout/{id}/{decision}', [SandboxController::class, 'decide'])->whereUuid('id')->whereIn('decision', ['approve', 'reject'])->middleware('throttle:30,1');
