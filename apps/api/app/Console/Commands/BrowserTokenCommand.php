<?php

namespace App\Console\Commands;

use App\Services\Stockbit\BrowserTokenExtractionException;
use App\Services\Stockbit\BrowserTokenExtractor;
use App\Services\Stockbit\StockbitTokenResolver;
use App\Support\StockbitCredentialStore;
use Illuminate\Console\Command;

/**
 * Run one headless login from the terminal, and say what happened.
 *
 *     php artisan browser:token                 # prompt for the password
 *     php artisan browser:token --stored        # use the stored credentials
 *     php artisan browser:token --dry-run       # capture, report, do not store
 *
 * The dashboard shows the same outcome, but going through it means a browser
 * round trip for every attempt while tuning a portal that is being awkward.
 * This is the same code path with the diagnosis in front of you.
 *
 * The password is read from a hidden prompt and used once. The token is never
 * printed -- only its fingerprint, expiry, and which of the five sources it
 * came from.
 */
class BrowserTokenCommand extends Command
{
    protected $signature = 'browser:token
                            {--username= : The portal username or email}
                            {--stored : Use the credentials saved by stockbit:credentials}
                            {--session : Use the saved browser profile without logging in}
                            {--screenshot= : Write a picture of the page here when no token is found}
                            {--dry-run : Report what was captured without storing it}';

    protected $description = 'Sign in to the portal with a headless browser and report what was captured.';

    public function handle(
        BrowserTokenExtractor $extractor,
        StockbitCredentialStore $credentials,
        StockbitTokenResolver $resolver,
    ): int {
        if (! $extractor->enabled()) {
            $this->error('Headless login is switched off. Set BROWSER_AUTH_ENABLED=true and BROWSER_AUTH_LOGIN_URL.');

            return self::FAILURE;
        }

        // With a saved profile the session may already be there, and asking
        // for a password to use one that is not needed would defeat the point.
        $sessionOnly = $this->option('session') || (
            $this->hasProfile($extractor) && ! $this->option('stored') && ! $this->option('username')
                ? ! $this->confirm('A saved browser profile exists. Sign in again with a password?', false)
                : false
        );

        [$username, $password] = $sessionOnly
            ? [null, null]
            : $this->credentials($credentials);

        if (! $sessionOnly && ($username === null || $password === null)) {
            return self::FAILURE;
        }

        $screenshot = $this->option('screenshot');

        if (is_string($screenshot) && trim($screenshot) !== '') {
            $extractor->screenshotPath = $this->resolveScreenshotPath(trim($screenshot));
        }

        $this->line(sprintf('Signing in to %s…', (string) config('browser_auth.login_url')));

        try {
            $result = $extractor->extract($username, $password);
        } catch (BrowserTokenExtractionException $exception) {
            $this->newLine();
            $this->error(sprintf('[%s] %s', $exception->failureCode, $exception->getMessage()));
            $this->reportEvidence($exception->evidence);

            return self::FAILURE;
        }

        $this->newLine();
        $this->info(sprintf(
            'Captured a token from %s in %.1fs.',
            $result['source'],
            $result['elapsed_ms'] / 1000,
        ));

        // The fingerprint is the last four characters: enough to tell two
        // tokens apart, useless to anyone who reads it over your shoulder.
        $this->line(sprintf('  fingerprint  …%s', substr($result['token'], -4)));

        if ($sessionOnly) {
            $this->line('  signed in    from the saved profile, with no password');
        }

        if ($this->option('dry-run')) {
            $this->newLine();
            $this->warn('Dry run: the token was not stored.');

            return self::SUCCESS;
        }

        $resolver->persist($result['token']);
        $this->line('  stored       yes, through the encrypted token store');

        return self::SUCCESS;
    }

    /**
     * A directory means "name the file yourself"; anything else is the file.
     */
    private function resolveScreenshotPath(string $given): string
    {
        return is_dir($given)
            ? rtrim($given, '/').'/browser-token-'.date('Ymd-His').'.png'
            : $given;
    }

    private function hasProfile(BrowserTokenExtractor $extractor): bool
    {
        try {
            return $extractor->profileDir() !== null;
        } catch (BrowserTokenExtractionException $exception) {
            $this->warn($exception->getMessage());

            return false;
        }
    }

    /**
     * Names and landing page, which counts alone cannot supply.
     *
     * "15 web storage key(s)" narrowed the problem and then stopped: it cannot
     * distinguish a session stored under an unexpected name from no session at
     * all. The names can, and a key called "sb_session" answers in one glance
     * what another round of counting would not. Names only -- a key name is
     * structure, its value is the secret.
     *
     * This is the terminal, in front of the operator, so it prints more than
     * the API's one-line summary does.
     *
     * @param  array<string, mixed>|null  $evidence
     */
    private function reportEvidence(?array $evidence): void
    {
        if ($evidence === null) {
            return;
        }

        $this->newLine();

        foreach ([
            // What the page was immediately after submitting, before this
            // navigated anywhere: the only observation that speaks to whether
            // the login itself worked.
            'after submit' => $evidence['url_after_submit'] ?? null,
            'form gone' => array_key_exists('login_form_gone', $evidence)
                ? ($evidence['login_form_gone'] ? 'yes' : 'no -- the login was refused')
                : null,
            'landed on' => $evidence['landed_url'] ?? null,
            'page title' => $evidence['title'] ?? null,
            'screenshot' => $evidence['screenshot'] ?? null,
        ] as $label => $value) {
            if (is_string($value) && $value !== '') {
                $this->line(sprintf('  %-16s %s', $label, $value));
            }
        }

        foreach ([
            'storage keys' => $evidence['storage_key_names'] ?? null,
            'cookies' => $evidence['cookie_names'] ?? null,
            'indexeddb' => $evidence['indexeddb_names'] ?? null,
            'claim a token' => $evidence['claimed_token'] ?? null,
        ] as $label => $names) {
            if (! is_array($names) || $names === []) {
                continue;
            }

            $this->line(sprintf(
                '  %-16s %s',
                $label,
                implode(', ', array_map(static fn ($name): string => (string) $name, $names)),
            ));
        }

        $this->newLine();
        $this->line(
            '<fg=gray>Names only; no values are read back. If none of these looks like a '
            .'session, the login did not complete -- check the landing page above.</>'
        );
    }

    /**
     * @return array{0: ?string, 1: ?string}
     */
    private function credentials(StockbitCredentialStore $store): array
    {
        if ($this->option('stored')) {
            $stored = $store->get();

            if ($stored === null) {
                $this->error($store->exists()
                    ? 'Stored credentials could not be decrypted with this APP_KEY.'
                    : 'No stored credentials. Run `php artisan stockbit:credentials` first.');

                return [null, null];
            }

            return [$stored['username'], $stored['password']];
        }

        $username = trim((string) ($this->option('username') ?: $this->ask('Portal username or email')));
        $password = (string) $this->secret('Portal password (not echoed, not stored)');

        if ($username === '' || $password === '') {
            $this->error('Both a username and a password are required.');

            return [null, null];
        }

        return [$username, $password];
    }
}
