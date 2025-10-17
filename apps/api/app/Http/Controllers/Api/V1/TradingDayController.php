<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Resources\ApiResponse;
use App\Models\Asset;
use App\Models\Price;
use App\Models\TradingDay;
use Illuminate\Http\Request;

class TradingDayController extends ApiController
{
    private const FALLBACK_SYMBOLS = ['ANTM', 'INCO', 'MDKA', 'ASII', 'TEBE'];

    public function index(Request $request)
    {
        $symbols = $this->resolveSymbols($request);

        $statistics = $this->buildStatistics();

        $perPage = (int) $request->query('per_page', 30);
        $perPage = max(1, min($perPage, 200));

        $paginator = TradingDay::query()
            ->orderByDesc('date')
            ->paginate($perPage);

        $collection = collect($paginator->items());

        if ($collection->isEmpty()) {
            return ApiResponse::success([
                'symbols' => $symbols,
                'trading_days' => [],
                'pagination' => $this->buildPaginationMeta($paginator),
            ]);
        }

        $datesDesc = $collection
            ->map(fn (TradingDay $day) => $day->date->toDateString())
            ->values()
            ->all();
        $datesAsc = array_reverse($datesDesc);

        $indexCloseByDate = [];
        foreach ($collection as $day) {
            $indexCloseByDate[$day->date->toDateString()] = $day->close !== null
                ? (float) $day->close
                : null;
        }

        $indexChangeByDate = [];
        $previousIndexClose = null;
        foreach ($datesAsc as $date) {
            $currentClose = $indexCloseByDate[$date] ?? null;
            $change = null;

            if ($currentClose !== null && $previousIndexClose !== null && $previousIndexClose != 0.0) {
                $change = (($currentClose - $previousIndexClose) / $previousIndexClose) * 100;
            }

            $indexChangeByDate[$date] = $change;

            if ($currentClose !== null) {
                $previousIndexClose = $currentClose;
            }
        }

        $assetMap = $this->loadAssets($symbols);
        $symbolByAssetId = array_flip($assetMap);

        $priceRows = [];
        if ($assetMap !== []) {
            $priceRows = Price::query()
                ->select(['asset_id', 'date', 'close'])
                ->whereIn('asset_id', array_values($assetMap))
                ->whereIn('date', $datesDesc)
                ->orderBy('asset_id')
                ->orderBy('date')
                ->get();
        }

        $priceBySymbolAndDate = [];
        foreach ($priceRows as $price) {
            $symbol = $symbolByAssetId[$price->asset_id] ?? null;
            if ($symbol === null) {
                continue;
            }

            $priceBySymbolAndDate[$symbol][$price->date->toDateString()] = $price->close !== null
                ? (float) $price->close
                : null;
        }

        $symbolHistory = [];
        foreach ($symbols as $symbol) {
            $previousClose = null;
            foreach ($datesAsc as $date) {
                $close = $priceBySymbolAndDate[$symbol][$date] ?? null;
                $change = null;

                if ($close !== null && $previousClose !== null && $previousClose != 0.0) {
                    $change = (($close - $previousClose) / $previousClose) * 100;
                }

                $symbolHistory[$date][$symbol] = [
                    'close' => $close,
                    'change_percent' => $change,
                ];

                if ($close !== null) {
                    $previousClose = $close;
                }
            }
        }

        $rows = [];
        foreach ($collection as $day) {
            $date = $day->date->toDateString();
            $symbolsPayload = [];

            foreach ($symbols as $symbol) {
                $symbolsPayload[$symbol] = $symbolHistory[$date][$symbol] ?? [
                    'close' => null,
                    'change_percent' => null,
                ];
            }

            $rows[] = [
                'trading_date' => $date,
                'close' => $indexCloseByDate[$date] ?? null,
                'change_percent' => $indexChangeByDate[$date] ?? null,
                'symbols' => $symbolsPayload,
            ];
        }

        return ApiResponse::success([
            'symbols' => $symbols,
            'trading_days' => $rows,
            'pagination' => $this->buildPaginationMeta($paginator),
            'statistics' => $statistics,
        ]);
    }

    private function resolveSymbols(Request $request): array
    {
        $symbolsParam = $request->query('symbols');
        $symbols = [];

        if (is_string($symbolsParam)) {
            $symbols = array_filter(array_map('trim', explode(',', $symbolsParam)));
        } elseif (is_array($symbolsParam)) {
            $symbols = array_filter(array_map('trim', $symbolsParam));
        }

        $symbols = array_map(fn ($symbol) => strtoupper($symbol), $symbols);
        $symbols = array_values(array_unique(array_filter($symbols)));

        if ($symbols === []) {
            $symbols = $this->defaultSymbols();
        }

        return $symbols;
    }

    private function defaultSymbols(): array
    {
        $configured = config('csv.index_symbols', []);

        if (is_array($configured) && $configured !== []) {
            return array_values(array_unique(array_map(fn ($symbol) => strtoupper((string) $symbol), $configured)));
        }

        return self::FALLBACK_SYMBOLS;
    }

    private function loadAssets(array $symbols): array
    {
        if ($symbols === []) {
            return [];
        }

        return Asset::query()
            ->whereIn('symbol', $symbols)
            ->pluck('id', 'symbol')
            ->mapWithKeys(fn ($id, $symbol) => [strtoupper($symbol) => (int) $id])
            ->all();
    }

    private function buildPaginationMeta($paginator): array
    {
        return [
            'current_page' => $paginator->currentPage(),
            'per_page' => $paginator->perPage(),
            'last_page' => $paginator->lastPage(),
            'total' => $paginator->total(),
        ];
    }

    private function buildStatistics(): array
    {
        $rows = TradingDay::query()
            ->orderBy('date')
            ->get(['date', 'close']);

        if ($rows->isEmpty()) {
            return [
                'total_valid_trading_days' => 0,
                'highest_change' => null,
                'lowest_change' => null,
            ];
        }

        $totalValidTradingDays = 0;
        $previousClose = null;
        $highestChange = null;
        $highestDate = null;
        $lowestChange = null;
        $lowestDate = null;

        foreach ($rows as $day) {
            $close = $day->close !== null ? (float) $day->close : null;

            if ($close !== null) {
                $totalValidTradingDays++;

                if ($previousClose !== null && $previousClose != 0.0) {
                    $change = (($close - $previousClose) / $previousClose) * 100;

                    if ($highestChange === null || $change > $highestChange) {
                        $highestChange = $change;
                        $highestDate = $day->date->toDateString();
                    }

                    if ($lowestChange === null || $change < $lowestChange) {
                        $lowestChange = $change;
                        $lowestDate = $day->date->toDateString();
                    }
                }

                $previousClose = $close;
            }
        }

        return [
            'total_valid_trading_days' => $totalValidTradingDays,
            'highest_change' => $highestChange === null
                ? null
                : [
                    'date' => $highestDate,
                    'change_percent' => $highestChange,
                ],
            'lowest_change' => $lowestChange === null
                ? null
                : [
                    'date' => $lowestDate,
                    'change_percent' => $lowestChange,
                ],
        ];
    }
}
