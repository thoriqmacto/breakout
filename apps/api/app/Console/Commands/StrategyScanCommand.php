<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class StrategyScanCommand extends Command
{
    protected $signature = 'strategy:scan-accumulation
        {--date= : Scan date in YYYY-MM-DD (default: latest features date)}
        {--anchor-date= : Anchor start date for broker accumulation baseline (default: 60 days before scan date)}
        {--symbol=* : Restrict scan to one or many symbols}
        {--max-ret=-0.005 : Max daily return (negative means down day)}
        {--max-vol-ratio=0.95 : Max daily vol_ratio_20 for lower volume day}
        {--min-anchor-net-norm=0.01 : Minimum anchored average of avg_net_norm}
        {--min-net-norm=0.01 : Minimum current-day avg_net_norm for absorption proxy}
        {--min-pbas=70 : Minimum PBAS score to represent lower timeframe absorption}
        {--limit=30 : Max number of symbols in output}';

    protected $description = 'Scan accumulation anomalies: down day + lower volume + absorption proxy + anchored broker accumulation.';

    public function handle(): int
    {
        $scanDate = $this->resolveScanDate((string) ($this->option('date') ?? ''));
        if ($scanDate === null) {
            $this->error('Invalid --date value. Use YYYY-MM-DD.');

            return self::FAILURE;
        }

        if ($scanDate === false) {
            $this->warn('No rows found in features_daily. Run php artisan features:extract first.');

            return self::SUCCESS;
        }

        $anchorDate = $this->resolveAnchorDate($scanDate, (string) ($this->option('anchor-date') ?? ''));
        if ($anchorDate === null) {
            $this->error('Invalid --anchor-date value. Use YYYY-MM-DD and ensure it is not after --date.');

            return self::FAILURE;
        }

        $symbols = $this->normalizeSymbols($this->option('symbol'));
        $maxRet = (float) $this->option('max-ret');
        $maxVolRatio = (float) $this->option('max-vol-ratio');
        $minAnchorNetNorm = (float) $this->option('min-anchor-net-norm');
        $minNetNorm = (float) $this->option('min-net-norm');
        $minPbas = (int) $this->option('min-pbas');
        $limit = max(1, (int) $this->option('limit'));

        // The CAST keeps SQLite's text-affinity for date filtering from forcing
        // the aggregate into TEXT, which would silently drop the row when
        // compared to a bound REAL parameter. The target type is not portable:
        // REAL is a syntax error in MySQL/MariaDB ("ERROR 1064 ... near
        // 'REAL)'"), so pick the spelling each engine accepts.
        $anchorAvgCast = match (DB::connection()->getDriverName()) {
            'sqlite' => 'CAST(AVG(avg_net_norm) AS REAL)',
            'pgsql' => 'CAST(AVG(avg_net_norm) AS DOUBLE PRECISION)',
            default => 'CAST(AVG(avg_net_norm) AS DOUBLE)',
        };

        $rows = DB::table('features_daily as fd')
            ->joinSub(
                DB::table('features_daily')
                    ->select('symbol')
                    ->selectRaw("{$anchorAvgCast} as anchor_avg_net_norm")
                    ->whereDate('date', '>=', $anchorDate->toDateString())
                    ->whereDate('date', '<=', $scanDate->toDateString())
                    ->groupBy('symbol'),
                'anchor',
                fn ($join) => $join->on('anchor.symbol', '=', 'fd.symbol')
            )
            ->where('fd.date', $scanDate->toDateString())
            ->where('fd.has_broker', 1)
            ->where('fd.ret_1', '<=', $maxRet)
            ->where('fd.vol_ratio_20', '<=', $maxVolRatio)
            ->where('anchor.anchor_avg_net_norm', '>=', $minAnchorNetNorm)
            ->where(function ($query) use ($minPbas, $minNetNorm): void {
                $query->where('fd.absorption_flag', 1)
                    ->orWhere('fd.pbas', '>=', $minPbas)
                    ->orWhere('fd.avg_net_norm', '>=', $minNetNorm);
            })
            ->when($symbols !== [], fn ($query) => $query->whereIn('fd.symbol', $symbols))
            ->orderByDesc('fd.pbas')
            ->orderByDesc('anchor.anchor_avg_net_norm')
            ->orderBy('fd.ret_1')
            ->limit($limit)
            ->get([
                'fd.symbol',
                'fd.date',
                'fd.ret_1',
                'fd.vol_ratio_20',
                'fd.avg_net_norm',
                'fd.pbas',
                'fd.absorption_flag',
                'anchor.anchor_avg_net_norm',
            ]);

        $this->info(sprintf(
            'Scan date: %s | Anchor window: %s → %s',
            $scanDate->toDateString(),
            $anchorDate->toDateString(),
            $scanDate->toDateString()
        ));

        if ($rows->isEmpty()) {
            $this->warn('No symbols matched the accumulation anomaly filters.');

            return self::SUCCESS;
        }

        $tableRows = $rows->map(static fn ($row): array => [
            $row->symbol,
            $row->date,
            number_format((float) $row->ret_1 * 100, 2).'%',
            number_format((float) $row->vol_ratio_20, 2),
            number_format((float) $row->avg_net_norm, 3),
            number_format((float) $row->anchor_avg_net_norm, 3),
            (int) $row->pbas,
            (int) $row->absorption_flag,
        ])->all();

        $this->table(
            ['Symbol', 'Date', 'Ret 1D', 'VolRatio20', 'NetNorm', 'AnchorNetNorm', 'PBAS', 'Absorb'],
            $tableRows
        );

        $this->newLine();
        $this->line('Tip: tighten filters as confirmation arrives (e.g. raise --min-pbas or --min-anchor-net-norm).');

        return self::SUCCESS;
    }

    private function resolveScanDate(string $scanDateInput): Carbon|false|null
    {
        if ($scanDateInput !== '') {
            try {
                return Carbon::parse($scanDateInput)->startOfDay();
            } catch (\Throwable) {
                return null;
            }
        }

        $latest = DB::table('features_daily')->max('date');
        if (! $latest) {
            return false;
        }

        return Carbon::parse((string) $latest)->startOfDay();
    }

    private function resolveAnchorDate(Carbon $scanDate, string $anchorDateInput): ?Carbon
    {
        if ($anchorDateInput === '') {
            return $scanDate->copy()->subDays(60);
        }

        try {
            $anchorDate = Carbon::parse($anchorDateInput)->startOfDay();
        } catch (\Throwable) {
            return null;
        }

        if ($anchorDate->greaterThan($scanDate)) {
            return null;
        }

        return $anchorDate;
    }

    /**
     * @return array<int, string>
     */
    private function normalizeSymbols(mixed $symbolsOption): array
    {
        if (! is_array($symbolsOption)) {
            return [];
        }

        $symbols = [];
        foreach ($symbolsOption as $value) {
            if (! is_string($value)) {
                continue;
            }

            foreach (explode(',', $value) as $candidate) {
                $symbol = strtoupper(trim($candidate));
                if ($symbol === '') {
                    continue;
                }

                $symbols[$symbol] = $symbol;
            }
        }

        return array_values($symbols);
    }
}
