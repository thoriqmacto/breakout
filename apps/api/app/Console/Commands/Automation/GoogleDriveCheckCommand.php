<?php

namespace App\Console\Commands\Automation;

use App\Models\AutomationAlert;
use App\Services\Automation\AutomationAlerts;
use App\Services\Automation\RunMetadata;
use App\Services\GoogleDriveGrantLog;
use App\Services\GoogleDriveHealth;
use App\Services\GoogleDriveOAuthClassifier as Code;
use Illuminate\Console\Command;

/**
 * Ask Google whether the Drive grant still works, before the evening needs it.
 *
 * A Google refresh token is not a JWT. It carries no readable expiry, so
 * unlike the Stockbit bearer there is nothing to inspect and no clock to
 * compare against -- the only way to know whether it still works is to spend
 * one and see. That makes a scheduled probe the whole mechanism rather than a
 * convenience: the alternative is discovering it mid-scrape, which is exactly
 * how a nightly run came to die on
 *
 *     Google Drive OAuth failed: invalid_grant (Token has been expired or revoked.)
 *
 * after fetching bars for the first ticker, with 0 of 55 succeeded.
 *
 * Scheduled at 17:45 WIB: after the trading calendar refresh at 17:30 and
 * before the 18:00 collectors, so a dead grant is a reminder on the dashboard
 * while there is still time to re-authorise, rather than a failed run and a
 * day of missing data.
 *
 * The probe resolves the disk -- which performs the OAuth exchange -- and
 * reads. It deliberately does not write: this runs unattended every day, and
 * the question is whether the grant is alive, not whether a byte can be
 * round-tripped. `gdrive:check` remains the write/read/delete version for
 * someone standing at a terminal.
 */
class GoogleDriveCheckCommand extends Command
{
    protected $signature = 'automation:gdrive-check
        {--disk= : Check this disk instead of the configured mirror}';

    protected $description = 'Verify the Google Drive grant still works and raise a dashboard reminder when it does not.';

    private const ALERT_KEY = 'authorisation-required';

    /**
     * Kept apart from the failure alert on purpose: "it stopped working" and
     * "it will stop working on Friday" need different responses, and one
     * resolving must not clear the other.
     */
    private const AGE_ALERT_KEY = 'expiring-soon';

    public function __construct(private readonly GoogleDriveGrantLog $grants)
    {
        parent::__construct();
    }

    public function handle(
        GoogleDriveHealth $health,
        AutomationAlerts $alerts,
        RunMetadata $metadata,
    ): int {
        $disk = $this->diskName();
        $status = $health->check($disk);
        $code = (string) ($status['code'] ?? Code::UNKNOWN_ERROR);

        // Never Google's raw response: it describes a request carrying the
        // client secret and the refresh token. The health check has already
        // reduced it to a code and a fixed message chosen by the classifier.
        $metadata->merge([
            'job' => 'gdrive_check',
            'disk' => $disk,
            'drive_status' => $status['status'],
            'drive_code' => $code,
            'drive_configured' => $status['configured'],
            'drive_connected' => $status['connected'],
            'drive_can_read' => $status['can_read'],
        ]);

        if ($code === Code::HEALTHY) {
            $cleared = $alerts->resolve(AutomationAlert::TYPE_GOOGLE_DRIVE, self::ALERT_KEY);

            $metadata->set('alert', $cleared ? 'resolved' : 'none');

            $this->info($status['message']);

            // A working grant is not necessarily a grant that will still be
            // working on Friday. On a consent screen still in Testing, Google
            // expires refresh tokens seven days after issue, so "healthy
            // today" and "healthy for the rest of the week" are different
            // claims and only the first one has been tested.
            return $this->reportAge($alerts, $metadata);
        }

        $alerts->raise(
            AutomationAlert::TYPE_GOOGLE_DRIVE,
            self::ALERT_KEY,
            $this->severity($code),
            $this->title($code),
            $status['message'],
            ['code' => $code, 'disk' => $disk, 'configured' => $status['configured']],
        );

        $metadata->merge(['alert' => 'raised', 'alert_severity' => $this->severity($code)]);

        $this->warn($status['message']);

        foreach ((array) ($status['guidance'] ?? []) as $line) {
            $this->line('  '.$line);
        }

        // A dead grant is a failure of this check, not of the day: the
        // collectors have not run yet, and a non-zero exit is what marks the
        // run red on the dashboard so it is noticed before they do.
        return self::FAILURE;
    }

    /**
     * Warn while the grant still works, if it is living on a timer.
     *
     * Only when an operator has said there is one. A refresh token has no
     * expiry to read, so the lifetime comes from configuration -- it is the
     * consent screen's mode expressed as a number of days -- and with none
     * set this does nothing at all rather than inventing a deadline for a
     * published app and warning weekly about nothing.
     *
     * The age is a lower bound: it counts from the first time a probe saw
     * this token working, which may be after it was issued. That errs early,
     * which is the safe direction.
     */
    private function reportAge(AutomationAlerts $alerts, RunMetadata $metadata): int
    {
        $lifetime = config('google_drive.grant_lifetime_days');
        $refreshToken = (string) config('filesystems.disks.gdrive.refreshToken', '');

        if ($refreshToken === '') {
            return self::SUCCESS;
        }

        $firstSeen = $this->grants->observe($refreshToken);
        $age = (int) $firstSeen->diffInDays(now());

        $metadata->merge([
            'grant_fingerprint' => $this->grants->fingerprint($refreshToken),
            'grant_first_seen_at' => $firstSeen->toIso8601String(),
            'grant_age_days' => $age,
            'grant_lifetime_days' => $lifetime,
        ]);

        if (! is_int($lifetime) || $lifetime <= 0) {
            $alerts->resolve(AutomationAlert::TYPE_GOOGLE_DRIVE, self::AGE_ALERT_KEY);

            return self::SUCCESS;
        }

        $remaining = $lifetime - $age;
        $warnBefore = max(1, (int) config('google_drive.grant_warn_before_days', 2));

        $this->line(sprintf(
            '  Grant seen working for %d of its %d day(s); %d remaining.',
            $age,
            $lifetime,
            max(0, $remaining),
        ));

        if ($remaining > $warnBefore) {
            $alerts->resolve(AutomationAlert::TYPE_GOOGLE_DRIVE, self::AGE_ALERT_KEY);

            return self::SUCCESS;
        }

        $alerts->raise(
            AutomationAlert::TYPE_GOOGLE_DRIVE,
            self::AGE_ALERT_KEY,
            $remaining <= 0 ? AutomationAlert::SEVERITY_CRITICAL : AutomationAlert::SEVERITY_WARNING,
            'Google Drive authorisation is about to expire',
            sprintf(
                'The Drive grant has been working for %d of the %d days it is configured to last, so it expires in '
                .'about %d. Re-authorise before then, or publish the OAuth consent screen: Google expires refresh '
                .'tokens after 7 days only while the app is in Testing.',
                $age,
                $lifetime,
                max(0, $remaining),
            ),
            ['age_days' => $age, 'lifetime_days' => $lifetime, 'remaining_days' => max(0, $remaining)],
        );

        $metadata->set('alert', 'raised');

        // Still a success: the grant works, and the collectors that follow
        // will not be blocked by a warning about next week.
        return self::SUCCESS;
    }

    /**
     * How loudly to say it, by what the operator would have to do.
     *
     * A revoked grant and a disabled API both need a person; an unreachable
     * host usually needs nothing but the next attempt, and shouting about a
     * bad minute on the network trains the reader to ignore this row.
     */
    private function severity(string $code): string
    {
        return match ($code) {
            Code::RENEW_REQUIRED, Code::INVALID_CLIENT, Code::SCOPE_ERROR, Code::API_DISABLED => AutomationAlert::SEVERITY_CRITICAL,
            default => AutomationAlert::SEVERITY_WARNING,
        };
    }

    private function title(string $code): string
    {
        return match ($code) {
            Code::RENEW_REQUIRED => 'Google Drive needs re-authorising',
            Code::NOT_CONFIGURED => 'Google Drive is not configured',
            Code::UNREACHABLE => 'Google Drive could not be reached',
            default => 'Google Drive is not usable',
        };
    }

    private function diskName(): string
    {
        $option = $this->option('disk');

        if (is_string($option) && trim($option) !== '') {
            return trim($option);
        }

        // 'gdrive' by default, matching BackupStatus and ReconciliationReadiness,
        // which both take it as a default parameter rather than from config.
        // One name for the Drive disk across the three things that probe it.
        return 'gdrive';
    }
}
