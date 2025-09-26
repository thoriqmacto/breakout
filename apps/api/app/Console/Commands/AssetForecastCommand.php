<?php

namespace App\Console\Commands;

use App\Models\Asset;
use App\Services\Strategies\HLSLBreakoutStrategy;
use Illuminate\Console\Command;

class AssetForecastCommand extends Command
{
    protected $signature = 'asset:forecast
        {--sym=* : Comma-separated or repeated tickers to analyze}
        {--strategy=HLSLBreakout : Strategy identifier (currently only HLSLBreakout)}';

    protected $description = 'Forecast potential entry levels for assets using a breakout strategy.';

    public function handle(): int
    {
        $tickers = $this->resolveTickers();
        if ($tickers === []) {
            $this->error('At least one ticker must be provided via --sym.');
            return Command::FAILURE;
        }

        $strategyOption = (string) $this->option('strategy');
        if ($strategyOption === '') {
            $strategyOption = 'HLSLBreakout';
        }

        if (strcasecmp($strategyOption, 'HLSL') === 0) {
            $strategyOption = 'HLSLBreakout';
        }

        if ($strategyOption !== 'HLSLBreakout') {
            $this->error("Unsupported strategy: {$strategyOption}. Only HLSLBreakout is available at the moment.");
            return Command::FAILURE;
        }

        $strategy = new HLSLBreakoutStrategy();
        $rows = [];

        foreach ($tickers as $symbol) {
            $bars = $this->loadBars($symbol);
            if ($bars === null) {
                continue;
            }

            $analysis = $strategy->analyze($bars);
            $dailyData = $analysis['daily'];
            if ($dailyData === []) {
                $this->warn("{$symbol}: insufficient price history.");
                continue;
            }

            $latestDay = $dailyData[array_key_last($dailyData)];
            $latestClose = (float) $latestDay['close'];
            $latestDate = $latestDay['date']->toDateString();

            $forecast = $this->forecastNextEntry($analysis['weekly'], $analysis['signals']);

            $entryPrice = $forecast['entry_price'];
            $distancePct = null;
            if ($entryPrice !== null && $latestClose > 0.0) {
                $distancePct = (($entryPrice - $latestClose) / $latestClose) * 100.0;
            }

            $rows[] = [
                'symbol' => $symbol,
                'last_close' => sprintf('%.4f', $latestClose),
                'last_date' => $latestDate,
                'entry_price' => $entryPrice !== null ? sprintf('%.4f', $entryPrice) : '—',
                'distance_pct' => $distancePct !== null ? sprintf('%.2f%%', $distancePct) : '—',
                'swing_week' => $forecast['week_end'] ?? '—',
                'volume_ema' => $forecast['volume_ema'] !== null
                    ? number_format((float) $forecast['volume_ema'], 0, '.', ',')
                    : '—',
                'volume_target' => $forecast['volume_target'] !== null
                    ? number_format((float) $forecast['volume_target'], 0, '.', ',')
                    : '—',
                'note' => $forecast['note'] ?? '',
            ];
        }

        if ($rows === []) {
            $this->warn('No rows to display.');
            return Command::FAILURE;
        }

        $this->table([
            'Ticker',
            'Last Close',
            'Last Close Date',
            'Trigger Price',
            'Distance %',
            'Swing Week End',
            'Volume EMA',
            'Volume Target (1.2x)',
            'Note',
        ], array_map(static function (array $row) {
            return [
                $row['symbol'],
                $row['last_close'],
                $row['last_date'],
                $row['entry_price'],
                $row['distance_pct'],
                $row['swing_week'],
                $row['volume_ema'],
                $row['volume_target'],
                $row['note'],
            ];
        }, $rows));

        return Command::SUCCESS;
    }

    /**
     * Determine the next actionable swing high and associated volume filters.
     *
     * @param array<int, array<string, mixed>> $weeklyData
     * @param array<int, array<string, mixed>> $signals
     * @return array{
     *     entry_price: float|null,
     *     week_end: string|null,
     *     volume_ema: float|null,
     *     volume_target: float|null,
     *     note: string|null
     * }
     */
    private function forecastNextEntry(array $weeklyData, array $signals): array
    {
        if ($weeklyData === []) {
            return [
                'entry_price' => null,
                'week_end' => null,
                'volume_ema' => null,
                'volume_target' => null,
                'note' => 'Not enough weekly data.',
            ];
        }

        $lastSignalIndex = null;
        if ($signals !== []) {
            $lastSignal = end($signals);
            $lastSignalIndex = is_array($lastSignal) ? (int) $lastSignal['week_index'] : null;
            reset($signals);
        }

        for ($i = count($weeklyData) - 1; $i >= 0; $i--) {
            $week = $weeklyData[$i];
            $isSwingHigh = ! empty($week['is_swing_high']);
            if (! $isSwingHigh) {
                continue;
            }

            if ($lastSignalIndex !== null && $i <= $lastSignalIndex) {
                break;
            }

            $volumeEma = isset($week['volume_ema']) ? (float) $week['volume_ema'] : null;

            return [
                'entry_price' => (float) $week['high'],
                'week_end' => $week['end_date']->toDateString(),
                'volume_ema' => $volumeEma,
                'volume_target' => $volumeEma !== null ? $volumeEma * 1.2 : null,
                'note' => null,
            ];
        }

        if ($lastSignalIndex !== null && isset($weeklyData[$lastSignalIndex])) {
            $signalWeek = $weeklyData[$lastSignalIndex];
            $volumeEma = isset($signalWeek['volume_ema']) ? (float) $signalWeek['volume_ema'] : null;

            return [
                'entry_price' => null,
                'week_end' => $signalWeek['end_date']->toDateString(),
                'volume_ema' => $volumeEma,
                'volume_target' => $volumeEma !== null ? $volumeEma * 1.2 : null,
                'note' => 'Latest swing high already triggered. Await new setup.',
            ];
        }

        return [
            'entry_price' => null,
            'week_end' => null,
            'volume_ema' => null,
            'volume_target' => null,
            'note' => 'No swing highs detected.',
        ];
    }

    /**
     * @return array<int, string>
     */
    private function resolveTickers(): array
    {
        $raw = $this->option('sym');
        if (is_string($raw)) {
            $raw = [$raw];
        } elseif (! is_array($raw)) {
            $raw = [];
        }

        $values = [];
        foreach ($raw as $value) {
            if (is_string($value) && trim($value) !== '') {
                $values = array_merge($values, explode(',', $value));
            }
        }

        $values = array_map(static fn ($ticker) => strtoupper(trim((string) $ticker)), $values);
        $values = array_filter($values, static fn ($ticker) => $ticker !== '');

        return array_values(array_unique($values));
    }

    /**
     * @return array<int, array<string, mixed>>|null
     */
    private function loadBars(string $symbol): ?array
    {
        $asset = Asset::where('symbol', $symbol)->first();
        if (! $asset) {
            $this->warn("Asset {$symbol} not found. Skipping.");
            return null;
        }

        $prices = $asset->prices()->orderBy('date')->get(['date', 'open', 'high', 'low', 'close', 'volume']);
        if ($prices->isEmpty()) {
            $this->warn("No price data available for {$symbol}. Skipping.");
            return null;
        }

        return $prices->map(static function ($price) {
            $date = $price->date instanceof \DateTimeInterface
                ? $price->date->toDateString()
                : (string) $price->date;

            return [
                'date' => $date,
                'open' => (float) $price->open,
                'high' => (float) $price->high,
                'low' => (float) $price->low,
                'close' => (float) $price->close,
                'volume' => (float) $price->volume,
            ];
        })->all();
    }
}
