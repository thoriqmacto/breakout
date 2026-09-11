<?php

namespace Tests\Feature;

use App\Services\Stockbit\BrowserTokenExtractionException;
use App\Services\Stockbit\BrowserTokenExtractor;
use Tests\TestCase;

/**
 * The diagnosis has to survive the crossing from the child into PHP.
 *
 * It did not. The child counted what it saw and put the summary in its
 * message; `interpret()` replaced that message with a canned explanation --
 * correctly, since a child message can carry a URL, a selector or portal
 * markup -- and the diagnosis was discarded a few microseconds after being
 * computed. From outside, "no bearer token was seen; check
 * BROWSER_AUTH_TOKEN_KEYS" looked identical whether the app had made no
 * authenticated call at all, made one carrying an opaque token, or kept the
 * token somewhere never examined. Three different fixes, one message.
 *
 * The evidence now travels as structured counts, which are none of the things
 * the message rule exists to keep back.
 */
class BrowserTokenEvidenceTest extends TestCase
{
    /**
     * A stub standing in for the node child, emitting one canned result.
     */
    private function stubChildEmitting(string $json): string
    {
        $path = storage_path('framework/testing/fake-node');

        @mkdir(dirname($path), 0775, true);

        // Reads and discards the job on stdin the way the real child does, so
        // the password cannot reach a place a later assertion would find it.
        file_put_contents($path, "#!/bin/sh\ncat > /dev/null\ncat <<'EOF'\n".$json."\nEOF\nexit 1\n");
        chmod($path, 0755);

        return $path;
    }

    private function configureWith(string $json): void
    {
        config([
            'browser_auth.enabled' => true,
            'browser_auth.login_url' => 'https://portal.example.test/login',
            'browser_auth.selectors.username' => 'input[id="username"]',
            'browser_auth.selectors.password' => 'input[name="password"]',
            'browser_auth.selectors.submit' => 'button[id="email-login-button"]',
            'browser_auth.node_binary' => $this->stubChildEmitting($json),
        ]);
    }

    protected function tearDown(): void
    {
        @unlink(storage_path('framework/testing/fake-node'));

        parent::tearDown();
    }

    private function failureFor(string $json): BrowserTokenExtractionException
    {
        $this->configureWith($json);

        try {
            app(BrowserTokenExtractor::class)->extract('someone@example.test', 'a-secret-password');
        } catch (BrowserTokenExtractionException $exception) {
            return $exception;
        }

        $this->fail('The extraction should have failed.');
    }

    public function test_the_counts_the_child_gathered_reach_the_message(): void
    {
        $exception = $this->failureFor((string) json_encode([
            'ok' => false,
            'code' => 'TOKEN_NOT_FOUND',
            'message' => 'this free-form text is deliberately not surfaced',
            'evidence' => [
                'requests' => 42,
                'authorization_headers' => 0,
                'non_jwt_authorization' => 0,
                'json_responses' => 7,
                'storage_keys' => 3,
                'cookies' => 5,
                'hosts' => ['stockbit.com', 'exodus.stockbit.com'],
            ],
        ]));

        $message = $exception->getMessage();

        $this->assertStringContainsString('42 request(s)', $message);
        $this->assertStringContainsString('0 with an Authorization header', $message);
        $this->assertStringContainsString('3 web storage key(s)', $message);
        $this->assertStringContainsString('5 cookie(s)', $message);
        $this->assertStringContainsString('exodus.stockbit.com', $message);

        // The rule that caused the problem still holds: the child's own prose
        // is not repeated, because it can carry markup, a URL or a selector.
        $this->assertStringNotContainsString('deliberately not surfaced', $message);
    }

    /**
     * A bearer that is not a JWT is its own diagnosis and its own fix.
     */
    public function test_a_non_jwt_bearer_is_called_out_separately(): void
    {
        $exception = $this->failureFor((string) json_encode([
            'ok' => false,
            'code' => 'TOKEN_NOT_FOUND',
            'message' => 'ignored',
            'evidence' => [
                'requests' => 12,
                'authorization_headers' => 4,
                'non_jwt_authorization' => 4,
                'json_responses' => 2,
                'storage_keys' => 0,
                'cookies' => 0,
                'hosts' => ['portal.example.test'],
            ],
        ]));

        $this->assertStringContainsString('4 bearer(s) that are not JWTs', $exception->getMessage());
    }

    /**
     * An older child, or one that failed before counting anything, must not
     * turn a useful message into a broken one.
     */
    public function test_a_failure_without_evidence_still_reads_cleanly(): void
    {
        $exception = $this->failureFor((string) json_encode([
            'ok' => false,
            'code' => 'TOKEN_NOT_FOUND',
            'message' => 'ignored',
        ]));

        $this->assertStringContainsString('no bearer token was seen', $exception->getMessage());
        $this->assertStringNotContainsString('Seen:', $exception->getMessage());
    }

    /**
     * Counts localise the problem; names identify it.
     *
     * "15 web storage key(s)" cannot separate a session stored under an
     * unexpected name from no session at all, and those need opposite fixes.
     * The names travel too, and the command prints them.
     */
    public function test_the_command_prints_the_names_behind_the_counts(): void
    {
        $this->configureWith((string) json_encode([
            'ok' => false,
            'code' => 'TOKEN_NOT_FOUND',
            'message' => 'ignored',
            'evidence' => [
                'requests' => 697,
                'authorization_headers' => 2,
                'non_jwt_authorization' => 2,
                'storage_keys' => 15,
                'cookies' => 9,
                'hosts' => ['stockbit.com'],
                'storage_key_names' => ['sb_session', 'theme', '_ga'],
                'cookie_names' => ['SESSIONID', '_gid'],
                'indexeddb_names' => ['sb-offline'],
                'landed_url' => 'https://portal.example.test/verify-device',
                'title' => 'Verify your device',
            ],
        ]));

        $this->artisan('browser:token', ['--username' => 'someone@example.test'])
            ->expectsQuestion('Portal password (not echoed, not stored)', 'a-secret-password')
            ->expectsOutputToContain('sb_session')
            ->expectsOutputToContain('SESSIONID')
            ->expectsOutputToContain('sb-offline')
            // The landing page is the other half: a device-verification screen
            // explains an absent session far better than any count can.
            ->expectsOutputToContain('verify-device')
            ->assertFailed();
    }

    /**
     * "Mentions a token but yielded none" is a different fault entirely.
     *
     * A store whose contents name a token is a decoding problem -- the session
     * is right there and the scan could not read it. A run with no such store
     * has no session at all. Reporting only "0 cookies held a JWT" collapses
     * the two, and they are fixed at opposite ends.
     */
    public function test_a_store_that_claims_a_token_is_named(): void
    {
        $exception = $this->failureFor((string) json_encode([
            'ok' => false,
            'code' => 'TOKEN_NOT_FOUND',
            'message' => 'ignored',
            'evidence' => [
                'requests' => 512,
                'authorization_headers' => 0,
                'storage_keys' => 20,
                'cookies' => 10,
                'hosts' => ['stockbit.com'],
                'claimed_token' => ['credentialStorage'],
            ],
        ]));

        $this->assertStringContainsString(
            'credentialStorage mention a token but none could be read',
            $exception->getMessage(),
        );
    }

    /**
     * A timeout is the failure that most needs the evidence, and had none.
     *
     * TIMEOUT and NAVIGATION_FAILED were raised without any of what had been
     * gathered, so the one message that means "I cannot tell you what
     * happened" was also the one that arrived with nothing attached -- no
     * landing page, no screenshot, nothing to look at. The operator was left
     * re-running it and hoping for a different code.
     */
    public function test_a_timeout_carries_its_evidence_and_its_screenshot(): void
    {
        $this->configureWith((string) json_encode([
            'ok' => false,
            'code' => 'TIMEOUT',
            'message' => 'ignored',
            'evidence' => [
                'requests' => 88,
                'hosts' => ['stockbit.com'],
                'landed_url' => 'https://portal.example.test/captcha',
                'title' => 'Confirm you are human',
                'screenshot' => '/tmp/browser-token-20260907-101500.png',
            ],
        ]));

        $this->artisan('browser:token', ['--username' => 'someone@example.test'])
            ->expectsQuestion('Portal password (not echoed, not stored)', 'a-secret-password')
            ->expectsOutputToContain('TIMEOUT')
            // The three that turn "it timed out" into something answerable.
            ->expectsOutputToContain('/captcha')
            ->expectsOutputToContain('Confirm you are human')
            ->expectsOutputToContain('browser-token-20260907-101500.png')
            ->assertFailed();
    }

    /**
     * "Wrong password" is the wrong answer when no password was offered.
     *
     * `--session` supplies no credentials by design. When the profile it opens
     * carries no session, nothing is submitted and nothing is judged -- but
     * both outcomes were coded INVALID_CREDENTIALS, so the canned explanation
     * for that code replaced the child's accurate message and sent the
     * operator to check a password that was never in question. The real cause
     * is the profile: on a server, usually one user reading a profile another
     * user wrote.
     */
    public function test_an_empty_profile_is_not_reported_as_a_bad_password(): void
    {
        $exception = $this->failureFor((string) json_encode([
            'ok' => false,
            'code' => 'PROFILE_SIGNED_OUT',
            'message' => 'ignored',
            'evidence' => [
                'requests' => 172,
                'authorization_headers' => 0,
                'storage_keys' => 0,
                'cookies' => 0,
                'hosts' => ['stockbit.com'],
                'login_form_gone' => false,
            ],
        ]));

        $this->assertSame(BrowserTokenExtractor::PROFILE_SIGNED_OUT, $exception->failureCode);

        $message = $exception->getMessage();

        $this->assertStringContainsString('signed out', $message);
        // The distinction the whole code exists to draw.
        $this->assertStringNotContainsString('rejected those credentials', $message);
        // And the server-shaped cause, which is what it actually was.
        $this->assertStringContainsString('cannot read it', $message);
    }

    /**
     * The trap this cost an evening to find: the account's display name is not
     * its username. The portal answers 401, which is a real rejection, so the
     * evidence reads exactly like a wrong password -- and because the login
     * never passed that step, no device-approval notification arrives either,
     * which invites the operator to go looking at the device instead.
     */
    public function test_rejected_credentials_name_the_email_trap_and_the_missing_notification(): void
    {
        $exception = $this->failureFor((string) json_encode([
            'ok' => false,
            'code' => 'INVALID_CREDENTIALS',
            'message' => 'ignored',
            'evidence' => [
                'requests' => 566,
                'authorization_headers' => 1,
                'hosts' => ['stockbit.com'],
                'login_form_gone' => true,
            ],
        ]));

        $this->assertSame(BrowserTokenExtractor::INVALID_CREDENTIALS, $exception->failureCode);

        $message = $exception->getMessage();

        $this->assertStringContainsString('email address', $message);
        $this->assertStringContainsString('notification', $message);
    }

    public function test_the_password_never_appears_in_the_diagnosis(): void
    {
        $exception = $this->failureFor((string) json_encode([
            'ok' => false,
            'code' => 'TOKEN_NOT_FOUND',
            'message' => 'ignored',
            'evidence' => ['requests' => 1, 'hosts' => ['portal.example.test']],
        ]));

        $this->assertStringNotContainsString('a-secret-password', $exception->getMessage());
    }
}
