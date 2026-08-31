<?php

namespace App\Console\Commands;

use App\Models\Asset;
use App\Services\Analysis\AssetMetricProjector;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * The structural metrics table, on the command line.
 *
 * A thin caller. Every figure and the ordering come from
 * AssetMetricProjector, which is the same code path
 * `POST /v1/assets/metrics/update` and `GET /v1/assets/metrics` take -- so the
 * rank shown here and the rank shown in the browser cannot disagree, which
 * they previously did: this command ranked on ROC13 while the API ranked on
 * PBAS, and both called the column "Rank".
 *
 * What this answers is "which stocks are structurally strong", not "which
 * should I trade tomorrow". The second question belongs to the execution
 * workspace, which scores broker accumulation, breakout confirmation,
 * liquidity and risk on top of these same snapshots.
 */
class AssetMetricsCommand extends Command
{
    protected $signature = 'asset:metrics
        {--sym= : Comma-separated symbols}
        {--all : Every asset}
        {--as-of= : Structural snapshot as of this date (YYYY-MM-DD, default: latest session)}
        {--persist : Write the results into the metrics cache}';

    protected $description = 'Show the canonical structural metrics and structural rank for one or more assets.';

    public function handle(AssetMetricProjector $projector): int
    {
        $asOf = $this->resolveAsOf();

        if ($asOf === false) {
            return self::INVALID;
        }

        $assets = $this->resolveAssets();

        if ($assets === []) {
            $this->warn('No matching assets.');

            return self::SUCCESS;
        }

        $rows = $projector->project($assets, $asOf);

        if ($rows === []) {
            $this->warn('None of the selected assets has a price bar on or before that date.');

            return self::SUCCESS;
        }

        $headers = [
            'Rank', 'Symbol', 'As of', 'Close', 'MA50', 'MA100', '20wH', '55wH', 'ATR14',
            'ROC13', 'AvgVol20', 'Vol/Avg20', 'Close/20wH', 'Close/55wH', 'IsUptrend?',
            'Bars', 'PBAS', 'BAVG',
        ];

        $this->table($headers, array_map(static fn (array $row): array => [
            $row['structural_rank'],
            $row['symbol'],
            $row['as_of_date'],
            $row['close'],
            $row['ma50'],
            $row['ma100'],
            $row['high20'],
            $row['high55'],
            $row['atr14'],
            $row['roc13'],
            $row['avg_vol20'],
            $row['vol_vs_avg20'],
            $row['close_vs_high20'],
            $row['close_vs_high55'],
            $row['uptrend'] ? 'Yes' : 'No',
            $row['bars'],
            $row['pbas'],
            $row['bavg'],
        ], $rows));

        if ($this->option('persist')) {
            $persisted = $projector->persist($rows);
            $forgotten = $projector->forgetMissing(
                array_map(static fn (Asset $asset): int => (int) $asset->id, $assets),
                $rows,
            );

            $this->info(sprintf('Persisted metrics for %d asset(s).', $persisted));

            if ($forgotten > 0) {
                $this->line(sprintf('Removed %d cached row(s) for assets with no price history.', $forgotten));
            }
        }

        return self::SUCCESS;
    }

    /**
     * @return array<int, Asset>
     */
    private function resolveAssets(): array
    {
        if ($this->option('all')) {
            return Asset::query()->orderBy('symbol')->get()->all();
        }

        $raw = (string) ($this->option('sym') ?? '');

        if (trim($raw) === '') {
            $raw = (string) $this->ask('Enter symbols (comma separated)');
        }

        $symbols = array_values(array_filter(array_map(
            static fn (string $value): string => strtoupper(trim($value)),
            explode(',', $raw),
        )));

        if ($symbols === []) {
            return [];
        }

        return Asset::query()->whereIn('symbol', $symbols)->orderBy('symbol')->get()->all();
    }

    private function resolveAsOf(): Carbon|false|null
    {
        $option = $this->option('as-of');

        if (! is_string($option) || trim($option) === '') {
            return null;
        }

        try {
            return Carbon::parse(trim($option))->startOfDay();
        } catch (Throwable) {
            $this->error('--as-of must be a YYYY-MM-DD date.');

            return false;
        }
    }
}
