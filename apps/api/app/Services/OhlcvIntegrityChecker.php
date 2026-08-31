<?php

namespace App\Services;

use App\Models\Asset;
use App\Models\TradingDay;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class OhlcvIntegrityChecker
{
    public function __construct(
        private readonly TradingDay $tradingDay,
        private readonly Asset $asset,
    ) {}

    /**
     * Build integrity reports for every asset with OHLCV data.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function checkAll(?string $from = null, ?string $to = null): Collection
    {
        return $this->asset->newQuery()
            ->orderBy('symbol')
            ->get()
            ->map(fn (Asset $asset) => $this->checkAsset($asset, $from, $to));
    }

    /**
     * Create an integrity report for a single asset.
     *
     * @return array{
     *     asset_id:int,
     *     symbol:?string,
     *     range_start:?string,
     *     range_end:?string,
     *     first_bar:?string,
     *     last_bar:?string,
     *     global_first_bar:?string,
     *     global_last_bar:?string,
     *     total_rows:int,
     *     unique_rows:int,
     *     expected_trading_days:int,
     *     actual_trading_days:int,
     *     missing_trading_days:array<int, string>,
     *     extra_bars:array<int, string>,
     *     duplicate_bars:array<int, string>,
     *     is_consistent:bool,
     *     has_trading_day_data:bool
     * }
     */
    public function checkAsset(Asset $asset, ?string $from = null, ?string $to = null): array
    {
        $rangeRow = DB::table('price_bars')
            ->selectRaw('MIN(date) as min_date, MAX(date) as max_date, COUNT(*) as total_rows')
            ->where('asset_id', $asset->id)
            ->first();

        $globalFirst = $rangeRow?->min_date ? Carbon::parse($rangeRow->min_date)->toDateString() : null;
        $globalLast = $rangeRow?->max_date ? Carbon::parse($rangeRow->max_date)->toDateString() : null;

        $resolvedStart = $this->resolveBoundary($from, $globalFirst);
        $resolvedEnd = $this->resolveBoundary($to, $globalLast);

        if ($resolvedStart === null || $resolvedEnd === null) {
            return $this->emptyReport($asset, $resolvedStart, $resolvedEnd, $globalFirst, $globalLast);
        }

        if ($resolvedEnd->lessThan($resolvedStart)) {
            throw new InvalidArgumentException('The end date must be greater than or equal to the start date.');
        }

        [$barDateCounts, $totalRows, $uniqueRows] = $this->loadBarStatistics($asset, $resolvedStart, $resolvedEnd);

        $barDates = $barDateCounts->keys()->values()->all();
        $duplicates = $barDateCounts
            ->filter(static fn (int $count) => $count > 1)
            ->keys()
            ->values()
            ->all();

        $tradingDays = $this->loadTradingDays($resolvedStart, $resolvedEnd);
        $missingTradingDays = array_values(array_diff($tradingDays, $barDates));
        $extraBars = array_values(array_diff($barDates, $tradingDays));

        $firstBar = $barDates[0] ?? null;
        $lastBar = $barDates !== [] ? $barDates[array_key_last($barDates)] : null;

        return [
            'asset_id' => $asset->id,
            'symbol' => $asset->symbol,
            'range_start' => $resolvedStart->toDateString(),
            'range_end' => $resolvedEnd->toDateString(),
            'first_bar' => $firstBar,
            'last_bar' => $lastBar,
            'global_first_bar' => $globalFirst,
            'global_last_bar' => $globalLast,
            'total_rows' => $totalRows,
            'unique_rows' => $uniqueRows,
            'expected_trading_days' => count($tradingDays),
            'actual_trading_days' => $uniqueRows,
            'missing_trading_days' => $missingTradingDays,
            'extra_bars' => $extraBars,
            'duplicate_bars' => $duplicates,
            'is_consistent' => $missingTradingDays === [] && $extraBars === [] && $duplicates === [],
            'has_trading_day_data' => $tradingDays !== [],
        ];
    }

    /**
     * @return array{
     *     asset_id:int,
     *     symbol:?string,
     *     range_start:?string,
     *     range_end:?string,
     *     first_bar:null,
     *     last_bar:null,
     *     global_first_bar:?string,
     *     global_last_bar:?string,
     *     total_rows:0,
     *     unique_rows:0,
     *     expected_trading_days:0,
     *     actual_trading_days:0,
     *     missing_trading_days:array<int, string>,
     *     extra_bars:array<int, string>,
     *     duplicate_bars:array<int, string>,
     *     is_consistent:false,
     *     has_trading_day_data:false
     * }
     */
    private function emptyReport(Asset $asset, ?Carbon $rangeStart, ?Carbon $rangeEnd, ?string $globalFirst, ?string $globalLast): array
    {
        return [
            'asset_id' => $asset->id,
            'symbol' => $asset->symbol,
            'range_start' => $rangeStart?->toDateString(),
            'range_end' => $rangeEnd?->toDateString(),
            'first_bar' => null,
            'last_bar' => null,
            'global_first_bar' => $globalFirst,
            'global_last_bar' => $globalLast,
            'total_rows' => 0,
            'unique_rows' => 0,
            'expected_trading_days' => 0,
            'actual_trading_days' => 0,
            'missing_trading_days' => [],
            'extra_bars' => [],
            'duplicate_bars' => [],
            'is_consistent' => false,
            'has_trading_day_data' => false,
        ];
    }

    /**
     * @return array{Collection<string, int>, int, int}
     */
    private function loadBarStatistics(Asset $asset, Carbon $start, Carbon $end): array
    {
        $rows = DB::table('price_bars')
            ->selectRaw('date, COUNT(*) as total')
            ->where('asset_id', $asset->id)
            ->whereDate('date', '>=', $start->toDateString())
            ->whereDate('date', '<=', $end->toDateString())
            ->groupBy('date')
            ->orderBy('date')
            ->get();

        $barDateCounts = $rows->mapWithKeys(
            static fn (object $row): array => [Carbon::parse($row->date)->toDateString() => (int) $row->total]
        );

        return [
            $barDateCounts,
            (int) $barDateCounts->sum(),
            $barDateCounts->count(),
        ];
    }

    /**
     * @return array<int, string>
     */
    private function loadTradingDays(Carbon $start, Carbon $end): array
    {
        return $this->tradingDay->newQuery()
            ->whereDate('date', '>=', $start->toDateString())
            ->whereDate('date', '<=', $end->toDateString())
            ->orderBy('date')
            ->pluck('date')
            ->map(static fn ($date): string => Carbon::parse($date)->toDateString())
            ->all();
    }

    private function resolveBoundary(?string $candidate, ?string $fallback): ?Carbon
    {
        $value = $candidate ?? $fallback;
        if ($value === null) {
            return null;
        }

        return Carbon::parse($value)->startOfDay();
    }
}
