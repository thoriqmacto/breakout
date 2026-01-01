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
        $positions = $portfolio->positions()->with('asset')->get();

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
            'side' => [$required, 'in:long,short'],
            'qty_shares' => [$required, 'numeric', 'min:0.0001'],
            'avg_price' => [$required, 'numeric', 'min:0'],
            'entry_date' => [$required, 'date'],
            'trail' => ['nullable', 'numeric', 'min:0'],
            'status' => ['sometimes', 'in:open,closed'],
        ];
    }

    /**
     * Prepare a sanitized payload for creating or updating a position.
     *
     * @param  array<string, mixed>  $input
     * @param  \App\Models\Portfolio  $portfolio
     * @param  \App\Models\Position|null  $position
     */
    protected function preparePayload(array $input, Portfolio $portfolio, ?Position $position = null): array
    {
        return [
            'asset_id' => $input['asset_id'] ?? $position?->asset_id,
            'portfolio_id' => $portfolio->id,
            'side' => isset($input['side']) ? strtolower($input['side']) : $position?->side,
            'qty_shares' => isset($input['qty_shares']) ? (float) $input['qty_shares'] : $position?->qty_shares,
            'avg_price' => isset($input['avg_price']) ? (float) $input['avg_price'] : $position?->avg_price,
            'entry_date' => $input['entry_date'] ?? $position?->entry_date?->toDateString(),
            'trail' => array_key_exists('trail', $input) ? $input['trail'] : $position?->trail,
            'status' => strtolower($input['status'] ?? ($position?->status ?? 'open')),
        ];
    }
}
