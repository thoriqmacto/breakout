<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * The selector report has to be readable, and honest when there is nothing.
 *
 * The probe itself is proven against a real browser by resources/browser's
 * smoke test, which also drives a login with the selectors it proposes. What
 * is left to check here is the Laravel side: that a report reaches the
 * operator as .env lines they can paste, and that an empty page is called out
 * rather than dressed up as three blank suggestions.
 */
class BrowserFormCommandTest extends TestCase
{
    private function stubNodePrinting(string $stdout): string
    {
        $path = storage_path('framework/testing/fake-node');

        @mkdir(dirname($path), 0775, true);

        file_put_contents($path, "#!/bin/sh\ncat <<'EOF'\n".$stdout."\nEOF\n");
        chmod($path, 0755);

        return $path;
    }

    /**
     * @param  array<string, mixed>  $report
     */
    private function configureWithReport(array $report): void
    {
        config([
            'browser_auth.enabled' => true,
            'browser_auth.login_url' => 'https://portal.example.test/login',
            'browser_auth.node_binary' => $this->stubNodePrinting(
                (string) json_encode($report),
            ),
        ]);
    }

    protected function tearDown(): void
    {
        @unlink(storage_path('framework/testing/fake-node'));

        parent::tearDown();
    }

    public function test_it_prints_env_lines_that_can_be_pasted(): void
    {
        $this->configureWithReport([
            'url' => 'https://sso.example.test/login',
            'title' => 'Sign in',
            'fields' => [
                ['tag' => 'input', 'type' => 'text', 'name' => 'username', 'visible' => true],
                ['tag' => 'input', 'type' => 'password', 'name' => 'password', 'visible' => true],
            ],
            'controls' => [
                ['tag' => 'button', 'type' => 'submit', 'text' => 'Masuk', 'visible' => true],
            ],
            'suggestion' => [
                'username' => 'input[name="username"]',
                'password' => 'input[name="password"]',
                'submit' => 'button[type="submit"]',
            ],
        ]);

        $this->assertSame(0, Artisan::call('browser:form'));

        $output = Artisan::output();

        // Quoted, because a .env value is only literal inside quotes. The
        // first run of this command proposed `#username`, which is a comment
        // to dotenv: the variable arrived empty and the login blamed the
        // portal for markup that had not changed.
        $this->assertStringContainsString(
            'BROWSER_AUTH_USERNAME_SELECTOR=\'input[name="username"]\'',
            $output,
        );
        $this->assertStringContainsString(
            'BROWSER_AUTH_PASSWORD_SELECTOR=\'input[name="password"]\'',
            $output,
        );
        $this->assertStringContainsString(
            'BROWSER_AUTH_SUBMIT_SELECTOR=\'button[type="submit"]\'',
            $output,
        );

        // Where it ended up, not where it was pointed: a login URL that
        // redirects to an SSO host is a different page with different markup,
        // and nothing in the .env would show that.
        $this->assertStringContainsString('https://sso.example.test/login', $output);
    }

    /**
     * A page with no form is a finding, not three blank lines.
     */
    public function test_a_page_with_no_controls_says_so(): void
    {
        $this->configureWithReport([
            'url' => 'https://portal.example.test/login',
            'title' => 'Sign in',
            'fields' => [],
            'controls' => [],
            'suggestion' => ['username' => null, 'password' => null, 'submit' => null],
        ]);

        Artisan::call('browser:form');

        $this->assertStringContainsString('no fields or buttons', Artisan::output());
    }

    /**
     * Half a form is still worth reporting, with the gap named.
     */
    public function test_a_missing_suggestion_is_called_out_rather_than_left_blank(): void
    {
        $this->configureWithReport([
            'url' => 'https://portal.example.test/login',
            'title' => 'Sign in',
            'fields' => [
                ['tag' => 'input', 'type' => 'text', 'name' => 'username', 'visible' => true],
            ],
            'controls' => [],
            'suggestion' => [
                'username' => 'input[name="username"]',
                'password' => null,
                'submit' => null,
            ],
        ]);

        Artisan::call('browser:form');

        $this->assertStringContainsString('nothing on the page matched', Artisan::output());
    }

    public function test_it_refuses_when_headless_login_is_switched_off(): void
    {
        config(['browser_auth.enabled' => false]);

        $this->assertSame(1, Artisan::call('browser:form'));
        $this->assertStringContainsString('BROWSER_AUTH_ENABLED', Artisan::output());
    }
}
