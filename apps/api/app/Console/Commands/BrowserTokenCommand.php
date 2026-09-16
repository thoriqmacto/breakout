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
            $this->questionTheUsername($exception->failureCode, $username);

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
     * Say the obvious thing about a rejected username, where it can be seen.
     *
     * This portal greets people by a display name, and typing that display
     * name is the single likeliest way to be refused -- it produces a genuine
     * 401, no device notification is sent, and the run reports exactly what it
     * was told. The explanation has always been in the failure message, in the
     * middle of a paragraph, next to four other possibilities. Whether the
     * username looks like an email address is a fact about *this* run, so it
     * belongs on its own line at the end, where the eye lands.
     *
     * Only on a rejection, and only as a question: a portal whose usernames are
     * not email addresses is perfectly ordinary, and this must not nag a person
     * whose username is correct.
     */
    private function questionTheUsername(string $failureCode, ?string $username): void
    {
        if ($failureCode !== BrowserTokenExtractor::INVALID_CREDENTIALS) {
            return;
        }

        if (! is_string($username) || $username === '' || str_contains($username, '@')) {
            return;
        }

        $this->newLine();
        $this->warn(sprintf(
            'The username supplied was "%s", which is not an email address. If this portal greets '
            .'you by a display name, it still authenticates on the account email -- and a display '
            .'name typed here is refused exactly like a wrong password, with no notification sent.',
            $username,
        ));
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
                // Two different things, and saying the wrong one is its own
                // failure: the portal actually asked, or the login simply did
                // not finish and an outstanding approval is the likeliest
                // reason. Claiming the first when only the second is known
                // sends someone to look for a notification that may not exist.
                'awaiting_device_approval' => ($event['signal'] ?? null) === null
                    ? $this->warn(sprintf(
                        '  unfinished    the login did not complete; holding up to %ds in case you are approving it',
                        $approvalWait,
                    ))
                    : $this->warn(sprintf(
                        '  approve now  the portal wants this login approved on another device%s',
                        $approvalWait > 0
                            ? sprintf(' — tap the notification within %ds', $approvalWait)
                            : ' — not waiting for it (--approval-wait=180 to wait)',
                    )),
                'checking_session' => $this->line('  still waiting checking whether the approval has landed yet'),
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
     *
     * And the file has to end in an image extension, because the browser
     * chooses the format from it and refuses a path it cannot read one from --
     * `--screenshot=/tmp/sb` produced `unsupported mime type "null"` and no
     * file at all. Every run in this feature's history that was asked for a
     * picture and given a bare path wrote nothing, and said nothing about it,
     * so the one artefact that would have explained those runs was never
     * there to look at.
     */
    private function resolveScreenshotPath(string $given): string
    {
        if (is_dir($given)) {
            return rtrim($given, '/').'/browser-token-'.date('Ymd-His').'.png';
        }

        return preg_match('/\.(png|jpe?g)$/i', $given) === 1
            ? $given
            : $given.'.png';
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
                ($evidence['held_open_unconfirmed'] ?? false) === true
                    && ($evidence['awaiting_approval'] ?? false) !== true => sprintf(
                        'nothing was said about one; held open %.0fs anyway, in case you were approving',
                        ((int) ($evidence['approval_waited_ms'] ?? 0)) / 1000,
                    ),
                ($evidence['awaiting_approval'] ?? false) === true => sprintf(
                    'asked for on another device (%s), waited %.0fs — approve it while this is running',
                    (string) ($evidence['approval_signal'] ?? 'seen'),
                    ((int) ($evidence['approval_waited_ms'] ?? 0)) / 1000,
                ),
                default => null,
            },
            // Which request was refused, and when. Without it, "the portal
            // rejected those credentials" is a claim with nothing behind it,
            // and the operator's only move is to re-type a password that was
            // never the problem.
            // Whether the credentials were sent at all, and what answered.
            // Nothing here means nothing was submitted: the control that was
            // clicked did not submit, or the portal stopped the attempt.
            'login post' => is_array($evidence['login_posts'] ?? null) && $evidence['login_posts'] !== []
                ? implode(' | ', array_map(static fn ($post): string => (string) $post, $evidence['login_posts']))
                : (($evidence['login_form_gone'] ?? false) === true ? 'none -- nothing was ever posted' : null),
            // And of those, whether any carried the credentials. An app opened
            // without a session posts to its own refresh endpoint and is
            // answered 401; printed as the login post, that says the password
            // was refused in a run where no password was ever sent.
            'credentials' => ($evidence['login_form_gone'] ?? false) === true
                && (int) ($evidence['credential_posts'] ?? 0) === 0
                ? '<fg=red>never sent -- the form went, and nothing carried them to the portal</>'
                : null,
            // Why a submit might send nothing: the handler threw, or it is
            // still awaiting something. The page is the only witness to both.
            'page threw' => is_array($evidence['page_errors'] ?? null) && $evidence['page_errors'] !== []
                ? implode(' | ', array_map(static fn ($line): string => (string) $line, $evidence['page_errors']))
                : null,
            'page logged' => is_array($evidence['console_errors'] ?? null) && $evidence['console_errors'] !== []
                ? implode(' | ', array_map(static fn ($line): string => (string) $line, $evidence['console_errors']))
                : null,
            // Captcha traffic, which the host list hides: reCAPTCHA is served
            // from www.google.com, and so reads as a font or a beacon.
            'captcha' => (int) ($evidence['captcha_requests'] ?? 0) > 0
                ? sprintf(
                    '%d request(s)%s',
                    (int) $evidence['captcha_requests'],
                    ($evidence['captcha_challenged'] ?? false) === true
                        ? ' -- including the challenge frame, which a headless run cannot answer'
                        : '',
                )
                : null,
            'refused' => is_string($evidence['rejected_by'] ?? null)
                ? sprintf(
                    '%s%s',
                    $evidence['rejected_by'],
                    isset($evidence['rejected_after_ms'])
                        ? sprintf(', %.0fs after the submit', ((int) $evidence['rejected_after_ms']) / 1000)
                        : '',
                )
                : null,
            'landed on' => $evidence['landed_url'] ?? null,
            'page title' => $evidence['title'] ?? null,
            // The page when it stalled, which is a different moment from the
            // page it gave up on -- by then the run has opened the post-login
            // page and been sent back.
            'stalled shot' => $evidence['waiting_screenshot'] ?? null,
            'screenshot' => $evidence['screenshot'] ?? null,
            // A picture that was asked for and not written says so, rather
            // than leaving an absent line to be read as "none was requested".
            'no picture' => is_string($evidence['screenshot_error'] ?? null)
                ? $evidence['screenshot_error']
                : null,
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
            // The shape of the bearers that were seen and refused. A long
            // opaque string and the word "undefined" are the same count and
            // opposite problems.
            'bearer shape' => $evidence['non_jwt_bearer_shapes'] ?? null,
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
