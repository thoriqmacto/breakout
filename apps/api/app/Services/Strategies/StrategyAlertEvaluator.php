<?php

namespace App\Services\Strategies;

use App\Models\AutomationAlert;
use App\Models\Price;
use App\Models\StrategyAlert;
use App\Services\AssetMetrics;
use App\Services\Automation\AutomationAlerts;
use Illuminate\Support\Carbon;

/**
 * Ask each watched strategy whether it fires on its asset's newest bar.
 *
 * The strategy classes are the ones the backtester walks history with, given
 * the same bars up to today rather than up to some point in the past. That is
 * the whole design: an alert that used its own copy of the entry rule would
 * eventually disagree with the backtest that justified subscribing to it, and
 * the disagreement would surface as a position taken on a signal the numbers
 * never supported.
 *
 * Firing is keyed on the bar's date, not on the clock. The evaluator runs
 * after the evening collection and may run more than once -- a manual re-run,
 * a catch-up minute -- and a strategy still in a buy state would otherwise
 * raise the same alert on every pass.
 */
class StrategyAlertEvaluator
{
    public function __construct(
        private readonly StrategyCatalogue $catalogue,
        private readonly AutomationAlerts $alerts,
    ) {}

    /**
     * @return array{evaluated:int, triggered:int, skipped:int, failed:int, signals:array<int, array<string, mixed>>}
     */
    public function evaluate(): array
    {
        $evaluated = 0;
        $triggered = 0;
        $skipped = 0;
        $failed = 0;
        $signals = [];

        $subscriptions = StrategyAlert::query()
            ->where('enabled', true)
            ->with('asset')
            ->orderBy('symbol')
            ->get();

        foreach ($subscriptions as $subscription) {
            $evaluated++;

            $asset = $subscription->asset;

            if ($asset === null) {
                $skipped++;

                continue;
            }

            $entry = $this->catalogue->find((string) $subscription->strategy);

            if ($entry === null) {
                // A subscription to a strategy that no longer exists is not a
                // silent no-op: left unreported it would look like a strategy
                // that simply never fires.
                $failed++;

                continue;
            }

            $bars = $this->bars($asset->id);

            if (count($bars) < 2) {
                $skipped++;

                continue;
            }

            $lastBarDate = $bars[count($bars) - 1]['date'];

            $strategy = $this->strategyFor((string) $entry['class'], $bars);
            $signal = $strategy->signal();

            $subscription->forceFill([
                'last_signal' => $signal,
                'last_evaluated_at' => Carbon::now(),
            ]);

            $wanted = (string) $subscription->signal;
            $fires = $signal === $wanted;

            // Same session, same event. Without this a re-run would raise the
            // alert again and the dashboard would show a second reminder for
            // something the reader has already seen.
            $alreadyFired = $subscription->last_triggered_on?->toDateString() === $lastBarDate;

            if ($fires && ! $alreadyFired) {
                $this->raise($subscription, $signal, $lastBarDate, $bars[count($bars) - 1]['close']);

                $subscription->forceFill(['last_triggered_on' => $lastBarDate]);
                $triggered++;

                $signals[] = [
                    'symbol' => $subscription->symbol,
                    'strategy' => $subscription->strategy,
                    'signal' => $signal,
                    'bar_date' => $lastBarDate,
                ];
            }

            $subscription->save();
        }

        return [
            'evaluated' => $evaluated,
            'triggered' => $triggered,
            'skipped' => $skipped,
            'failed' => $failed,
            'signals' => $signals,
        ];
    }

    private function raise(StrategyAlert $subscription, string $signal, string $barDate, float $close): void
    {
        $this->alerts->raise(
            AutomationAlert::TYPE_STRATEGY_SIGNAL,
            $subscription->alertKey(),
            // Never critical. A signal is something to decide about, not
            // something broken, and colouring it like a failure would train
            // the reader to treat real failures as routine.
            AutomationAlert::SEVERITY_INFO,
            sprintf('%s: %s signal from %s', $subscription->symbol, strtoupper($signal), $subscription->strategy),
            sprintf(
                '%s closed at %s on %s and %s now reads %s. This is the strategy firing on the last '
                .'bar, not a recommendation or a guaranteed entry -- the position, the size and the '
                .'stop are still yours to decide.',
                $subscription->symbol,
                number_format($close, 2),
                $barDate,
                $subscription->strategy,
                $signal,
            ),
            [
                'symbol' => $subscription->symbol,
                'strategy' => $subscription->strategy,
                'signal' => $signal,
                'bar_date' => $barDate,
                'close' => $close,
            ],
        );
    }

    /**
     * @param  array<int, array<string, mixed>>  $bars
     */
    private function strategyFor(string $class, array $bars): BaseStrategy
    {
        // Seeded with the first bar and then handed the whole series, matching
        // how the backtester constructs a strategy on its final bar.
        $strategy = new $class(new AssetMetrics([$bars[0]]));

        return $strategy->withMetrics(new AssetMetrics($bars));
    }

    /**
     * @return array<int, array{date:string, open:float, high:float, low:float, close:float}>
     */
    private function bars(int $assetId): array
    {
        return Price::query()
            ->where('asset_id', $assetId)
            ->orderBy('date')
            ->get(['date', 'open', 'high', 'low', 'close'])
            ->map(static function ($price): array {
                $date = $price->date instanceof \DateTimeInterface
                    ? $price->date->format('Y-m-d')
                    : (string) $price->date;

                return [
                    'date' => $date,
                    'open' => (float) $price->open,
                    'high' => (float) $price->high,
                    'low' => (float) $price->low,
                    'close' => (float) $price->close,
                ];
            })
            ->all();
    }
}
