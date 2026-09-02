<?php

namespace App\Services\Strategy;

use InvalidArgumentException;

/**
 * The strategy's parameters, as one immutable object.
 *
 * Every tunable number the execution strategy uses arrives through here.
 * Services take a profile rather than reading config() at the point of use,
 * for three reasons that all turn out to be the same reason:
 *
 *   A simulation can vary a parameter without mutating global state, so the
 *   parameter grid is a loop over profiles rather than a sequence of config
 *   pokes that leak into whatever runs next.
 *
 *   Every stored outcome records the `version` of the profile that produced
 *   it, so a historical statistic can always be traced to the rules behind
 *   it. A result whose parameters are unknown is not a result.
 *
 *   The defaults live in configuration and nowhere else, so there is one
 *   place to audit and no magic constant to discover in a service six months
 *   from now.
 *
 * `withOverrides()` returns a new profile and derives a version suffix from
 * what changed, so a grid cell cannot silently write its numbers under the
 * base profile's name.
 */
final class StrategyProfile
{
    public const INTRADAY_CONSERVATIVE = 'conservative';

    public const INTRADAY_OPTIMISTIC = 'optimistic';

    /**
     * @param  array<int, int>  $brokerWindows
     * @param  array<int, int>  $brokerRegimeWindows
     * @param  array<int, int>  $outcomeHorizons
     * @param  array<string, mixed>  $costs
     * @param  array<string, float>  $scoreWeights
     */
    private function __construct(
        public readonly string $version,
        public readonly array $brokerWindows,
        public readonly array $brokerRegimeWindows,
        public readonly float $brokerFlowEpsilon,
        public readonly float $brokerStrongTop3Norm,
        public readonly float $trailActivationGainPct,
        public readonly float $trailingDistancePct,
        public readonly float $minimumLockedProfitPct,
        public readonly float $maxEntryExtensionAtr,
        public readonly float $armedDistanceAtr,
        public readonly float $maxInitialRiskPct,
        public readonly float $initialStopAtrMultiple,
        public readonly float $minVolumeRatio,
        public readonly float $preferredVolumeRatio,
        public readonly float $minClosePosition,
        public readonly int $minimumProbabilitySample,
        public readonly array $outcomeHorizons,
        public readonly int $maxHoldingSessions,
        public readonly int $maxBrokerLagDaysExecution,
        public readonly array $costs,
        public readonly string $intradayAssumption,
        public readonly array $scoreWeights,
        public readonly float $minTurnoverValue,
        public readonly int $minActiveBrokers,
        public readonly string $disclaimer,
    ) {}

    /**
     * The configured profile.
     *
     * @param  array<string, mixed>  $overrides
     */
    public static function fromConfig(array $overrides = []): self
    {
        /** @var array<string, mixed> $config */
        $config = config('strategy_profile', []);

        return self::fromArray(array_replace_recursive($config, $overrides));
    }

    /**
     * @param  array<string, mixed>  $values
     */
    public static function fromArray(array $values): self
    {
        $windows = self::intList($values['broker_windows'] ?? [3, 5, 10, 20]);

        if ($windows === []) {
            throw new InvalidArgumentException('A strategy profile needs at least one broker window.');
        }

        $regimeWindows = self::intList($values['broker_regime_windows'] ?? $windows);
        $assumption = (string) ($values['intraday_assumption'] ?? self::INTRADAY_CONSERVATIVE);

        if (! in_array($assumption, [self::INTRADAY_CONSERVATIVE, self::INTRADAY_OPTIMISTIC], true)) {
            throw new InvalidArgumentException(sprintf('Unknown intraday assumption "%s".', $assumption));
        }

        $costs = (array) ($values['costs'] ?? []);

        return new self(
            version: (string) ($values['version'] ?? 'execution-v2'),
            brokerWindows: $windows,
            brokerRegimeWindows: $regimeWindows === [] ? $windows : $regimeWindows,
            brokerFlowEpsilon: (float) ($values['broker_flow_epsilon'] ?? 0.005),
            brokerStrongTop3Norm: (float) ($values['broker_strong_top3_norm'] ?? 0.05),
            trailActivationGainPct: (float) ($values['trail_activation_gain_pct'] ?? 5.0),
            trailingDistancePct: (float) ($values['trailing_distance_pct'] ?? 2.0),
            minimumLockedProfitPct: (float) ($values['minimum_locked_profit_pct'] ?? 3.0),
            maxEntryExtensionAtr: (float) ($values['max_entry_extension_atr'] ?? 0.5),
            armedDistanceAtr: (float) ($values['armed_distance_atr'] ?? 1.0),
            maxInitialRiskPct: (float) ($values['max_initial_risk_pct'] ?? 4.0),
            initialStopAtrMultiple: (float) ($values['initial_stop_atr_multiple'] ?? 1.0),
            minVolumeRatio: (float) ($values['min_volume_ratio'] ?? 1.3),
            preferredVolumeRatio: (float) ($values['preferred_volume_ratio'] ?? 1.5),
            minClosePosition: (float) ($values['min_close_position'] ?? 0.70),
            minimumProbabilitySample: max(1, (int) ($values['minimum_probability_sample'] ?? 30)),
            outcomeHorizons: self::intList($values['outcome_horizons'] ?? [1, 3, 5, 10, 20]),
            maxHoldingSessions: max(1, (int) ($values['max_holding_sessions'] ?? 40)),
            maxBrokerLagDaysExecution: max(0, (int) ($values['max_broker_lag_days_execution'] ?? 1)),
            costs: [
                'buy_fee_pct' => (float) ($costs['buy_fee_pct'] ?? 0.0),
                'sell_fee_pct' => (float) ($costs['sell_fee_pct'] ?? 0.0),
                'slippage_pct' => (float) ($costs['slippage_pct'] ?? 0.0),
                'round_to_tick' => (bool) ($costs['round_to_tick'] ?? true),
            ],
            intradayAssumption: $assumption,
            scoreWeights: self::weights($values['score_weights'] ?? []),
            minTurnoverValue: (float) ($values['min_turnover_value'] ?? 5_000_000_000.0),
            minActiveBrokers: max(0, (int) ($values['min_active_brokers'] ?? 5)),
            disclaimer: (string) ($values['disclaimer'] ?? ''),
        );
    }

    /**
     * A copy with some parameters changed, under a version that says so.
     *
     * The derived suffix is what stops a grid cell writing its outcomes under
     * the base profile's name -- two different rule sets sharing one version
     * would make every stored statistic ambiguous.
     *
     * @param  array<string, mixed>  $overrides
     */
    public function withOverrides(array $overrides): self
    {
        if ($overrides === []) {
            return $this;
        }

        $base = $this->toArray();
        $merged = array_replace_recursive($base, $overrides);

        if (! array_key_exists('version', $overrides)) {
            $merged['version'] = $this->version.'+'.self::describeOverrides($base, $overrides);
        }

        return self::fromArray($merged);
    }

    public function conservativeIntraday(): bool
    {
        return $this->intradayAssumption === self::INTRADAY_CONSERVATIVE;
    }

    /**
     * Activation price for a given entry: where trailing switches on.
     */
    public function activationPrice(float $entryPrice): float
    {
        return $entryPrice * (1.0 + $this->trailActivationGainPct / 100.0);
    }

    /**
     * The floor the effective stop may never fall below once trailing is on.
     */
    public function profitFloorPrice(float $entryPrice): float
    {
        return $entryPrice * (1.0 + $this->minimumLockedProfitPct / 100.0);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'version' => $this->version,
            'broker_windows' => $this->brokerWindows,
            'broker_regime_windows' => $this->brokerRegimeWindows,
            'broker_flow_epsilon' => $this->brokerFlowEpsilon,
            'broker_strong_top3_norm' => $this->brokerStrongTop3Norm,
            'trail_activation_gain_pct' => $this->trailActivationGainPct,
            'trailing_distance_pct' => $this->trailingDistancePct,
            'minimum_locked_profit_pct' => $this->minimumLockedProfitPct,
            'max_entry_extension_atr' => $this->maxEntryExtensionAtr,
            'armed_distance_atr' => $this->armedDistanceAtr,
            'max_initial_risk_pct' => $this->maxInitialRiskPct,
            'initial_stop_atr_multiple' => $this->initialStopAtrMultiple,
            'min_volume_ratio' => $this->minVolumeRatio,
            'preferred_volume_ratio' => $this->preferredVolumeRatio,
            'min_close_position' => $this->minClosePosition,
            'minimum_probability_sample' => $this->minimumProbabilitySample,
            'outcome_horizons' => $this->outcomeHorizons,
            'max_holding_sessions' => $this->maxHoldingSessions,
            'max_broker_lag_days_execution' => $this->maxBrokerLagDaysExecution,
            'costs' => $this->costs,
            'intraday_assumption' => $this->intradayAssumption,
            'score_weights' => $this->scoreWeights,
            'min_turnover_value' => $this->minTurnoverValue,
            'min_active_brokers' => $this->minActiveBrokers,
            'disclaimer' => $this->disclaimer,
        ];
    }

    /**
     * A short, stable description of what a grid cell changed.
     *
     * @param  array<string, mixed>  $base
     * @param  array<string, mixed>  $overrides
     */
    private static function describeOverrides(array $base, array $overrides): string
    {
        $bits = [];

        foreach ($overrides as $key => $value) {
            if (is_array($value) || ($base[$key] ?? null) === $value) {
                continue;
            }

            $bits[] = self::abbreviate((string) $key).(is_float($value) ? rtrim(rtrim(sprintf('%.2f', $value), '0'), '.') : (string) $value);
        }

        sort($bits);

        return $bits === [] ? 'custom' : implode('-', $bits);
    }

    private static function abbreviate(string $key): string
    {
        $out = '';

        foreach (explode('_', $key) as $part) {
            $out .= substr($part, 0, 1);
        }

        return $out;
    }

    /**
     * Score weights, normalised so they always sum to 1.
     *
     * A profile whose weights sum to 1.3 would report scores above 100 and
     * make two profiles incomparable; normalising here means a caller can
     * express weights in whatever units are natural and still get a 0..100
     * score out.
     *
     * @return array<string, float>
     */
    private static function weights(mixed $value): array
    {
        $defaults = [
            'broker_persistence' => 0.15,
            'broker_strength' => 0.10,
            'broker_acceleration' => 0.05,
            'breakout_confirmation' => 0.20,
            'volume_confirmation' => 0.10,
            'trend_quality' => 0.10,
            'liquidity' => 0.05,
            'risk_quality' => 0.10,
            'historical_outcome' => 0.15,
        ];

        $merged = array_merge($defaults, array_filter(
            (array) $value,
            static fn ($item): bool => is_numeric($item),
        ));

        $merged = array_map(static fn ($item): float => max(0.0, (float) $item), $merged);
        $sum = array_sum($merged);

        if ($sum <= 0.0) {
            return $defaults;
        }

        return array_map(static fn (float $item): float => round($item / $sum, 6), $merged);
    }

    /**
     * @return array<int, int>
     */
    private static function intList(mixed $value): array
    {
        $out = [];

        foreach ((array) $value as $item) {
            $int = (int) $item;

            if ($int > 0 && ! in_array($int, $out, true)) {
                $out[] = $int;
            }
        }

        sort($out);

        return $out;
    }
}
