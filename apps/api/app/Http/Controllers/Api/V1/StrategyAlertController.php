<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Resources\ApiResponse;
use App\Models\Asset;
use App\Models\AutomationAlert;
use App\Models\StrategyAlert;
use App\Services\Automation\AutomationAlerts;
use App\Services\Strategies\StrategyCatalogue;
use Illuminate\Http\Request;

/**
 * Standing requests to be told when a strategy fires.
 *
 * Scoped to the caller throughout. Two people watching the same asset want
 * their own alerts, and one of them dismissing a reminder must not clear the
 * other's.
 */
class StrategyAlertController extends ApiController
{
    public function __construct(
        private readonly StrategyCatalogue $catalogue,
        private readonly AutomationAlerts $alerts,
    ) {}

    public function index(Request $request)
    {
        $data = $request->validate([
            'asset_id' => ['sometimes', 'integer'],
            'symbol' => ['sometimes', 'string', 'max:32'],
        ]);

        $query = StrategyAlert::query()
            ->where('user_id', $request->user()->id)
            ->orderBy('symbol')
            ->orderBy('strategy');

        if (isset($data['asset_id'])) {
            $query->where('asset_id', $data['asset_id']);
        }

        if (isset($data['symbol'])) {
            $query->where('symbol', strtoupper($data['symbol']));
        }

        return ApiResponse::success([
            'alerts' => $query->get()->map(fn (StrategyAlert $alert): array => $this->present($alert))->all(),
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'asset_id' => ['required', 'integer', 'exists:assets,id'],
            'strategy' => ['required', 'string', 'max:64'],
            'signal' => ['sometimes', 'in:buy,sell'],
        ]);

        $entry = $this->catalogue->find($data['strategy']);

        if ($entry === null) {
            return ApiResponse::error(
                sprintf('Unknown strategy "%s". Available: %s', $data['strategy'], implode(', ', $this->catalogue->keys())),
                422,
            );
        }

        // A strategy that emits no daily signal can never fire on a daily bar,
        // so subscribing to it would be a reminder that never arrives and no
        // way to tell that from a strategy that simply has not triggered.
        if (($entry['emits_daily_signal'] ?? true) === false) {
            return ApiResponse::error(
                sprintf('"%s" does not emit a daily signal, so it cannot raise an alert.', $entry['key']),
                422,
            );
        }

        $asset = Asset::findOrFail($data['asset_id']);

        $alert = StrategyAlert::query()->updateOrCreate(
            [
                'user_id' => $request->user()->id,
                'asset_id' => $asset->id,
                'strategy' => (string) $entry['key'],
                'signal' => $data['signal'] ?? StrategyAlert::SIGNAL_BUY,
            ],
            [
                'symbol' => $asset->symbol,
                'enabled' => true,
            ],
        );

        return ApiResponse::success(['alert' => $this->present($alert)], 'Alert saved.', 201);
    }

    public function update(Request $request, StrategyAlert $strategyAlert)
    {
        if ($strategyAlert->user_id !== $request->user()->id) {
            return ApiResponse::error('Alert not found.', 404);
        }

        $data = $request->validate([
            'enabled' => ['required', 'boolean'],
        ]);

        $strategyAlert->update(['enabled' => $data['enabled']]);

        // A disabled subscription must not leave a standing reminder behind:
        // the row on the dashboard would outlive the thing that raised it.
        if (! $data['enabled']) {
            $this->alerts->resolve(AutomationAlert::TYPE_STRATEGY_SIGNAL, $strategyAlert->alertKey());
        }

        return ApiResponse::success(['alert' => $this->present($strategyAlert->fresh())]);
    }

    public function destroy(Request $request, StrategyAlert $strategyAlert)
    {
        if ($strategyAlert->user_id !== $request->user()->id) {
            return ApiResponse::error('Alert not found.', 404);
        }

        $this->alerts->resolve(AutomationAlert::TYPE_STRATEGY_SIGNAL, $strategyAlert->alertKey());
        $strategyAlert->delete();

        return ApiResponse::success(['deleted' => true], 'Alert removed.');
    }

    /**
     * @return array<string, mixed>
     */
    private function present(StrategyAlert $alert): array
    {
        return [
            'id' => $alert->id,
            'asset_id' => $alert->asset_id,
            'symbol' => $alert->symbol,
            'strategy' => $alert->strategy,
            'signal' => $alert->signal,
            'enabled' => (bool) $alert->enabled,
            'last_signal' => $alert->last_signal,
            'last_triggered_on' => $alert->last_triggered_on?->toDateString(),
            'last_evaluated_at' => $alert->last_evaluated_at?->toIso8601String(),
        ];
    }
}
