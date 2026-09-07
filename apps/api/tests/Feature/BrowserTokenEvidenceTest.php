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
