<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * The command exists to name the fix, so a failure has to name the fix.
 *
 * `browser:check` was written because a 502 said nothing useful. It would be
 * the same mistake to answer the most common failure -- a browser installed
 * into another user's home -- with Playwright's own bordered banner, which is
 * cut off long before the instruction inside it and, run as the reader,
 * recreates the problem it is meant to solve.
 */
class BrowserAuthCheckCommandTest extends TestCase
{
    /**
     * A stub standing in for node, reporting what Playwright reports.
     *
     * The real probe cannot be used here: whether it fails this way depends on
     * what is installed on the machine running the tests, and CI installs no
     * browsers at all.
     */
    private function stubNodeReporting(string $stderr): string
    {
        $path = storage_path('framework/testing/fake-node');

        @mkdir(dirname($path), 0775, true);

        file_put_contents($path, "#!/bin/sh\ncat >&2 <<'EOF'\n".$stderr."\nEOF\nexit 1\n");
        chmod($path, 0755);

        return $path;
    }

    protected function tearDown(): void
    {
        @unlink(storage_path('framework/testing/fake-node'));

        parent::tearDown();
    }

    public function test_a_browser_in_another_users_home_is_named_along_with_the_fix(): void
    {
        $missing = '/var/www/.cache/ms-playwright/chromium_headless_shell-1243/chrome-headless-shell';

        config([
            'browser_auth.enabled' => true,
            'browser_auth.login_url' => 'https://portal.example.test/login',
            'browser_auth.node_binary' => $this->stubNodeReporting(
                "browserType.launch: Executable doesn't exist at ".$missing."\n"
                ."╔═══════════════════════════════════════╗\n"
                ."║ Looks like Playwright was just installed or updated. ║\n"
                ."║ Please run the following command to download new browsers: ║\n"
                .'╚═══════════════════════════════════════╝',
            ),
        ]);

        $status = Artisan::call('browser:check', ['--json' => true]);
        $output = Artisan::output();

        $this->assertSame(1, $status, 'A machine that cannot launch a browser must fail the check.');

        $checks = json_decode($output, true);

        $this->assertIsArray($checks);

        $detail = $checks['chromium']['detail'];

        $this->assertFalse($checks['chromium']['ok']);

        // The path is the diagnosis: it names the user the browser belongs to.
        $this->assertStringContainsString($missing, $detail);

        // And the fix, rather than Playwright's suggestion to reinstall as
        // whoever is reading -- which is how the browser landed in one user's
        // home to begin with.
        $this->assertStringContainsString('PLAYWRIGHT_BROWSERS_PATH', $detail);
        $this->assertStringContainsString('BROWSER_AUTH_BROWSERS_PATH', $detail);

        $this->assertStringNotContainsString(
            'Please run the following',
            $detail,
            'Playwright\'s banner is not an answer; it gets truncated before its own instruction.',
        );
    }

    /**
     * Anything unanticipated still has to come through, just bounded.
     */
    public function test_an_unrecognised_launch_failure_is_still_reported(): void
    {
        config([
            'browser_auth.enabled' => true,
            'browser_auth.login_url' => 'https://portal.example.test/login',
            'browser_auth.node_binary' => $this->stubNodeReporting(
                'browserType.launch: Target page, context or browser has been closed',
            ),
        ]);

        Artisan::call('browser:check', ['--json' => true]);

        $checks = json_decode(Artisan::output(), true);

        $this->assertStringContainsString(
            'browser has been closed',
            $checks['chromium']['detail'],
        );
    }
}
