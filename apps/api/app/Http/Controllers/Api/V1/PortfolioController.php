<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Resources\ApiResponse;
use App\Http\Resources\PortfolioResource;
use App\Models\Portfolio;
use App\Services\Portfolio\PortfolioCalculator;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;

class PortfolioController extends ApiController
{
    public function __construct(private readonly PortfolioCalculator $calculator) {}

    public function index(Request $request)
    {
        $userId = optional($request->user())->getAuthIdentifier();

        $query = Portfolio::query()
            ->withCount('positions')
            ->when(
                $userId !== null,
                fn ($q) => $q->where(function ($inner) use ($userId): void {
                    $inner->where('user_id', $userId)->orWhereNull('user_id');
                })
            );

        if ($this->include('positions')) {
            $query->with([
                'positions' => fn ($builder) => $builder->with(['asset.latestPriceRecord']),
            ]);
        }

        return ApiResponse::success(PortfolioResource::collection($query->get()));
    }

    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => ['required', 'string', 'max:255'],
            'base_ccy' => ['required', 'string', 'max:10'],
            'remarks' => ['nullable', 'string', 'max:255'],
            'year' => ['nullable', 'integer', 'between:1900,3000'],
            'cash_balance' => ['nullable', 'numeric'],
        ]);

        if ($validator->fails()) {
            return ApiResponse::error('Validation failed', 422, $validator->errors());
        }

        $payload = $validator->validated();
        $payload['user_id'] = optional($request->user())->getAuthIdentifier();
        $payload['cash_balance'] ??= 0;

        $portfolio = Portfolio::create($payload);

        return ApiResponse::success(new PortfolioResource($portfolio), 'Portfolio created', 201);
    }

    public function show(Portfolio $portfolio)
    {
        Gate::authorize('view', $portfolio);

        $portfolio
            ->loadMissing(['positions.asset.latestPriceRecord'])
            ->loadCount('positions');

        return ApiResponse::success(new PortfolioResource($portfolio));
    }

    public function update(Request $request, Portfolio $portfolio)
    {
        Gate::authorize('update', $portfolio);

        $validator = Validator::make($request->all(), [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'base_ccy' => ['sometimes', 'required', 'string', 'max:10'],
            'remarks' => ['sometimes', 'nullable', 'string', 'max:255'],
            'year' => ['sometimes', 'nullable', 'integer', 'between:1900,3000'],
            'cash_balance' => ['sometimes', 'numeric'],
        ]);

        if ($validator->fails()) {
            return ApiResponse::error('Validation failed', 422, $validator->errors());
        }

        $portfolio->update($validator->validated());

        return ApiResponse::success(new PortfolioResource($portfolio), 'Portfolio updated');
    }

    public function destroy(Portfolio $portfolio)
    {
        Gate::authorize('delete', $portfolio);

        $portfolio->delete();

        return ApiResponse::success(null, 'Portfolio deleted');
    }

    public function summary(Portfolio $portfolio)
    {
        Gate::authorize('view', $portfolio);

        $portfolio->loadMissing([
            'positions.asset.latestPriceRecord',
            'cashMovements',
        ]);

        return ApiResponse::success($this->calculator->compute($portfolio));
    }

    public function holdings(Portfolio $portfolio)
    {
        Gate::authorize('view', $portfolio);

        $portfolio->loadMissing([
            'positions.asset.latestPriceRecord',
            'cashMovements',
        ]);

        $summary = $this->calculator->compute($portfolio);

        return ApiResponse::success([
            'portfolio_id' => $summary['portfolio_id'],
            'holdings' => $summary['holdings'],
        ]);
    }

    public function allocations(Portfolio $portfolio)
    {
        Gate::authorize('view', $portfolio);

        $portfolio->loadMissing([
            'positions.asset.latestPriceRecord',
            'cashMovements',
        ]);

        $summary = $this->calculator->compute($portfolio);

        return ApiResponse::success([
            'portfolio_id' => $summary['portfolio_id'],
            'total_market_value' => $summary['total_market_value'],
            'allocation_by_symbol' => $summary['allocation_by_symbol'],
            'allocation_by_sector' => $summary['allocation_by_sector'],
        ]);
    }
}
