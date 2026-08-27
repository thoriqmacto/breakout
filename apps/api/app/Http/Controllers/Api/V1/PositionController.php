<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Resources\ApiResponse;
use App\Http\Resources\PositionResource;
use App\Models\Portfolio;
use App\Models\Position;
use App\Services\Portfolio\PositionPricing;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class PositionController extends ApiController
{
    public function __construct(private readonly PositionPricing $pricing) {}

    public function index(Portfolio $portfolio)
    {
        // "all" returns the whole ledger, which is what an imported multi-year
        // history needs to stay reachable. Anything else keeps the previous
        // behaviour: an explicit year, else the portfolio's own.
        $requested = request()->query('year');
        $year = $requested === Portfolio::ALL_YEARS
            ? Portfolio::ALL_YEARS
            : (is_numeric($requested) ? (int) $requested : $portfolio->year);

        $positions = $portfolio->positionsForYear($year)
            ->with('asset')
            ->orderBy('executed_at')
            ->orderBy('id')
            ->get();

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
            // Lets a caller state the exact money the broker charged instead
            // of a percentage. When present it is authoritative and fee_rate
            // becomes the derived, descriptive figure.
            'fee_value' => ['nullable', 'numeric', 'min:0'],
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
        $side ??= 'entry';

        // An edit that changes neither fee input keeps the row's stored rate,
        // which is what the form has always done.
        $feeRate = array_key_exists('fee_rate', $input) && $input['fee_rate'] !== null
            ? (float) $input['fee_rate']
            : ($position?->fee_rate ?? 0.0);

        $feeValue = array_key_exists('fee_value', $input) && $input['fee_value'] !== null
            ? (float) $input['fee_value']
            : null;

        if ($price === null || $qty === null) {
            $pricing = [
                'fee_rate' => $feeRate,
                'fee_value' => $feeValue ?? ($position?->fee_value ?? 0.0),
                'avg_price' => $position?->avg_price,
            ];
        } else {
            // One arithmetic path, shared with the Stockbit importer, so the
            // two can never disagree about what a trade cost.
            $pricing = $this->pricing->normalize(
                $side,
                (float) $qty,
                (float) $price,
                $feeValue === null ? $feeRate : null,
                $feeValue,
            );
        }

        return [
            'asset_id' => $input['asset_id'] ?? $position?->asset_id,
            'portfolio_id' => $portfolio->id,
            'side' => $side,
            'qty_shares' => $qty,
            'price' => $price,
            'fee_rate' => $pricing['fee_rate'],
            'fee_value' => $pricing['fee_value'],
            'avg_price' => $pricing['avg_price'],
            'executed_at' => $input['executed_at'] ?? $position?->executed_at?->toDateTimeString(),
        ];
    }
}
