<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Resources\ApiResponse;
use App\Http\Resources\PositionResource;
use App\Models\Portfolio;
use App\Models\Position;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class PositionController extends ApiController
{
    public function index(Portfolio $portfolio)
    {
        $year = request()->integer('year') ?? $portfolio->year;
        $positions = $portfolio->positionsForYear($year)->with('asset')->get();

        return ApiResponse::success(PositionResource::collection($positions));
    }

    public function store(Request $request, Portfolio $portfolio)
    {
        $validator = Validator::make($request->all(), $this->rules());

        if ($validator->fails()) {
            return ApiResponse::error('Validation failed', 422, $validator->errors());
        }

        $payload = $this->preparePayload($validator->validated(), $portfolio);

        $position = $portfolio->positions()->create($payload);

        return ApiResponse::success(new PositionResource($position->load('asset')), 'Position created', 201);
    }

    public function show(Portfolio $portfolio, Position $position)
    {
        if ($portfolio->id !== $position->portfolio_id) {
            return ApiResponse::error('Position not found for this portfolio.', 404);
        }

        return ApiResponse::success(new PositionResource($position->load('asset')));
    }

    public function update(Request $request, Portfolio $portfolio, Position $position)
    {
        if ($portfolio->id !== $position->portfolio_id) {
            return ApiResponse::error('Position not found for this portfolio.', 404);
        }

        $validator = Validator::make($request->all(), $this->rules(isUpdate: true));

        if ($validator->fails()) {
            return ApiResponse::error('Validation failed', 422, $validator->errors());
        }

        $payload = $this->preparePayload($validator->validated(), $portfolio, $position);

        $position->update($payload);

        return ApiResponse::success(new PositionResource($position->load('asset')), 'Position updated');
    }

    public function destroy(Portfolio $portfolio, Position $position)
    {
        if ($portfolio->id !== $position->portfolio_id) {
            return ApiResponse::error('Position not found for this portfolio.', 404);
        }

        $position->delete();

        return ApiResponse::success(null, 'Position deleted');
    }

    protected function rules(bool $isUpdate = false): array
    {
        $required = $isUpdate ? 'sometimes' : 'required';

        return [
            'asset_id' => [$required, 'exists:assets,id'],
            'side' => [$required, 'in:entry,exit'],
            'qty_shares' => [$required, 'numeric', 'min:0.0001'],
            'price' => [$required, 'numeric', 'min:0'],
            'fee_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'executed_at' => [$required, 'date'],
        ];
    }

    /**
     * Prepare a sanitized payload for creating or updating a position.
     *
     * @param  array<string, mixed>  $input
     */
    protected function preparePayload(array $input, Portfolio $portfolio, ?Position $position = null): array
    {
        $side = isset($input['side']) ? strtolower($input['side']) : $position?->side;
        $qty = isset($input['qty_shares']) ? (float) $input['qty_shares'] : $position?->qty_shares;
        $price = isset($input['price']) ? (float) $input['price'] : $position?->price;
        $feeRate = isset($input['fee_rate']) ? (float) $input['fee_rate'] : ($position?->fee_rate ?? 0.0);
        $side ??= 'entry';

        $feeMultiplier = $side === 'exit' ? 1 - ($feeRate / 100) : 1 + ($feeRate / 100);
        $avgPrice = $price !== null ? $price * $feeMultiplier : $position?->avg_price;
        $feeValue = $price !== null && $qty !== null ? $qty * $price * ($feeRate / 100) : ($position?->fee_value ?? 0);

        return [
            'asset_id' => $input['asset_id'] ?? $position?->asset_id,
            'portfolio_id' => $portfolio->id,
            'side' => $side,
            'qty_shares' => $qty,
            'price' => $price,
            'fee_rate' => $feeRate,
            'fee_value' => $feeValue,
            'avg_price' => $avgPrice,
            'executed_at' => $input['executed_at'] ?? $position?->executed_at?->toDateString(),
        ];
    }
}
