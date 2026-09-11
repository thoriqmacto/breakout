<?php

namespace App\Console\Commands\Automation;

use App\Services\Automation\RunMetadata;
use App\Services\Strategies\StrategyAlertEvaluator;
use Illuminate\Console\Command;

/**
 * Tell the watcher when a watched strategy fires.
 *
 * Scheduled after the analysis refresh, so it reads the bars the evening
 * collection just wrote rather than yesterday's. A signal found here is for
 * the next session: the bar it fired on has already closed.
 */
class StrategyAlertsCommand extends Command
{
    protected $signature = 'automation:strategy-alerts';

    protected $description = 'Evaluate strategy alert subscriptions against the newest bar.';

    public function handle(StrategyAlertEvaluator $evaluator, RunMetadata $metadata): int
    {
        $result = $evaluator->evaluate();

        $metadata->merge([
            'job' => 'strategy_alerts',
            'evaluated' => $result['evaluated'],
            'triggered' => $result['triggered'],
            'skipped' => $result['skipped'],
            'failed' => $result['failed'],
            'signals' => $result['signals'],
        ]);

        $this->info(sprintf(
            '%d subscription(s) evaluated, %d fired.',
            $result['evaluated'],
            $result['triggered'],
        ));

        foreach ($result['signals'] as $signal) {
            $this->line(sprintf(
                '  %s %s on %s (%s)',
                $signal['symbol'],
                strtoupper((string) $signal['signal']),
                $signal['bar_date'],
                $signal['strategy'],
            ));
        }

        if ($result['skipped'] > 0) {
            $this->line(sprintf('  %d skipped for want of price history.', $result['skipped']));
        }

        // A subscription naming a strategy that no longer exists is a failure
        // to report, not a strategy that never fires.
        if ($result['failed'] > 0) {
            $this->warn(sprintf(
                '%d subscription(s) name a strategy that is no longer in the catalogue.',
                $result['failed'],
            ));

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
