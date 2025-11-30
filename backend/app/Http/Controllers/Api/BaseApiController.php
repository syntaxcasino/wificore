<?php
namespace App\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use App\Http\Controllers\Controller;

class BaseApiController extends Controller
{
    protected function success($data = [], $message = 'Success', $status = 200): JsonResponse
    {
        return response()->json(['message' => $message, 'data' => $data], $status);
    }

    protected function error($message = 'Error', $status = 500): JsonResponse
    {
        return response()->json(['error' => $message], $status);
    }
}