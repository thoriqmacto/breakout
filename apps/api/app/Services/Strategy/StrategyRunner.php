<?php

namespace App\Services\Strategy;

use App\Models\Asset;
use App\Models\Strategy;
use App\Models\StrategyRun;
use App\Services\Strategy\Rules\FieldRegistry;
use App\Services\Strategy\Rules\RuleEvaluator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Evaluates a strategy's rule tree across every symbol with features for a
 * scan date, and persists the run plus its matches.
 */
class StrategyRunner
{
    public function __construct(private readonly RuleEvaluator $evaluator) {}

    /**
     * Resolve the newest date that has feature rows, which is what a run
     * defaults to when the caller does not name one.
     */
    public function latestScanDate(): ?string
    {
        $date = DB::table('features_daily')->max('date');

        return $date === null ? null : Carbon::parse($date)->toDateString();
    }

    /**
     * Execute the strategy and record the outcome against $run.
     *
     * Failures are caught and recorded on the run rather than thrown, so a bad
     * strategy marks itself failed instead of leaving a queued row behind and
     * burning through the worker's retry budget.
     */
    public function run(Strategy $strategy, StrategyRun $run): StrategyRun
    {
        $run->forceFill([
            'status' => StrategyRun::STATUS_RUNNING,
            'started_at' => now(),
        ])->save();

        try {
            [$evaluated, $matched] = $this->evaluateAll($strategy, $run);

            $run->forceFill([
                'status' => StrategyRun::STATUS_COMPLETED,
                'evaluated_count' => $evaluated,
                'matched_count' => $matched,
                'finished_at' => now(),
            ])->save();
        } catch (Throwable $e) {
            $run->forceFill([
                'status' => StrategyRun::STATUS_FAILED,
                'error' => mb_substr($e->getMessage(), 0, 2000),
                'finished_at' => now(),
            ])->save();
        }

        // Mirrored onto the strategy so a dashboard card renders from one row.
        $strategy->forceFill([
            'last_run_at' => $run->finished_at ?? now(),
            'last_run_status' => $run->status,
            'last_match_count' => $run->status === StrategyRun::STATUS_COMPLETED
                ? $run->matched_count
                : $strategy->last_match_count,
        ])->save();

        return $run->refresh();
    }

    /**
     * @return array{0: int, 1: int} evaluated and matched counts
     */
    private function evaluateAll(Strategy $strategy, StrategyRun $run): array
    {
        $scanDate = $run->scan_date instanceof Carbon
            ? $run->scan_date->toDateString()
            : (string) $run->scan_date;

        $metrics = $this->metricsBySymbol();
        $assetIds = Asset::query()->pluck('id', 'symbol');

        $tree = $strategy->rules ?? [];
        $evaluated = 0;
        $matched = 0;
        $pending = [];

        $featureColumns = array_merge(['symbol'], FieldRegistry::columnsFor('features'));

        DB::table('features_daily')
            ->select($featureColumns)
            ->whereDate('date', $scanDate)
            ->orderBy('symbol')
            ->chunk(200, function ($rows) use (
                $tree, $metrics, $assetIds, $run, &$evaluated, &$matched, &$pending
            ) {
                foreach ($rows as $featureRow) {
                    $evaluated++;

                    $symbol = (string) $featureRow->symbol;
                    $flat = $this->flatten($featureRow, $metrics[$symbol] ?? null);

                    $result = $this->evaluator->evaluate($tree, $flat);

                    if (! $result['matched']) {
                        continue;
                    }

                    $matched++;

                    $pending[] = [
                        'strategy_run_id' => $run->id,
                        'asset_id' => $assetIds[$symbol] ?? null,
                        'symbol' => $symbol,
                        // Only the fields the rules actually referenced, so a
                        // match row stays small regardless of table width.
                        'facts' => json_encode($this->usedFacts($result['explanation'], $flat)),
                        'explanation' => json_encode($result['explanation']),
                        'created_at' => now(),
                        'updated_at' => now(),
                    ];

                    if (count($pending) >= 200) {
                        DB::table('strategy_run_matches')->insert($pending);
                        $pending = [];
                    }
                }
            });

        if ($pending !== []) {
            DB::table('strategy_run_matches')->insert($pending);
        }

        return [$evaluated, $matched];
    }

    /**
     * @return array<string, object>
     */
    private function metricsBySymbol(): array
    {
        $columns = array_merge(['symbol'], FieldRegistry::columnsFor('metrics'));

        return DB::table('metrics')
            ->select($columns)
            ->get()
            ->keyBy('symbol')
            ->all();
    }

    /**
     * Merge a feature row and its metrics row into the namespaced shape the
     * evaluator expects.
     *
     * @return array<string, mixed>
     */
    private function flatten(object $featureRow, ?object $metricRow): array
    {
        $flat = [];

        foreach (FieldRegistry::all() as $key => $meta) {
            $source = $meta['source'] === 'features' ? $featureRow : $metricRow;

            $flat[$key] = $source === null
                ? null
                : ($source->{$meta['column']} ?? null);
        }

        return $flat;
    }

    /**
     * @param  array<int, array<string, mixed>>  $explanation
     * @param  array<string, mixed>  $flat
     * @return array<string, mixed>
     */
    private function usedFacts(array $explanation, array $flat): array
    {
        $facts = [];

        foreach ($explanation as $entry) {
            $field = (string) ($entry['field'] ?? '');

            if ($field !== '' && array_key_exists($field, $flat)) {
                $facts[$field] = $flat[$field];
            }
        }

        return $facts;
    }
}
