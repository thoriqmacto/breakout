<?php

namespace App\Http\Controllers\Api\V1;

use App\Services\Backtest\HLSLBreakoutBacktestService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BacktestController extends ApiController
{
    public function __construct(private readonly HLSLBreakoutBacktestService $service)
    {
    }

    public function run(Request $request): JsonResponse
    {
        $tickersParam = $request->query('tickers');
        if (empty($tickersParam)) {
            return response()->json([
                'message' => 'The tickers query parameter is required.',
            ], 422);
        }

        $tickers = array_filter(array_map('trim', explode(',', $tickersParam)));
        $tickers = array_map('strtoupper', $tickers);

        $dataInput = $request->input('data', []);
        if (is_string($dataInput)) {
            $decoded = json_decode($dataInput, true);
            $dataRows = is_array($decoded) ? $decoded : [];
        } elseif (is_array($dataInput)) {
            $dataRows = $dataInput;
        } else {
            $dataRows = [];
        }

        $grouped = [];
        foreach ($dataRows as $row) {
            if (! is_array($row) || empty($row['ticker'])) {
                continue;
            }

            $ticker = strtoupper($row['ticker']);
            $grouped[$ticker][] = $row;
        }

        $selectedData = [];
        foreach ($tickers as $ticker) {
            $selectedData[$ticker] = $grouped[$ticker] ?? [];
        }

        $initialCapital = (float) $request->input('initial_capital', 100000);
        $boardLot = (int) $request->input('board_lot', 100);

        $result = $this->service->run($selectedData, $initialCapital, max($boardLot, 1));

        return response()->json($result);
    }
}
