<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Resources\ApiResponse;
use App\Http\Resources\BroksumResource;
use App\Models\Broksum;
use Illuminate\Http\Request;

class BrokerSummaryController extends ApiController
{
    public function index(Request $request)
    {
        $data = $request->validate([
            'symbol' => 'required|string|max:10',
            'from' => 'sometimes|date',
            'to' => 'sometimes|date',
            'broker' => 'sometimes|string|max:20',
            'limit' => 'sometimes|integer|min:1|max:1000',
        ]);

        $symbol = strtoupper($data['symbol']);
        $limit = $data['limit'] ?? 250;

        $query = Broksum::query()
            ->whereHas('asset', fn ($q) => $q->where('symbol', $symbol))
            ->with(['asset:id,symbol,name'])
            ->orderByDesc('date')
            ->orderBy('broker');

        if (isset($data['from'])) {
            $query->whereDate('date', '>=', $data['from']);
        }

        if (isset($data['to'])) {
            $query->whereDate('date', '<=', $data['to']);
        }

        if (isset($data['broker'])) {
            $query->where('broker', strtoupper($data['broker']));
        }

        $rows = $query->limit($limit)->get();

        return ApiResponse::success([
            'symbol' => $symbol,
            'rows' => BroksumResource::collection($rows),
        ]);
    }
}
