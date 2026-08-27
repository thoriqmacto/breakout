<?php

namespace App\Services\Automation;

use App\Services\Stockbit\StockbitTokenResolver;
use App\Services\StockbitExodusClient;
use Illuminate\Support\Carbon;

/**
 * Describes the stored Stockbit bearer without ever revealing it.
 *
 * The existing resolver and encrypted store stay the source of truth; this
 * only asks them what state the token is in, and translates that into
 * something a scheduler can gate on and a dashboard can display. Every value
 * it returns is safe to serialise: a source name, a four-character
 * fingerprint, an expiry and a remaining duration. The bearer itself never
 * leaves this class.
 */
class StockbitTokenHealth
{
    public const HEALTHY = 'healthy';

    public const EXPIRING_SOON = 'expiring_soon';

    public const EXPIRED = 'expired';

    public const MISSING = 'missing';

    /** Present and not expired, but with no parseable `exp` claim. */
    public const UNKNOWN_EXPIRY = 'expiry_unknown';

    public function __construct(private readonly StockbitTokenResolver $resolver) {}

    /**
     * @return array{
     *     status: string, configured: bool, source: string, fingerprint: ?string,
     *     expires_at: ?string, expires_in_seconds: ?int, expires_in_human: ?string,
     *     warn_after_minutes: int, min_ttl_minutes: int, message: string,
     *     can_start_bulk_job: bool
     * }
     */
    /**
     * @param  int|null  $warnMinutesOverride  Per-call "expiring soon" threshold.
     *                                         Passed rather than written into config, so a
     *                                         one-off override cannot leak into the other
     *                                         tasks the same dispatcher process goes on to run.
     */
    public function status(?int $warnMinutesOverride = null): array
    {
        $warnMinutes = $warnMinutesOverride !== null && $warnMinutesOverride > 0
            ? $warnMinutesOverride
            : max(1, (int) config('automation.stockbit.warn_ttl_minutes', 720));
        $minTtlMinutes = max(0, (int) config('automation.stockbit.min_ttl_minutes', 90));

        $resolved = $this->resolver->resolveWithSource();
        $token = $resolved['token'] ?? null;
        $source = (string) ($resolved['source'] ?? StockbitTokenResolver::SOURCE_NONE);

        $base = [
            'source' => $source,
            'warn_after_minutes' => $warnMinutes,
            'min_ttl_minutes' => $minTtlMinutes,
        ];

        if (! is_string($token) || $token === '') {
            return $base + [
                'status' => self::MISSING,
                'configured' => false,
                'fingerprint' => null,
                'expires_at' => null,
                'expires_in_seconds' => null,
                'expires_in_human' => null,
                'message' => 'No Stockbit token is stored. Paste a fresh bearer token to resume scheduled scraping.',
                'can_start_bulk_job' => false,
            ];
        }

        $fingerprint = $this->fingerprint($token);
        $expiresAt = StockbitExodusClient::jwtExpiresAt($token);

        if ($expiresAt === null) {
            // Present and usable, but nothing can be promised about how long
            // for. Bulk jobs are allowed -- refusing every run because a token
            // lacks an `exp` claim would be worse than the risk -- and the
            // state is reported honestly instead.
            return $base + [
                'status' => self::UNKNOWN_EXPIRY,
                'configured' => true,
                'fingerprint' => $fingerprint,
                'expires_at' => null,
                'expires_in_seconds' => null,
                'expires_in_human' => null,
                'message' => 'A token is stored but carries no readable expiry, so its remaining lifetime is unknown.',
                'can_start_bulk_job' => true,
            ];
        }

        $expiry = Carbon::instance($expiresAt);
        $remaining = $expiry->getTimestamp() - Carbon::now()->getTimestamp();

        if ($remaining <= 0) {
            return $base + [
                'status' => self::EXPIRED,
                'configured' => true,
                'fingerprint' => $fingerprint,
                'expires_at' => $expiry->toIso8601String(),
                'expires_in_seconds' => 0,
                'expires_in_human' => null,
                'message' => 'The stored Stockbit token expired on '.$expiry->toDayDateTimeString().'. Renew it to resume scheduled scraping.',
                'can_start_bulk_job' => false,
            ];
        }

        $status = $remaining <= $warnMinutes * 60 ? self::EXPIRING_SOON : self::HEALTHY;

        // A token with twenty minutes left is not usable for an hour-long bulk
        // scrape. Starting one anyway produces a half-imported day and an
        // authorisation failure buried in the middle of the output.
        $canStartBulk = $remaining >= $minTtlMinutes * 60;

        return $base + [
            'status' => $status,
            'configured' => true,
            'fingerprint' => $fingerprint,
            'expires_at' => $expiry->toIso8601String(),
            'expires_in_seconds' => $remaining,
            'expires_in_human' => $this->humanize($remaining),
            'message' => $status === self::HEALTHY
                ? 'The Stockbit token is healthy for another '.$this->humanize($remaining).'.'
                : 'The Stockbit token expires in '.$this->humanize($remaining).'. Renew it before the next bulk scrape.',
            'can_start_bulk_job' => $canStartBulk,
        ];
    }

    /**
     * The preflight a bulk Stockbit job must pass before it spends an hour on
     * the API.
     *
     * @return array{ok: bool, status: array<string, mixed>, reason: ?string, message: ?string}
     */
    public function preflight(): array
    {
        $status = $this->status();

        if (! $status['configured']) {
            return [
                'ok' => false,
                'status' => $status,
                'reason' => 'token_missing',
                'message' => 'No Stockbit bearer token is stored. Renew it from the Automation page or run "php artisan stockbit:token:set".',
            ];
        }

        if ($status['status'] === self::EXPIRED) {
            return [
                'ok' => false,
                'status' => $status,
                'reason' => 'token_expired',
                'message' => 'The stored Stockbit bearer token has expired. Renew it from the Automation page or run "php artisan stockbit:token:set".',
            ];
        }

        if (! $status['can_start_bulk_job']) {
            return [
                'ok' => false,
                'status' => $status,
                'reason' => 'token_ttl_too_short',
                'message' => sprintf(
                    'The Stockbit token expires in %s, which is under the %d-minute minimum for starting a bulk job. Renew it and the next run will proceed.',
                    $status['expires_in_human'] ?? 'less than a minute',
                    (int) $status['min_ttl_minutes'],
                ),
            ];
        }

        return ['ok' => true, 'status' => $status, 'reason' => null, 'message' => null];
    }

    /**
     * Whether a token in this state warrants a dashboard reminder.
     */
    public function needsAttention(array $status): bool
    {
        return in_array($status['status'], [self::MISSING, self::EXPIRED, self::EXPIRING_SOON], true);
    }

    /**
     * The last four characters, which is enough to tell two tokens apart and
     * useless to anyone who does not already have the token.
     */
    private function fingerprint(string $token): string
    {
        return '****'.substr($token, -4);
    }

    private function humanize(int $seconds): string
    {
        if ($seconds <= 0) {
            return '0s';
        }

        $days = intdiv($seconds, 86400);
        $hours = intdiv($seconds % 86400, 3600);
        $minutes = intdiv($seconds % 3600, 60);

        if ($days > 0) {
            return sprintf('%dd %dh', $days, $hours);
        }

        if ($hours > 0) {
            return sprintf('%dh %02dm', $hours, $minutes);
        }

        return sprintf('%dm', max(1, $minutes));
    }
}
