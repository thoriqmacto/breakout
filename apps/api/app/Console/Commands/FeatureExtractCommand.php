<?php

namespace App\Console\Commands;

use App\Models\Asset;
use App\Models\Price;
use App\Services\FeatureExtractionService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

class FeatureExtractCommand extends Command
{
    protected $signature = 'features:extract
        {--symbol= : Only extract features for a single symbol}
        {--date= : Extract features for a single trading date (YYYY-MM-DD)}
        {--from= : Extract features starting from date (YYYY-MM-DD)}
        {--to= : Extract features ending at date (YYYY-MM-DD)}
        {--limit= : Limit number of assets processed}';

    protected $description = 'Extract daily OHLCV and broker features (including PBAS) for assets and persist them to features_daily.';

    public function __construct(private readonly FeatureExtractionService $features)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $symbol = $this->option('symbol');
        $date = $this->option('date');
        $from = $this->option('from');
        $to = $this->option('to');
        $limit = $this->option('limit');

        $assets = Asset::query()
            ->when($symbol, fn ($query) => $query->where('symbol', strtoupper((string) $symbol)))
            ->orderBy('symbol')
            ->when($limit, fn ($query) => $query->limit((int) $limit))
            ->get();

        if ($assets->isEmpty()) {
            $this->warn('No assets matched the requested filters.');

            return self::SUCCESS;
        }

        $headers = null;
        $rows = [];

        foreach ($assets as $asset) {
            $dates = $this->resolveDatesForAsset($asset->id, $date, $from, $to);
            if ($dates === []) {
                $this->warn("Skipping {$asset->symbol}; no price bars found for requested dates.");

                continue;
            }

            foreach ($dates as $resolvedDate) {
                $payload = $this->features->upsertDailyFeatures(
                    $asset,
                    $resolvedDate
                );

                $headers ??= array_keys($payload);
                $rows[] = array_map(
                    static fn (string $key): string => is_bool($payload[$key] ?? null)
                        ? (($payload[$key] ?? false) ? '1' : '0')
                        : (string) ($payload[$key] ?? ''),
                    $headers
                );
            }
        }

        if ($headers && $rows !== []) {
            $this->table($headers, $rows);
        }

        return self::SUCCESS;
    }

    /**
     * @return array<int, Carbon>
     */
    private function resolveDatesForAsset(int $assetId, ?string $date, ?string $from, ?string $to): array
    {
        if ($date) {
            return [Carbon::parse($date)];
        }

        $rangeStart = $from ? Carbon::parse($from) : null;
        $rangeEnd = $to ? Carbon::parse($to) : null;

        if ($rangeStart === null && $rangeEnd === null) {
            $latest = Price::query()
                ->where('asset_id', $assetId)
                ->max('date');
            if (! $latest) {
                return [];
            }

            return [Carbon::parse($latest)];
        }

        if ($rangeStart === null && $rangeEnd !== null) {
            $rangeStart = $rangeEnd->copy();
        }

        if ($rangeEnd === null && $rangeStart !== null) {
            $rangeEnd = $rangeStart->copy();
        }

        if ($rangeStart === null || $rangeEnd === null) {
            return [];
        }

        return Price::query()
            ->where('asset_id', $assetId)
            ->whereBetween('date', [$rangeStart->toDateString(), $rangeEnd->toDateString()])
            ->orderBy('date')
            ->pluck('date')
            ->map(static fn ($value): Carbon => Carbon::parse($value))
            ->all();
    }
}
