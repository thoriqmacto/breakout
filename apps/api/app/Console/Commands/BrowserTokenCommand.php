<?php

namespace App\Console\Commands;

use App\Services\Stockbit\BrowserTokenExtractionException;
use App\Services\Stockbit\BrowserTokenExtractor;
use App\Services\Stockbit\StockbitTokenResolver;
use App\Services\Stockbit\StockbitTokenVerifier;
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
                            {--approval-wait= : Seconds to hold the run open while you approve the login on another device}
                            {--dry-run : Report what was captured without storing it}';

    protected $description = 'Sign in to the portal with a headless browser and report what was captured.';

    public function handle(
        BrowserTokenExtractor $extractor,
        StockbitCredentialStore $credentials,
        StockbitTokenResolver $resolver,
        StockbitTokenVerifier $verifier,
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

        $approvalWait = $this->approvalWait();

        // Someone is watching this one, which is the whole reason it can wait
        // for a device approval at all. Nothing scheduled sets this.
        $extractor->onProgress = $this->progressReporter($approvalWait);

        $this->line(sprintf('Signing in to %s…', (string) config('browser_auth.login_url')));

        try {
            // Not session-only means the operator chose to sign in again
            // with a password, and expects that to actually happen even when
            // the app still renders as signed in.
            $result = $extractor->extract(
                $username,
                $password,
                forceLogin: ! $sessionOnly,
                approvalWaitSeconds: $approvalWait,
            );
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

        // Captured is not the same as accepted. The extractor reads the bearer
        // off a request header, which happens before any response exists to
        // say the request was refused -- so a profile whose session has ended
        // hands back the same dead token every time, and this command reported
        // it as a successful capture on each of them.
        $verification = $verifier->verify($result['token']);

        if ($verification['status'] === StockbitTokenVerifier::REJECTED) {
            $this->line('  accepted     <fg=red>no -- the portal refused it</>');
            $this->newLine();
            $this->error((string) $verification['message']);

            return self::FAILURE;
        }

        $this->line($verification['status'] === StockbitTokenVerifier::OK
            ? '  accepted     yes, checked against the API'
            : '  accepted     unknown -- '.$verification['message']);

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
     * How long to hold the run open for a device approval.
     *
     * A person is at the keyboard here, so the configured wait applies; the
     * option is for the run where you already know the phone is in the other
     * room. Zero switches the wait off and still reports the approval request,
     * which is the difference that matters -- the failure names what the portal
     * is waiting for either way.
     */
    private function approvalWait(): int
    {
        $given = $this->option('approval-wait');

        if (is_string($given) && trim($given) !== '') {
            return max(0, (int) trim($given));
        }

        return max(0, (int) config('browser_auth.approval_wait_seconds', 0));
    }

    /**
     * Narrate the run, because the only useful moment to learn that a portal
     * wants a device approved is while it is still waiting for one.
     *
     * A headless browser shows nothing and this command printed nothing between
     * "Signing in…" and the verdict, so an approval notification arrived on a
     * phone with no indication that anything was waiting for it -- and by the
     * time it was tapped the run had been over for a minute and had reported a
     * rejected password.
     *
     * @return callable(array<string, mixed>): void
     */
    private function progressReporter(int $approvalWait): callable
    {
        return function (array $event) use ($approvalWait): void {
            $name = (string) ($event['event'] ?? '');

            match ($name) {
                'submitted_credentials' => $this->line('  submitted    waiting for the portal to answer'),
                'awaiting_device_approval' => $this->warn(sprintf(
                    '  approve now  the portal wants this login approved on another device%s',
                    $approvalWait > 0
                        ? sprintf(' — tap the notification within %ds', $approvalWait)
                        : ' — not waiting for it (--approval-wait=180 to wait)',
                )),
                'reopening_login_page' => $this->line('  still waiting reopening the login page to pick the session up'),
                'approval_granted' => $this->info(sprintf(
                    '  approved     after %.0fs — collecting the token',
                    ((int) ($event['waited_ms'] ?? 0)) / 1000,
                )),
                default => null,
            };
        };
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
            'approval' => match (true) {
                ($evidence['approval_granted'] ?? false) === true => sprintf(
                    'granted after %.0fs',
                    ((int) ($evidence['approval_waited_ms'] ?? 0)) / 1000,
                ),
                ($evidence['awaiting_approval'] ?? false) === true => sprintf(
                    'asked for on another device (%s), waited %.0fs — approve it while this is running',
                    (string) ($evidence['approval_signal'] ?? 'seen'),
                    ((int) ($evidence['approval_waited_ms'] ?? 0)) / 1000,
                ),
                default => null,
            },
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
