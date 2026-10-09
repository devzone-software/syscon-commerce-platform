<?php

namespace App\Support;

use Illuminate\Http\JsonResponse;

class Api
{
    public static function ok(string $message, mixed $data = null, int $status = 200): JsonResponse
    {
        return response()->json(['success' => true, 'message' => $message, 'data' => $data], $status);
    }
}
