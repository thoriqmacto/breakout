<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Resources\ApiResponse;
use App\Http\Resources\ScraperRequestResource;
use App\Models\ScraperRequest;
use Illuminate\Http\Request;

class ScraperRequestController extends ApiController
{
    /**
     * Display a listing of the authenticated user's saved scraper requests.
     */
    public function index(Request $request)
    {
        $user = $request->user();

        $records = $user->scraperRequests()
            ->latest()
            ->limit(50)
            ->get();

        return ApiResponse::success(ScraperRequestResource::collection($records));
    }

    /**
     * Store a newly created scraper request for the authenticated user.
     */
    public function store(Request $request)
    {
        $user = $request->user();

        $data = $request->validate([
            'base_url' => ['required', 'string', 'max:512'],
            'endpoint' => ['nullable', 'string', 'max:512'],
            'query' => ['nullable', 'string', 'max:1024'],
            'bearer_token' => ['nullable', 'string'],
            'request_url' => ['required', 'string'],
            'curl_command' => ['required', 'string'],
            'response_status' => ['nullable', 'string', 'max:191'],
            'response_body' => ['required', 'string'],
        ]);

        $normalized = [
            'base_url' => trim($data['base_url']),
            'endpoint' => isset($data['endpoint']) ? trim($data['endpoint']) ?: null : null,
            'query' => isset($data['query']) ? trim($data['query']) ?: null : null,
            'bearer_token' => isset($data['bearer_token']) ? trim($data['bearer_token']) ?: null : null,
            'request_url' => trim($data['request_url']),
            'curl_command' => trim($data['curl_command']),
            'response_status' => isset($data['response_status']) ? trim($data['response_status']) ?: null : null,
            'response_body' => $data['response_body'],
        ];

        $record = $user->scraperRequests()->create($normalized);

        return ApiResponse::success(
            ScraperRequestResource::make($record),
            'Scraper request saved successfully',
            201
        );
    }

    /**
     * Remove the specified scraper request from storage.
     */
    public function destroy(Request $request, ScraperRequest $scraperRequest)
    {
        $user = $request->user();

        if (! $user || $scraperRequest->user_id !== $user->id) {
            return ApiResponse::error('Saved command not found.', 404);
        }

        $scraperRequest->delete();

        return ApiResponse::success(null, 'Saved command deleted successfully.');
    }
}
