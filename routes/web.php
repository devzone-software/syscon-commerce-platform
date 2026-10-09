<?php

use Illuminate\Support\Facades\Route;

Route::get('/', fn () => response()->json(['application' => 'SYSCON Commerce Platform', 'framework' => 'Laravel', 'health' => '/up']));
Route::get('/v3/api-docs', fn () => response()->file(base_path('docs/openapi.json'), ['Content-Type' => 'application/json']));
