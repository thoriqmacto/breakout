<?php

namespace Tests\Feature\Automation;

use App\Models\AutomationAlert;
use App\Services\GoogleDriveGrantLog;
use App\Services\GoogleDriveHealth;
use App\Services\GoogleDriveOAuthClassifier as Code;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Find out about a dead Drive grant before the collectors need it.
 *
 * A Google refresh token has no readable expiry. Unlike the Stockbit bearer
 * there is no `exp` to inspect and no clock to compare against, so the only
 * way to know whether it still works is to spend one -- which makes the
 * scheduled probe the entire mechanism rather than an early warning on top of
 * one.
 *
 * Without it the discovery happens mid-run: the 18:00 OHLCV sync died on
 * "invalid_grant (Token has been expired or revoked.)" after fetching bars for
 * the first ticker, 0 of 55 succeeded, and the day's data was simply lost.
 */
class GoogleDriveCheckCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
    }

    private function health(string $code, string $message = 'Drive says so.'): void
    {
        $this->mock(GoogleDriveHealth::class, function ($mock) use ($code, $message) {
            $mock->shouldReceive('check')->andReturn([
                'status' => $code === Code::HEALTHY ? 'healthy' : 'error',
                'configured' => $code !== Code::NOT_CONFIGURED,
                'connected' => $code === Code::HEALTHY,
                'refresh_token_status' => $code,
                'can_read' => $code === Code::HEALTHY,
                'code' => $code,
                'message' => $message,
                'guidance' => ['Re-authorise from the Backups page.'],
                'checked_at' => now()->toIso8601String(),
            ]);
        });
    }

    private function alert(): ?AutomationAlert
    {
        return AutomationAlert::where('type', AutomationAlert::TYPE_GOOGLE_DRIVE)
            ->where('key', 'authorisation-required')
            ->first();
    }

    /**
     * The case that cost a day of bars.
     */
    public function test_a_revoked_grant_raises_a_critical_reminder_and_fails(): void
    {
        $this->health(Code::RENEW_REQUIRED, 'The Google Drive authorisation has been revoked.');

        $this->artisan('automation:gdrive-check')->assertExitCode(1);

        $alert = $this->alert();

        $this->assertNotNull($alert, 'A revoked grant must leave a reminder on the dashboard.');
        $this->assertSame(AutomationAlert::SEVERITY_CRITICAL, $alert->severity);
        $this->assertStringContainsString('re-authorising', $alert->title);
    }

    /**
     * A bad minute on the network is not a revoked grant.
     *
     * Both stop the mirror, but only one needs a person, and shouting equally
     * about both is how a reader learns to skip the row.
     */
    public function test_an_unreachable_host_warns_rather_than_escalating(): void
    {
        $this->health(Code::UNREACHABLE, 'Google Drive could not be reached.');

        $this->artisan('automation:gdrive-check')->assertExitCode(1);

        $this->assertSame(AutomationAlert::SEVERITY_WARNING, $this->alert()?->severity);
    }

    public function test_a_healthy_grant_clears_the_reminder(): void
    {
        AutomationAlert::create([
            'type' => AutomationAlert::TYPE_GOOGLE_DRIVE,
            'key' => 'authorisation-required',
            'severity' => AutomationAlert::SEVERITY_CRITICAL,
            'title' => 'Google Drive needs re-authorising',
            'message' => 'From an earlier run.',
            'context' => [],
            'raised_at' => now(),
        ]);

        $this->health(Code::HEALTHY, 'Google Drive OAuth is healthy.');

        $this->artisan('automation:gdrive-check')->assertExitCode(0);

        $alert = $this->alert();

        // Resolved rather than deleted: the history of it having been raised
        // is worth keeping, and raising is keyed on (type, key) so the next
        // failure updates this row rather than accumulating another.
        $this->assertTrue($alert === null || $alert->resolved_at !== null);
    }

    /**
     * A working grant on a seven-day timer still needs notice.
     *
     * The consent screen is in Testing, where Google expires refresh tokens
     * seven days after issue. "Healthy today" and "healthy on Friday" are
     * different claims, and the probe only tests the first.
     */
    public function test_a_grant_close_to_its_configured_lifetime_warns_while_still_working(): void
    {
        $this->health(Code::HEALTHY, 'Google Drive OAuth is healthy.');

        config([
            'filesystems.disks.gdrive.refreshToken' => 'a-refresh-token',
            'google_drive.grant_lifetime_days' => 7,
            'google_drive.grant_warn_before_days' => 2,
        ]);

        // Seen working six days ago, so one day left of the seven.
        $this->travelTo(now()->subDays(6));
        app(GoogleDriveGrantLog::class)->observe('a-refresh-token');
        $this->travelBack();

        // Success, not failure: the grant works, and the collectors that
        // follow must not be blocked by a warning about next week.
        $this->artisan('automation:gdrive-check')->assertExitCode(0);

        $alert = AutomationAlert::where('type', AutomationAlert::TYPE_GOOGLE_DRIVE)
            ->where('key', 'expiring-soon')
            ->first();

        $this->assertNotNull($alert);
        $this->assertSame(AutomationAlert::SEVERITY_WARNING, $alert->severity);
        $this->assertStringContainsString('publish the OAuth consent screen', $alert->message);
    }

    /**
     * With no lifetime configured there is no deadline to warn about.
     *
     * A published app's refresh token does not expire on a timer, and
     * inventing one would produce a weekly warning about nothing.
     */
    public function test_no_configured_lifetime_raises_nothing(): void
    {
        $this->health(Code::HEALTHY, 'Google Drive OAuth is healthy.');

        config([
            'filesystems.disks.gdrive.refreshToken' => 'a-refresh-token',
            'google_drive.grant_lifetime_days' => null,
        ]);

        $this->travelTo(now()->subDays(300));
        app(GoogleDriveGrantLog::class)->observe('a-refresh-token');
        $this->travelBack();

        $this->artisan('automation:gdrive-check')->assertExitCode(0);

        $this->assertNull(
            AutomationAlert::where('key', 'expiring-soon')->first(),
            'A grant with no configured lifetime has no deadline to warn about.',
        );
    }

    /**
     * Re-authorising resets the clock, and the record never holds the token.
     */
    public function test_a_new_token_restarts_the_clock_and_is_stored_only_as_a_fingerprint(): void
    {
        $log = app(GoogleDriveGrantLog::class);

        $this->travelTo(now()->subDays(6));
        $log->observe('the-old-token');
        $this->travelBack();

        $this->assertSame(6, $log->ageInDays('the-old-token'));

        // A different token has no history: not zero days old, unknown.
        $this->assertNull($log->ageInDays('the-new-token'));

        $log->observe('the-new-token');
        $this->assertSame(0, $log->ageInDays('the-new-token'));

        $stored = Storage::disk('local')->get('google-drive/grant.json');

        $this->assertStringNotContainsString('the-new-token', $stored);
        $this->assertStringContainsString($log->fingerprint('the-new-token'), $stored);
    }

    /**
     * It must be scheduled before the collectors, not alongside them.
     */
    public function test_it_is_scheduled_ahead_of_the_evening_collectors(): void
    {
        $defaults = collect((array) config('automation.defaults'))->keyBy('slug');

        $check = $defaults['google-drive-check'] ?? null;

        $this->assertNotNull($check, 'The Drive check must ship as a default automation.');
        $this->assertSame('45 17 * * *', $check['cron_expression']);
        $this->assertLessThan(
            (int) $defaults['daily-ohlcv-sync']['priority'],
            (int) $check['priority'],
            'The Drive check must run before the OHLCV sync in a shared dispatcher pass.',
        );
    }
}
