<?php

namespace Tests\Feature\Automation;

use App\Models\AutomationAlert;
use App\Services\GoogleDriveHealth;
use App\Services\GoogleDriveOAuthClassifier as Code;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
