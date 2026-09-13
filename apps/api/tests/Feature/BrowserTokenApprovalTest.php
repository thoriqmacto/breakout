<?php

namespace Tests\Feature;

use App\Services\Stockbit\BrowserTokenExtractionException;
use App\Services\Stockbit\BrowserTokenExtractor;
use Tests\TestCase;

/**
 * A login held for a device approval, from the child's report to the terminal.
 *
 * The failure this covers cost several evenings and a wrong diagnosis in the
 * README. A portal with a device-trust step refuses the login call while it
 * waits for a phone, and its approval poll refuses too, until the approval
 * lands. On the wire that is indistinguishable from a rejected password -- so
 * the run latched INVALID_CREDENTIALS, printed "no device-approval notification
 * is sent for a login that never got past this step", and told an operator who
 * was holding that very notification to go and check their password.
 *
 * Three things are asserted here, because each was separately wrong:
 *
 *   - the approval is reported as itself, with what to do about it;
 *   - the operator hears about it *while the run is still open*, which is the
 *     only time the information is worth anything;
 *   - nothing unattended waits for an approval, and the interactive command
 *     does.
 */
class BrowserTokenApprovalTest extends TestCase
{
    private function stubChild(string $stdoutJson, string $stderr = ''): string
    {
        $path = storage_path('framework/testing/fake-node');

        @mkdir(dirname($path), 0775, true);

        // The job is kept so a test can assert what PHP asked the child for --
        // the approval wait in particular, which must be zero unless somebody
        // is watching. Written where tearDown can remove it again.
        $script = "#!/bin/sh\ncat > ".escapeshellarg($this->jobPath())."\n";

        if ($stderr !== '') {
            $script .= "printf '%s\\n' ".escapeshellarg($stderr)." >&2\n";
        }

        $script .= "cat <<'EOF'\n".$stdoutJson."\nEOF\nexit 1\n";

        file_put_contents($path, $script);
        chmod($path, 0755);

        return $path;
    }

    private function jobPath(): string
    {
        return storage_path('framework/testing/fake-node-job.json');
    }

    private function configureWith(string $stdoutJson, string $stderr = ''): void
    {
        config([
            'browser_auth.enabled' => true,
            'browser_auth.login_url' => 'https://portal.example.test/login',
            'browser_auth.selectors.username' => 'input[id="username"]',
            'browser_auth.selectors.password' => 'input[name="password"]',
            'browser_auth.selectors.submit' => 'button[id="email-login-button"]',
            'browser_auth.approval_wait_seconds' => 180,
            'browser_auth.node_binary' => $this->stubChild($stdoutJson, $stderr),
        ]);
    }

    protected function tearDown(): void
    {
        @unlink(storage_path('framework/testing/fake-node'));
        @unlink($this->jobPath());

        parent::tearDown();
    }

    /** The failure the child reports when no approval arrived. */
    private function awaitingApprovalResult(): string
    {
        return (string) json_encode([
            'ok' => false,
            'code' => 'AWAITING_DEVICE_APPROVAL',
            'message' => 'this free-form text is deliberately not surfaced',
            'evidence' => [
                'requests' => 596,
                'authorization_headers' => 1,
                'non_jwt_authorization' => 1,
                'storage_keys' => 25,
                'cookies' => 12,
                'hosts' => ['stockbit.com'],
                'login_form_gone' => true,
                'url_after_submit' => 'https://portal.example.test/login',
                'landed_url' => 'https://portal.example.test/login',
                'awaiting_approval' => true,
                'approval_signal' => 'refusal-body',
                'approval_granted' => false,
                'approval_waited_ms' => 180_000,
                'auth_rejections' => 12,
            ],
        ]);
    }

    public function test_a_held_login_is_not_reported_as_a_rejected_password(): void
    {
        $this->configureWith($this->awaitingApprovalResult());

        try {
            app(BrowserTokenExtractor::class)->extract('someone@example.test', 'a-secret-password');
            $this->fail('The extraction should have failed.');
        } catch (BrowserTokenExtractionException $exception) {
            $this->assertSame(
                BrowserTokenExtractor::AWAITING_DEVICE_APPROVAL,
                $exception->failureCode,
            );

            // What to do about it, rather than what to doubt: the approval is
            // outstanding, and the credentials are not in question.
            $this->assertStringContainsString('approved on another device', $exception->getMessage());
            $this->assertStringContainsString('BROWSER_AUTH_APPROVAL_WAIT_SECONDS', $exception->getMessage());

            // The sentence that sent the operator to the wrong place must not
            // appear for this outcome.
            $this->assertStringNotContainsString(
                'No device-approval notification is sent',
                $exception->getMessage(),
            );
            $this->assertStringNotContainsString('rejected those credentials', $exception->getMessage());

            // And the evidence says how long it actually held open.
            $this->assertStringContainsString('waited 180s', $exception->getMessage());
        }
    }

    /**
     * The approval request has to arrive while it can still be acted on.
     *
     * A headless browser shows nothing, so the terminal used to sit silent from
     * "Signing in…" until the verdict. The notification reached the phone with
     * nothing to say anybody was waiting for it, and by the time it was tapped
     * the run was over.
     */
    public function test_the_terminal_says_to_approve_while_the_run_is_still_open(): void
    {
        $this->configureWith(
            $this->awaitingApprovalResult(),
            'progress {"event":"submitted_credentials"}'."\n"
            .'progress {"event":"awaiting_device_approval","wait_ms":180000,"signal":"refusal-body"}'."\n"
            .'a line of ordinary node noise that is not a progress event',
        );

        $this->artisan('browser:token', ['--username' => 'someone@example.test'])
            ->expectsQuestion('Portal password (not echoed, not stored)', 'a-secret-password')
            ->expectsOutputToContain('tap the notification within 180s')
            // And the verdict still names the real outcome and what was seen.
            ->expectsOutputToContain('AWAITING_DEVICE_APPROVAL')
            ->expectsOutputToContain('asked for on another device (refusal-body)')
            ->assertFailed();
    }

    /**
     * An approval that landed is worth saying so, because the next question is
     * different: the session exists and the token is what went missing.
     */
    public function test_a_granted_approval_is_reported_as_granted(): void
    {
        $this->configureWith((string) json_encode([
            'ok' => false,
            'code' => 'TOKEN_NOT_FOUND',
            'message' => 'ignored',
            'evidence' => [
                'requests' => 400,
                'hosts' => ['stockbit.com'],
                'awaiting_approval' => true,
                'approval_granted' => true,
                'approval_waited_ms' => 42_000,
            ],
        ]));

        $this->artisan('browser:token', ['--username' => 'someone@example.test'])
            ->expectsQuestion('Portal password (not echoed, not stored)', 'a-secret-password')
            ->expectsOutputToContain('granted after 42s')
            ->assertFailed();
    }

    /**
     * The interactive command waits; nothing else does.
     *
     * A cron job has nobody to tap approve, so a scheduled renewal holding a
     * browser open for three minutes is pure cost -- and the default has to be
     * the safe one, because every unattended caller goes through the same
     * method.
     */
    public function test_only_an_interactive_run_asks_the_child_to_wait(): void
    {
        $this->configureWith($this->awaitingApprovalResult());

        $this->artisan('browser:token', ['--username' => 'someone@example.test'])
            ->expectsQuestion('Portal password (not echoed, not stored)', 'a-secret-password')
            ->assertFailed();

        $this->assertStringContainsString('"approval_wait_ms":180000', $this->job());

        // The same extractor, called the way the scheduled renewal calls it.
        try {
            app(BrowserTokenExtractor::class)->extract('someone@example.test', 'a-secret-password');
        } catch (BrowserTokenExtractionException) {
            // Expected: the stub always fails. The job is the subject here.
        }

        $this->assertStringContainsString('"approval_wait_ms":0', $this->job());
    }

    public function test_the_wait_can_be_switched_off_for_one_run(): void
    {
        $this->configureWith(
            $this->awaitingApprovalResult(),
            'progress {"event":"awaiting_device_approval","wait_ms":0,"signal":"refusal-body"}',
        );

        $this->artisan('browser:token', [
            '--username' => 'someone@example.test',
            '--approval-wait' => '0',
        ])
            ->expectsQuestion('Portal password (not echoed, not stored)', 'a-secret-password')
            ->expectsOutputToContain('not waiting for it')
            ->assertFailed();

        $this->assertStringContainsString('"approval_wait_ms":0', $this->job());
    }

    /**
     * The child is told how to recognise an approval, rather than guessing.
     *
     * Both lists are configuration for the same reason the selectors are: a
     * portal that changes its wording, or moves its approval poll, must cost an
     * .env line rather than a deploy.
     */
    public function test_the_child_is_told_how_to_recognise_an_approval(): void
    {
        $this->configureWith($this->awaitingApprovalResult());

        config([
            'browser_auth.approval_url_hints' => ['/device'],
            'browser_auth.approval_text_hints' => ['setujui'],
            'browser_auth.device_trust_keys' => ['trustedDevice'],
        ]);

        try {
            app(BrowserTokenExtractor::class)->extract('someone@example.test', 'a-secret-password');
        } catch (BrowserTokenExtractionException) {
            // The job is the subject.
        }

        $job = $this->job();

        $this->assertStringContainsString('"approval_url_hints":["\/device"]', $job);
        $this->assertStringContainsString('"approval_text_hints":["setujui"]', $job);
        $this->assertStringContainsString('"device_trust_keys":["trustedDevice"]', $job);
    }

    private function job(): string
    {
        $this->assertFileExists($this->jobPath(), 'the child was never handed a job');

        return (string) file_get_contents($this->jobPath());
    }
}
