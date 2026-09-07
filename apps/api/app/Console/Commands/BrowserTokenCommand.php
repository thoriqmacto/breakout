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
 * printed -- only its fingerprint, expiry, and which of the four sources it
 * came from.
 */
class BrowserTokenCommand extends Command
{
    protected $signature = 'browser:token
                            {--username= : The portal username or email}
                            {--stored : Use the credentials saved by stockbit:credentials}
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

        [$username, $password] = $this->credentials($credentials);

        if ($username === null || $password === null) {
            return self::FAILURE;
        }

        $this->line(sprintf('Signing in to %s…', (string) config('browser_auth.login_url')));

        try {
            $result = $extractor->extract($username, $password);
        } catch (BrowserTokenExtractionException $exception) {
            $this->newLine();
            $this->error(sprintf('[%s] %s', $exception->failureCode, $exception->getMessage()));

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
