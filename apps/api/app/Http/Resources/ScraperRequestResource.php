<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class ScraperRequestResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     */
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'base_url' => $this->base_url,
            'endpoint' => $this->endpoint,
            'query' => $this->query,
            'bearer_token' => $this->bearer_token,
            'request_url' => $this->request_url,
            'curl_command' => $this->curl_command,
            'response_status' => $this->response_status,
            'response_body' => $this->response_body,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
