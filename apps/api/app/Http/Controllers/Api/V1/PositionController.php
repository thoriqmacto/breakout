<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Resources\ApiResponse;
use App\Http\Resources\PositionResource;
use App\Models\Portfolio;
use App\Models\Position;
use App\Services\Portfolio\PositionPricing;
use Illuminate\Http\JsonResponse;
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

        $oversell = $this->oversellError($portfolio, $payload);

        if ($oversell !== null) {
            return $oversell;
        }

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

        $oversell = $this->oversellError($portfolio, $payload, $position);

        if ($oversell !== null) {
            return $oversell;
        }

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

    /**
     * Refuse a manual exit larger than the holding it claims to close.
     *
     * The calculator matches an exit against the running quantity with
     * `min(exit_qty, holding_qty)`, which keeps a bad row from producing a
     * negative position -- but silently. The surplus shares simply vanish from
     * the realized P/L, and the ledger goes on looking healthy while it no
     * longer describes anything that happened. Refusing the write is the only
     * way the person entering it finds out.
     *
     * This is a long-only portfolio: short selling is not modelled anywhere in
     * the ledger, so an exit beyond the holding is a data-entry error rather
     * than a position. Imported broker history is left alone -- it is a record
     * of real executions and is reconciled through the importer's opening
     * positions, not by rejecting it here.
     *
     * @param  array<string, mixed>  $payload
     */
    private function oversellError(Portfolio $portfolio, array $payload, ?Position $existing = null): ?JsonResponse
    {
        if (($payload['side'] ?? null) !== 'exit') {
            return null;
        }

        // Only rows a person is entering by hand. A Stockbit row that arrives
        // without its opening BUY is surfaced by the importer's reconciliation.
        if (($payload['source'] ?? $existing?->source) !== null) {
            return null;
        }

        $assetId = (int) ($payload['asset_id'] ?? $existing?->asset_id);
        $executedAt = $payload['executed_at'] ?? $existing?->executed_at;

        if ($assetId === 0 || $executedAt === null) {
            return null;
        }

        $held = $portfolio->positions()
            ->where('asset_id', $assetId)
            ->where('executed_at', '<=', $executedAt)
            ->when($existing !== null, fn ($query) => $query->where('id', '!=', $existing->id))
            ->get(['side', 'qty_shares'])
            ->reduce(static function (float $carry, Position $row): float {
                return $row->side === 'entry'
                    ? $carry + (float) $row->qty_shares
                    : $carry - (float) $row->qty_shares;
            }, 0.0);

        $requested = (float) ($payload['qty_shares'] ?? 0.0);

        // A hair of tolerance for fractional rounding, not for a real surplus.
        if ($requested <= $held + 0.0001) {
            return null;
        }

        return ApiResponse::error('Validation failed', 422, [
            'qty_shares' => [sprintf(
                'Cannot exit %s shares: only %s are held on %s. This portfolio is long-only.',
                rtrim(rtrim(number_format($requested, 4, '.', ''), '0'), '.'),
                rtrim(rtrim(number_format(max(0.0, $held), 4, '.', ''), '0'), '.'),
                $executedAt instanceof \DateTimeInterface ? $executedAt->format('Y-m-d') : (string) $executedAt,
            )],
        ]);
    }
}
