<?php

namespace App\Console\Commands\Automation;

use App\Models\AutomationAlert;
use App\Services\Automation\AutomationAlerts;
use App\Services\Automation\RunMetadata;
use App\Services\Automation\StockbitTokenHealth;
use Illuminate\Console\Command;

/**
 * The daily 09:00 WIB Stockbit token check.
 *
 * Stockbit bearer tokens are short-lived and can only be renewed by a person
 * pasting a fresh one; there is no credential login to automate and inventing
 * one would mean storing a password to drive a browser. So the useful thing an
 * automation can do is notice early and say so, while there is still time to
 * act before the 16:00 scrape.
 *
 * The reminder is an `automation_alerts` row rather than an email or a push:
 * the project has no transport for either, and a dashboard warning that
 * survives a reload delivers this without adding a dependency. Raising is
 * keyed on (type, key), so running this every day updates one row instead of
 * accumulating one per day, and a healthy token clears it.
 */
class TokenCheckCommand extends Command
{
    protected $signature = 'automation:token-check
        {--warn-minutes= : Treat the token as expiring soon when fewer minutes remain}';

    protected $description = 'Check the stored Stockbit token and raise a dashboard reminder when it needs renewing.';

    private const ALERT_KEY = 'renewal-required';

    public function handle(
        StockbitTokenHealth $health,
        AutomationAlerts $alerts,
        RunMetadata $metadata,
    ): int {
        $option = $this->option('warn-minutes');
        $warnMinutes = is_string($option) && ctype_digit($option) && (int) $option > 0
            ? (int) $option
            : null;

        $status = $health->status($warnMinutes);

        // Only ever the safe fields. The bearer itself never leaves the
        // resolver, and nothing here is written to a log or a run record that
        // could reconstruct it.
        $metadata->merge([
            'job' => 'token_check',
            'token_status' => $status['status'],
            'token_source' => $status['source'],
            'token_fingerprint' => $status['fingerprint'],
            'token_expires_at' => $status['expires_at'],
            'token_expires_in_human' => $status['expires_in_human'],
        ]);

        if (! $health->needsAttention($status)) {
            $cleared = $alerts->resolve(AutomationAlert::TYPE_STOCKBIT_TOKEN, self::ALERT_KEY);

            $metadata->set('alert', $cleared ? 'resolved' : 'none');

            $this->info($status['message']);

            return self::SUCCESS;
        }

        $severity = $status['status'] === StockbitTokenHealth::EXPIRING_SOON
            ? AutomationAlert::SEVERITY_WARNING
            : AutomationAlert::SEVERITY_CRITICAL;

        $alerts->raise(
            AutomationAlert::TYPE_STOCKBIT_TOKEN,
            self::ALERT_KEY,
            $severity,
            'Stockbit token needs renewing',
            $status['message'],
            [
                'status' => $status['status'],
                'source' => $status['source'],
                'fingerprint' => $status['fingerprint'],
                'expires_at' => $status['expires_at'],
            ],
        );

        $metadata->merge(['alert' => 'raised', 'alert_severity' => $severity]);

        $this->warn($status['message']);
        $this->line('Renew it from /dashboard/automation, or run "php artisan stockbit:token:set".');

        // A raised reminder is the job working as designed, so the run is a
        // success. The attention state lives on the alert, not on the exit
        // code -- a red run every day for a token that is merely expiring soon
        // would train everyone to ignore the run history.
        return self::SUCCESS;
    }
}
