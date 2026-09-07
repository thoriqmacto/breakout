<?php

namespace App\Console\Commands\Automation;

use App\Models\AutomationAlert;
use App\Services\Automation\AutomationAlerts;
use App\Services\Automation\RunMetadata;
use App\Services\Automation\StockbitTokenHealth;
use App\Services\Stockbit\BrowserTokenExtractionException;
use App\Services\Stockbit\BrowserTokenExtractor;
use App\Services\Stockbit\StockbitTokenResolver;
use App\Support\StockbitCredentialStore;
use Illuminate\Console\Command;

/**
 * Renew the Stockbit token before it expires, without anyone at a keyboard.
 *
 * This is the automated form of what was previously a manual chore: sign in
 * with a real browser and keep the bearer the portal hands its own front end.
 * `automation:token-check` still runs and still raises the dashboard reminder
 * -- it is the fallback for every case this cannot handle, and the two are
 * complementary rather than redundant: a portal that asks for a second factor,
 * a changed password, or a machine with no stored credentials all end with a
 * reminder rather than silence.
 *
 * It renews only when the token is missing, expired, or inside the renewal
 * window. A browser launch costs a few hundred megabytes and tens of seconds,
 * so running one hourly against a healthy token would be waste; and every
 * unnecessary login is another chance for the portal to notice a robot.
 *
 * Credentials are never parameters of this command or of the scheduled task
 * that runs it. They come from the encrypted store, which is written only by
 * `stockbit:credentials`.
 */
class TokenRefreshCommand extends Command
{
    protected $signature = 'automation:token-refresh
        {--minutes= : Renew when fewer than this many minutes remain}
        {--force : Renew even when the current token is healthy}';

    protected $description = 'Renew the Stockbit token with a headless login before it expires.';

    private const ALERT_KEY = 'renewal-required';

    public function handle(
        StockbitTokenHealth $health,
        StockbitCredentialStore $credentials,
        BrowserTokenExtractor $extractor,
        StockbitTokenResolver $resolver,
        AutomationAlerts $alerts,
        RunMetadata $metadata,
    ): int {
        $window = $this->renewalWindow();
        $status = $health->status($window);
        $stored = $credentials->get();

        $metadata->merge([
            'job' => 'token_refresh',
            'token_status_before' => $status['status'],
            'renewal_window_minutes' => $window,
            'credentials_stored' => $stored !== null,
        ]);

        if (! $this->shouldRenew($status, $window)) {
            $this->info(sprintf(
                'Token is healthy for another %s; nothing to do.',
                $status['expires_in_human'] ?? 'unknown period',
            ));
            $metadata->merge(['outcome' => 'skipped_healthy']);

            return self::SUCCESS;
        }

        if (! $extractor->enabled()) {
            return $this->standDown(
                $alerts,
                $metadata,
                'not_configured',
                'Headless login is switched off, so the token cannot be renewed automatically. '
                .'Set BROWSER_AUTH_ENABLED and BROWSER_AUTH_LOGIN_URL, or renew by hand.',
            );
        }

        if ($stored === null) {
            return $this->standDown(
                $alerts,
                $metadata,
                'no_credentials',
                $credentials->exists()
                    ? 'Stored credentials could not be decrypted with this APP_KEY. Run '
                        .'`php artisan stockbit:credentials` to replace them.'
                    : 'No stored credentials, so the token cannot be renewed automatically. Run '
                        .'`php artisan stockbit:credentials`, or renew by hand.',
            );
        }

        try {
            $result = $extractor->extract($stored['username'], $stored['password']);
        } catch (BrowserTokenExtractionException $exception) {
            // The extractor's message is already redacted and already says
            // what to do about each failure kind, so it is carried through
            // rather than replaced with something vaguer.
            return $this->standDown(
                $alerts,
                $metadata,
                $exception->failureCode,
                'Automatic renewal failed: '.$exception->getMessage(),
            );
        }

        $resolver->persist($result['token']);

        $after = $health->status($window);

        // Only ever the safe fields, the same rule the token check follows:
        // nothing written here could reconstruct the bearer.
        $metadata->merge([
            'outcome' => 'renewed',
            'token_status_after' => $after['status'],
            'token_fingerprint' => $after['fingerprint'],
            'token_expires_at' => $after['expires_at'],
            'captured_from' => $result['source'],
            'elapsed_ms' => $result['elapsed_ms'],
        ]);

        $alerts->resolve(AutomationAlert::TYPE_STOCKBIT_TOKEN, self::ALERT_KEY);

        $this->info(sprintf(
            'Renewed the Stockbit token (%s, seen in %s, %.1fs). Valid for another %s.',
            $after['fingerprint'] ?? 'unknown fingerprint',
            $result['source'],
            $result['elapsed_ms'] / 1000,
            $after['expires_in_human'] ?? 'unknown period',
        ));

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $status
     */
    private function shouldRenew(array $status, int $window): bool
    {
        if ($this->option('force')) {
            return true;
        }

        return match ($status['status']) {
            StockbitTokenHealth::HEALTHY => false,
            // An expiry this cannot read is not a reason to skip: the token
            // may already be dead, and finding out during the 16:00 scrape is
            // the outcome this exists to prevent.
            default => true,
        };
    }

    private function renewalWindow(): int
    {
        $option = $this->option('minutes');

        if (is_string($option) && ctype_digit($option) && (int) $option > 0) {
            return (int) $option;
        }

        return max(1, (int) config('browser_auth.renew_before_minutes', 120));
    }

    /**
     * Fail loudly on the dashboard rather than quietly in a log.
     *
     * A renewal that silently does not happen looks exactly like one that did,
     * until a scrape fails hours later. The reminder is the same row the daily
     * token check raises, so the two never stack up.
     */
    private function standDown(
        AutomationAlerts $alerts,
        RunMetadata $metadata,
        string $reason,
        string $message,
    ): int {
        $metadata->merge(['outcome' => 'not_renewed', 'reason' => $reason]);

        $alerts->raise(
            AutomationAlert::TYPE_STOCKBIT_TOKEN,
            self::ALERT_KEY,
            AutomationAlert::SEVERITY_WARNING,
            'Stockbit token needs renewing',
            $message,
            ['reason' => $reason],
        );

        $this->error($message);

        return self::FAILURE;
    }
}
