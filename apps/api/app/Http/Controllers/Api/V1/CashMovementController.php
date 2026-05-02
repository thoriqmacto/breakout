<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Resources\ApiResponse;
use App\Http\Resources\CashMovementResource;
use App\Models\CashMovement;
use App\Models\Portfolio;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class CashMovementController extends ApiController
{
    public function index(Portfolio $portfolio, Request $request)
    {
        Gate::authorize('view', $portfolio);

        $data = $request->validate([
            'from' => 'sometimes|date',
            'to' => 'sometimes|date',
            'kind' => ['sometimes', Rule::in(CashMovement::KINDS)],
            'limit' => 'sometimes|integer|min:1|max:500',
        ]);

        $query = $portfolio->cashMovements()
            ->orderByDesc('executed_at')
            ->orderByDesc('id');

        if (isset($data['from'])) {
            $query->whereDate('executed_at', '>=', $data['from']);
        }
        if (isset($data['to'])) {
            $query->whereDate('executed_at', '<=', $data['to']);
        }
        if (isset($data['kind'])) {
            $query->where('kind', $data['kind']);
        }

        $rows = $query->limit($data['limit'] ?? 100)->get();

        return ApiResponse::success([
            'portfolio_id' => (int) $portfolio->id,
            'rows' => CashMovementResource::collection($rows),
        ]);
    }

    public function store(Request $request, Portfolio $portfolio)
    {
        Gate::authorize('update', $portfolio);

        $validator = Validator::make($request->all(), [
            'kind' => ['required', Rule::in(CashMovement::KINDS)],
            'amount' => ['required', 'numeric'],
            'executed_at' => ['required', 'date'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);

        if ($validator->fails()) {
            return ApiResponse::error('Validation failed', 422, $validator->errors());
        }

        $data = $validator->validated();

        // Reject negative amounts on signed-positive kinds; adjustments may be either sign.
        if ($data['kind'] !== CashMovement::KIND_ADJUSTMENT && (float) $data['amount'] < 0) {
            return ApiResponse::error('Validation failed', 422, [
                'amount' => ['Amount must be non-negative for ' . $data['kind'] . '. Use kind=adjustment for signed corrections.'],
            ]);
        }

        $movement = $portfolio->cashMovements()->create($data);

        return ApiResponse::success(new CashMovementResource($movement), 'Cash movement created', 201);
    }

    public function destroy(Portfolio $portfolio, CashMovement $cashMovement)
    {
        Gate::authorize('update', $portfolio);

        if ((int) $cashMovement->portfolio_id !== (int) $portfolio->id) {
            return ApiResponse::error('Cash movement not found for this portfolio.', 404);
        }

        $cashMovement->delete();

        return ApiResponse::success(null, 'Cash movement deleted');
    }
}
