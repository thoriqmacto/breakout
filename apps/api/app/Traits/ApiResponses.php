<?php

namespace App\Traits;

use Illuminate\Http\JsonResponse;

trait ApiResponses
{
    protected function success($data = null, string $message = 'Success', int $code = 200): JsonResponse
    {
        return response()->json([
            'status'  => 'success',
            'message' => $message,
            'data'    => $data,
        ], $code);
    }

    protected function ok($message='OK'): JsonResponse
    {
        return $this->success(null, $message, 200);
    }

    protected function created($data = null, string $message = 'Resource created'): JsonResponse
    {
        return $this->success($data, $message, 201);
    }

    protected function updated($data = null, string $message = 'Resource updated'): JsonResponse
    {
        return $this->success($data, $message, 200);
    }

    protected function deleted(string $message = 'Resource deleted'): JsonResponse
    {
        return $this->success(null, $message, 200);
    }

    protected function error(string $message = 'Something went wrong', int $code = 500, $errors = null): JsonResponse
    {
        return response()->json([
            'status'  => 'error',
            'message' => $message,
            'errors'  => $errors,
        ], $code);
    }

    protected function notFound(string $message = 'Resource not found'): JsonResponse
    {
        return $this->error($message, 404);
    }

    protected function unauthorized(string $message = 'Unauthorized'): JsonResponse
    {
        return $this->error($message, 401);
    }

    protected function forbidden(string $message = 'Forbidden'): JsonResponse
    {
        return $this->error($message, 403);
    }

    protected function validationError($errors, string $message = 'Validation failed'): JsonResponse
    {
        return $this->error($message, 422, $errors);
    }
}
