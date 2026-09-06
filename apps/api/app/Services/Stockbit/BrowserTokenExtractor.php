<?php

namespace App\Services\Stockbit;

use Illuminate\Support\Str;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

/**
 * Runs the headless-browser login and hands back the bearer it captured.
 *
 * The process boundary is the security boundary, so it is drawn carefully:
 *
 * The child is started from an **argument array**, never a shell string, so
 * nothing here can become a command injection however the inputs are shaped.
 * No value from a request is ever concatenated into a command line.
 *
 * The password travels on **stdin**, not in the arguments. Arguments are
 * world-readable through `ps` for the lifetime of the process, which for a
 * browser launch is tens of seconds.
 *
 * The token comes back on stdout and goes straight to the caller, which
 * persists it through the existing encrypted store. It is never logged, never
 * written to a file here, and never returned through the API.
 */
class BrowserTokenExtractor
{
    /** Failure kinds the Node side reports, mirrored so callers can branch. */
    public const INVALID_CREDENTIALS = 'INVALID_CREDENTIALS';

    public const TIMEOUT = 'TIMEOUT';

    public const TOKEN_NOT_FOUND = 'TOKEN_NOT_FOUND';

    public const BROWSER_LAUNCH_FAILED = 'BROWSER_LAUNCH_FAILED';

    public const NOT_CONFIGURED = 'NOT_CONFIGURED';

    /**
     * Messages worth showing a person, keyed by what the Node side reported.
     *
     * The child's own message is not surfaced verbatim: it can carry a URL, a
     * selector, or a fragment of the portal's markup, and none of that helps
     * whoever is looking at the dashboard.
     */
    private const EXPLANATIONS = [
        self::INVALID_CREDENTIALS => 'The portal rejected those credentials, or asked for a second factor this cannot answer.',
        self::TIMEOUT => 'The login did not finish in time. The portal may be slow or unreachable from this server.',
        self::TOKEN_NOT_FOUND => 'Signed in, but no bearer token was seen. The portal may name its token differently; check BROWSER_AUTH_TOKEN_KEYS.',
        self::BROWSER_LAUNCH_FAILED => 'Chromium could not start on this server. Install it, or point BROWSER_AUTH_CHROMIUM_PATH at an existing one.',
        'SELECTOR_NOT_FOUND' => 'A field on the login form was not found. The portal has probably changed its markup; check the BROWSER_AUTH_*_SELECTOR values.',
        'NAVIGATION_FAILED' => 'The login page could not be opened from this server.',
        'BAD_JOB' => 'The extraction job was malformed. This is a bug rather than a configuration problem.',
    ];

    public function enabled(): bool
    {
        return (bool) config('browser_auth.enabled', false)
            && is_string(config('browser_auth.login_url'))
            && trim((string) config('browser_auth.login_url')) !== '';
    }

    public function scriptPath(): string
    {
        $configured = config('browser_auth.script');

        if (is_string($configured) && trim($configured) !== '') {
            return $configured;
        }

        return resource_path('browser/extract-token.mjs');
    }

    /**
     * @return array{token: string, source: string, elapsed_ms: int}
     *
     * @throws BrowserTokenExtractionException
     */
    public function extract(string $username, string $password): array
    {
        if (! $this->enabled()) {
            throw new BrowserTokenExtractionException(
                self::NOT_CONFIGURED,
                'Headless login is switched off. Set BROWSER_AUTH_ENABLED=true and BROWSER_AUTH_LOGIN_URL.',
            );
        }

        $script = $this->scriptPath();

        if (! is_file($script)) {
            throw new BrowserTokenExtractionException(
                self::NOT_CONFIGURED,
                sprintf('The extraction script is missing at %s.', $script),
            );
        }

        $timeout = max(10, (int) config('browser_auth.timeout_seconds', 60));

        $job = [
            'login_url' => (string) config('browser_auth.login_url'),
            'username' => $username,
            'password' => $password,
            'selectors' => [
                'username' => (string) config('browser_auth.selectors.username'),
                'password' => (string) config('browser_auth.selectors.password'),
                'submit' => (string) config('browser_auth.selectors.submit'),
            ],
            // The child gets the shorter budget so it can report its own
            // timeout; PHP's is the backstop for a child that wedged.
            'timeout_ms' => ($timeout - 5) * 1000,
            'token_keys' => (array) config('browser_auth.token_keys'),
            'url_hints' => (array) config('browser_auth.url_hints'),
            'chromium_path' => config('browser_auth.chromium_path'),
        ];

        // An argument list. There is no shell here, so there is nothing to
        // escape and nothing to inject into.
        //
        // The environment is inherited and then added to, because PHP-FPM's is
        // not the shell's: PLAYWRIGHT_BROWSERS_PATH in particular is usually
        // set in the deploy user's profile and absent here, which is how a
        // Chromium that works from the command line fails under the web
        // server.
        $process = new Process(
            [(string) config('browser_auth.node_binary', 'node'), $script],
            dirname($script),
            $this->childEnvironment(),
            json_encode($job, JSON_THROW_ON_ERROR),
            $timeout,
        );

        try {
            $process->run();
        } catch (ProcessTimedOutException) {
            throw new BrowserTokenExtractionException(
                self::TIMEOUT,
                self::EXPLANATIONS[self::TIMEOUT],
            );
        } finally {
            // Belt and braces: Symfony kills on destruct, but a browser left
            // running is expensive enough to be explicit about.
            if ($process->isRunning()) {
                $process->stop(1);
            }
        }

        return $this->interpret($process, [$password]);
    }

    /**
     * @param  array<int, string>  $secrets  Redacted from anything surfaced.
     * @return array{token: string, source: string, elapsed_ms: int}
     *
     * @throws BrowserTokenExtractionException
     */
    private function interpret(Process $process, array $secrets): array
    {
        $decoded = json_decode(trim($process->getOutput()), true);

        if (! is_array($decoded)) {
            // The child writes JSON to stdout for every outcome it anticipates,
            // so no JSON means it did not get far enough to have an opinion:
            // node missing from PATH, the module tree unreadable, a segfault.
            // The reason for that is on stderr, and throwing it away left the
            // operator with "produced no readable result" and nowhere to go.
            throw new BrowserTokenExtractionException(
                'UNREADABLE',
                $this->describeCrash($process, $secrets),
            );
        }

        if (($decoded['ok'] ?? false) !== true) {
            $code = is_string($decoded['code'] ?? null) ? $decoded['code'] : 'UNEXPECTED';

            throw new BrowserTokenExtractionException(
                $code,
                self::EXPLANATIONS[$code] ?? 'The headless login failed.',
            );
        }

        $token = $decoded['token'] ?? null;

        if (! is_string($token) || substr_count($token, '.') !== 2) {
            throw new BrowserTokenExtractionException(
                self::TOKEN_NOT_FOUND,
                'What came back was not a JWT.',
            );
        }

        return [
            'token' => $token,
            'source' => (string) ($decoded['source'] ?? 'unknown'),
            'elapsed_ms' => (int) ($decoded['elapsed_ms'] ?? 0),
        ];
    }

    /**
     * Additions to the inherited environment, or null to inherit unchanged.
     *
     * @return array<string, string>|null
     */
    private function childEnvironment(): ?array
    {
        $browsers = config('browser_auth.browsers_path');

        return is_string($browsers) && trim($browsers) !== ''
            ? ['PLAYWRIGHT_BROWSERS_PATH' => trim($browsers)]
            : null;
    }

    /**
     * Remove the password from anything on its way to a person.
     *
     * The child redacts its own output, but stderr can come from node itself
     * -- a stack trace quoting the payload, say -- which the child never sees.
     *
     * @param  array<int, string>  $secrets
     */
    private function redact(string $text, array $secrets): string
    {
        foreach ($secrets as $secret) {
            if (is_string($secret) && strlen($secret) >= 3) {
                $text = str_replace($secret, '[redacted]', $text);
            }
        }

        return $text;
    }

    /**
     * Say what actually went wrong when the child never spoke.
     *
     * The reader here is the person running the server, and the alternative
     * is a dead end, so stderr is surfaced -- bounded, on one line, and with
     * the password removed. Two failures are common enough to name outright,
     * because both come from the CLI and the web process being different
     * users: node absent from PHP-FPM's PATH, and Chromium installed into a
     * home directory PHP-FPM cannot read.
     *
     * @param  array<int, string>  $secrets
     */
    private function describeCrash(Process $process, array $secrets): string
    {
        $stderr = trim($process->getErrorOutput());
        $exitCode = $process->getExitCode();

        if (str_contains($stderr, 'not found') && str_contains($stderr, 'node')) {
            return 'Node could not be found by the web server user. It is usually on the '
                .'deploy user\'s PATH only; set BROWSER_AUTH_NODE_BINARY to its absolute path.';
        }

        if (str_contains($stderr, 'Executable doesn\'t exist')
            || str_contains($stderr, 'playwright install')) {
            return 'Chromium was not found by the web server user. Browsers installed as the '
                .'deploy user land in that user\'s home directory, which PHP-FPM cannot read; '
                .'set BROWSER_AUTH_CHROMIUM_PATH, or install to a shared path and set '
                .'BROWSER_AUTH_BROWSERS_PATH.';
        }

        if (str_contains($stderr, 'Cannot find module') || str_contains($stderr, 'ERR_MODULE_NOT_FOUND')) {
            return 'The extraction script could not load its dependencies. Run "npm install" in '
                .'resources/browser, and check the web server user can read node_modules.';
        }

        $excerpt = $stderr === ''
            ? 'no output on stderr either'
            : Str::limit((string) preg_replace('/\s+/', ' ', $this->redact($stderr, $secrets)), 400);

        return sprintf(
            'The extraction process exited with code %s and produced no readable result (%s). '
            .'Run "php artisan browser:check" as the web server user to see which step fails.',
            $exitCode === null ? 'unknown' : (string) $exitCode,
            $excerpt,
        );
    }
}
