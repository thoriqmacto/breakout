<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Resources\ApiResponse;
use App\Models\Portfolio;
use App\Services\Portfolio\Import\StockbitPortfolioImporter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;

/**
 * Import a pasted Stockbit API response into a portfolio's ledger.
 *
 * Deliberately thin. Every decision -- what the payload is, which rows are
 * importable, what already exists, what the numbers reconcile to -- belongs to
 * StockbitPortfolioImporter, which is testable without an HTTP request. This
 * class authorizes, validates the envelope, and shapes the response.
 *
 * `preview` writes nothing. `commit` re-runs the same analysis server-side
 * rather than trusting whatever the browser echoes back, so a tampered preview
 * cannot become a tampered import.
 */
class PortfolioImportController extends ApiController
{
    /**
     * Enough for years of history, and small enough that a paste cannot be
     * used to exhaust memory.
     */
    private const MAX_PAYLOAD_BYTES = 4_194_304;

    public function __construct(private readonly StockbitPortfolioImporter $importer) {}

    public function preview(Request $request, Portfolio $portfolio)
    {
        Gate::authorize('update', $portfolio);

        $data = $this->validatePayload($request);

        try {
            $analysis = $this->importer->analyze($portfolio, $data['payload'], $this->options($data));
        } catch (InvalidArgumentException $exception) {
            return ApiResponse::error($exception->getMessage(), 422, [
                'payload' => [$exception->getMessage()],
            ]);
        }

        return ApiResponse::success($analysis);
    }

    public function store(Request $request, Portfolio $portfolio)
    {
        Gate::authorize('update', $portfolio);

        $data = $this->validatePayload($request);

        try {
            $result = $this->importer->commit($portfolio, $data['payload'], $this->options($data));
        } catch (InvalidArgumentException $exception) {
            return ApiResponse::error($exception->getMessage(), 422, [
                'payload' => [$exception->getMessage()],
            ]);
        }

        if (! ($result['committed'] ?? false)) {
            return ApiResponse::error(
                'The import has blocking errors and was not applied.',
                422,
                ['import' => $result],
            );
        }

        return ApiResponse::success($result, $this->summarize($result));
    }

    /**
     * Say what actually happened, including when that is nothing.
     *
     * @param  array<string, mixed>  $result
     */
    private function summarize(array $result): string
    {
        $positions = (int) $result['created']['positions'];
        $movements = (int) $result['created']['cash_movements'];
        $duplicates = (int) ($result['totals'][StockbitPortfolioImporter::STATUS_DUPLICATE] ?? 0);

        if ($positions === 0 && $movements === 0) {
            return $duplicates > 0
                ? sprintf('Nothing new to import; %d record(s) were already imported.', $duplicates)
                : 'Nothing new to import.';
        }

        return sprintf(
            'Imported %d transaction(s) and %d cash movement(s).%s',
            $positions,
            $movements,
            $duplicates > 0 ? sprintf(' %d were already imported.', $duplicates) : '',
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function validatePayload(Request $request): array
    {
        return $request->validate([
            'payload' => ['required', 'string', 'max:'.self::MAX_PAYLOAD_BYTES],
            // Snapshot-only opt-ins. Both default to false so nothing
            // synthetic is ever created, and no cash is ever moved, without
            // the user asking for it in this request.
            'create_snapshot_positions' => ['sometimes', 'boolean'],
            'reconcile_cash' => ['sometimes', 'boolean'],
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, bool>
     */
    private function options(array $data): array
    {
        return [
            'create_snapshot_positions' => (bool) ($data['create_snapshot_positions'] ?? false),
            'reconcile_cash' => (bool) ($data['reconcile_cash'] ?? false),
        ];
    }
}
