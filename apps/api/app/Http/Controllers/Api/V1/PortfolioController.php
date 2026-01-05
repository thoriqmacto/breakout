<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Resources\ApiResponse;
use App\Http\Resources\PortfolioResource;
use App\Models\Portfolio;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class PortfolioController extends ApiController
{
    public function index()
    {
        $query = Portfolio::query()->withCount('positions');

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
        ]);

        if ($validator->fails()) {
            return ApiResponse::error('Validation failed', 422, $validator->errors());
        }

        $portfolio = Portfolio::create($validator->validated());

        return ApiResponse::success(new PortfolioResource($portfolio), 'Portfolio created', 201);
    }

    public function show(Portfolio $portfolio)
    {
        $portfolio
            ->loadMissing(['positions.asset.latestPriceRecord'])
            ->loadCount('positions');

        return ApiResponse::success(new PortfolioResource($portfolio));
    }

    public function update(Request $request, Portfolio $portfolio)
    {
        $validator = Validator::make($request->all(), [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'base_ccy' => ['sometimes', 'required', 'string', 'max:10'],
            'remarks' => ['sometimes', 'nullable', 'string', 'max:255'],
            'year' => ['sometimes', 'nullable', 'integer', 'between:1900,3000'],
        ]);

        if ($validator->fails()) {
            return ApiResponse::error('Validation failed', 422, $validator->errors());
        }

        $portfolio->update($validator->validated());

        return ApiResponse::success(new PortfolioResource($portfolio), 'Portfolio updated');
    }

    public function destroy(Portfolio $portfolio)
    {
        $portfolio->delete();

        return ApiResponse::success(null, 'Portfolio deleted');
    }
}
