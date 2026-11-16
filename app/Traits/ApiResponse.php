<?php

namespace App\Traits;

use Illuminate\Http\JsonResponse;

trait ApiResponse
{
    /**
     * Return a standardized success JSON response.
     *
     * @param  mixed  $data
     * @param  string  $message
     * @param  int  $statusCode
     * @return JsonResponse
     */
    protected function successResponse(mixed $data = null, string $message = 'Operation successful', int $statusCode = 200): JsonResponse
    {
        return new JsonResponse([
            'status' => 'success',
            'code' => $statusCode,
            'message' => $message,
            'data' => $data,
        ], $statusCode);
    }

    /**
     * Return a standardized error JSON response.
     *
     * @param  string  $message
     * @param  int  $statusCode
     * @param  mixed  $errors
     * @return JsonResponse
     */
    protected function errorResponse(string $message = 'Operation failed', int $statusCode = 400, mixed $errors = null): JsonResponse
    {
        $response = [
            'status' => 'error',
            'code' => $statusCode,
            'message' => $message,
        ];

        if ($errors !== null) {
            $response['errors'] = $errors;
        }

        return new JsonResponse($response, $statusCode);
    }
}
