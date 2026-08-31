<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Resources\ApiResponse;
use App\Models\Portfolio;
use App\Services\Execution\ExecutionCandidateService;
use App\Services\Execution\ExecutionStatus;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * The one endpoint the execution workspace calls.
 *
 * Composed server-side on purpose. The page needs technicals, the watchlist
 * score, features, broker windows and optionally the portfolio's holdings for
 * every row; assembling that in the browser would be five requests per screen
 * plus one per row, and would put the ranking logic somewhere it could drift
 * from the backend's.
 */
class ExecutionCandidateController extends ApiController
{
    public function index(Request $request, ExecutionCandidateService $candidates)
    {
        $data = $request->validate([
            'date' => ['sometimes', 'date'],
            'version' => ['sometimes', 'string', 'max:32'],
            'status' => ['sometimes', 'array'],
            'status.*' => ['string', 'in:'.implode(',', ExecutionStatus::ALL)],
            'symbol' => ['sometimes', 'array'],
            'symbol.*' => ['string', 'max:20'],
            'sector' => ['sometimes', 'string', 'max:100'],
            'min_score' => ['sometimes', 'numeric', 'min:0', 'max:100'],
            'min_rr' => ['sometimes', 'numeric', 'min:0', 'max:100'],
            'portfolio_id' => ['sometimes', 'integer'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:500'],
        ]);

        // Holdings are only attached for a portfolio the caller may read.
        // Without this the endpoint would leak one user's positions to
        // anybody who guessed an id.
        $portfolioId = null;

        if (isset($data['portfolio_id'])) {
            $portfolio = Portfolio::query()->find((int) $data['portfolio_id']);

            if ($portfolio === null) {
                return ApiResponse::error('Portfolio not found.', 404);
            }

            Gate::authorize('view', $portfolio);
            $portfolioId = (int) $portfolio->id;
        }

        return ApiResponse::success($candidates->candidates([
            'date' => $data['date'] ?? null,
            'version' => $data['version'] ?? null,
            'statuses' => $data['status'] ?? null,
            'symbols' => $data['symbol'] ?? null,
            'sector' => $data['sector'] ?? null,
            'min_score' => $data['min_score'] ?? null,
            'min_rr' => $data['min_rr'] ?? null,
            'limit' => $data['limit'] ?? null,
            'portfolio_id' => $portfolioId,
        ]));
    }
}
