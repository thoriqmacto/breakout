<?php

namespace App\Http\Resources;

use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Contracts\Support\Responsable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\JsonResource;
use JsonSerializable;

class ApiResponse extends JsonResource
{
    /**
     * Ensure Laravel doesn't wrap the response in a "data" key automatically.
     */
    public static $wrap = null;

    /**
     * Build a successful response payload.
     */
    public static function success(
        mixed $data = null,
        ?string $message = null,
        int $status = 200,
        array $meta = []
    ) {
        $resource = new static([
            'status' => 'success',
            'message' => $message,
            'data' => $data,
            'meta' => empty($meta) ? null : $meta,
        ]);

        $response = $resource->response()->setStatusCode($status);

        return static::withFloatPreservation($response);
    }

    /**
     * Build an error response payload.
     */
    public static function error(
        string $message,
        int $status = 400,
        mixed $errors = null,
        array $meta = []
    ) {
        $resource = new static([
            'status' => 'error',
            'message' => $message,
            'data' => null,
            'errors' => $errors,
            'meta' => empty($meta) ? null : $meta,
        ]);

        $response = $resource->response()->setStatusCode($status);

        return static::withFloatPreservation($response);
    }

    /**
     * Transform the resource into an array.
     */
    public function toArray($request)
    {
        $payload = [
            'status' => $this->resource['status'] ?? 'success',
        ];

        if (array_key_exists('message', $this->resource) && $this->resource['message'] !== null) {
            $payload['message'] = $this->resource['message'];
        }

        if (array_key_exists('data', $this->resource)) {
            $payload['data'] = $this->transformValue($request, $this->resource['data']);
        } else {
            $payload['data'] = null;
        }

        if (array_key_exists('errors', $this->resource)) {
            $payload['errors'] = $this->resource['errors'];
        }

        if (! empty($this->resource['meta'] ?? [])) {
            $payload['meta'] = $this->resource['meta'];
        }

        return $payload;
    }

    /**
     * Normalize nested resources or other value types.
     */
    protected function transformValue($request, mixed $value): mixed
    {
        if ($value instanceof JsonResource) {
            return $value->resolve($request);
        }

        if ($value instanceof Responsable) {
            return $value->toResponse($request)->getData(true);
        }

        if ($value instanceof Arrayable) {
            return $value->toArray();
        }

        if ($value instanceof JsonSerializable) {
            return $value->jsonSerialize();
        }

        return $value;
    }

    /**
     * Ensure floating point numbers keep their trailing zeroes in JSON output.
     */
    private static function withFloatPreservation(JsonResponse $response): JsonResponse
    {
        $response->setEncodingOptions($response->getEncodingOptions() | JSON_PRESERVE_ZERO_FRACTION);

        return $response;
    }
}
