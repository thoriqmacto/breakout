<?php

namespace App\Services\Strategy;

use App\Services\Analysis\AssetTechnicalSnapshot;

/**
 * What makes two setups comparable.
 *
 * "How often did setups like this reach +5% before their stop" needs a
 * definition of "like this", and the definition is the whole statistic. Too
 * fine and every bucket is empty; too coarse and the number describes the
 * universe rather than the candidate. Four coarse axes, chosen because each
 * one plausibly changes the outcome distribution and none of them is a
 * continuous knob that invites fitting:
 *
 *     broker regime      is somebody accumulating
 *     breakout state     what level, if any, the close cleared
 *     volume band        whether the move carried participation
 *     initial risk band  how far the stop sits from the entry
 *
 * The key is stored on every outcome row rather than recomputed at query
 * time, so a change to these definitions is a change to the profile version
 * and cannot silently re-interpret rows written under the old ones.
 *
 * `coarseKey()` drops the volume and risk axes. The probability service falls
 * back to it when the exact bucket is too thin -- a wider, still-honest
 * comparison beats reporting nothing, provided the reader is told which one
 * was used.
 */
final class SetupBucket
{
    private const REGIME_CODES = [
        BrokerRegime::STRONG_ACCUMULATION => 'ACC2',
        BrokerRegime::ACCUMULATION => 'ACC1',
        BrokerRegime::NEUTRAL => 'NEU',
        BrokerRegime::DISTRIBUTION => 'DIS1',
        BrokerRegime::STRONG_DISTRIBUTION => 'DIS2',
    ];

    public function __construct(
        public readonly string $regimeCode,
        public readonly string $breakoutCode,
        public readonly string $volumeCode,
        public readonly string $riskCode,
    ) {}

    public static function for(
        string $regime,
        bool $breakout20,
        bool $breakout55,
        ?float $volRatio20,
        ?float $initialRiskPct,
        StrategyProfile $profile,
    ): self {
        return new self(
            regimeCode: self::REGIME_CODES[$regime] ?? 'NEU',
            breakoutCode: $breakout55 ? 'B55' : ($breakout20 ? 'B20' : 'NB'),
            volumeCode: self::volumeCode($volRatio20, $profile),
            riskCode: self::riskCode($initialRiskPct, $profile),
        );
    }

    /**
     * Convenience for the common case where the snapshot has the price side.
     */
    public static function fromSnapshot(
        AssetTechnicalSnapshot $snapshot,
        string $regime,
        ?float $initialRiskPct,
        StrategyProfile $profile,
    ): self {
        return self::for(
            regime: $regime,
            breakout20: $snapshot->isBreakout20(),
            breakout55: $snapshot->isBreakout55(),
            volRatio20: $snapshot->volRatio20,
            initialRiskPct: $initialRiskPct,
            profile: $profile,
        );
    }

    public function key(): string
    {
        return implode('|', [$this->regimeCode, $this->breakoutCode, $this->volumeCode, $this->riskCode]);
    }

    /**
     * The same setup with the two narrowest axes dropped.
     */
    public function coarseKey(): string
    {
        return implode('|', [$this->regimeCode, $this->breakoutCode]);
    }

    /**
     * Human wording for the bucket, so a reported probability can say what
     * population it came from instead of showing a code.
     */
    public function label(): string
    {
        $regime = array_search($this->regimeCode, self::REGIME_CODES, true);

        $breakout = match ($this->breakoutCode) {
            'B55' => '55-session breakout',
            'B20' => '20-session breakout',
            default => 'no breakout',
        };

        $volume = match ($this->volumeCode) {
            'VH' => 'high volume',
            'VM' => 'above-average volume',
            default => 'ordinary volume',
        };

        $risk = match ($this->riskCode) {
            'RL' => 'tight stop',
            'RM' => 'normal stop',
            'RH' => 'wide stop',
            default => 'unknown stop',
        };

        return sprintf(
            '%s, %s, %s, %s',
            is_string($regime) ? strtolower(str_replace('_', ' ', $regime)) : 'neutral',
            $breakout,
            $volume,
            $risk,
        );
    }

    public function coarseLabel(): string
    {
        $regime = array_search($this->regimeCode, self::REGIME_CODES, true);

        $breakout = match ($this->breakoutCode) {
            'B55' => '55-session breakout',
            'B20' => '20-session breakout',
            default => 'no breakout',
        };

        return sprintf(
            '%s, %s',
            is_string($regime) ? strtolower(str_replace('_', ' ', $regime)) : 'neutral',
            $breakout,
        );
    }

    private static function volumeCode(?float $volRatio20, StrategyProfile $profile): string
    {
        if ($volRatio20 === null) {
            return 'VL';
        }

        if ($volRatio20 >= $profile->preferredVolumeRatio) {
            return 'VH';
        }

        return $volRatio20 >= $profile->minVolumeRatio ? 'VM' : 'VL';
    }

    private static function riskCode(?float $initialRiskPct, StrategyProfile $profile): string
    {
        if ($initialRiskPct === null || $initialRiskPct <= 0) {
            return 'RU';
        }

        if ($initialRiskPct <= $profile->maxInitialRiskPct / 2) {
            return 'RL';
        }

        return $initialRiskPct <= $profile->maxInitialRiskPct ? 'RM' : 'RH';
    }
}
