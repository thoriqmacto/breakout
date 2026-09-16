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

    /**
     * A hold nobody announced must not be reported as one the portal asked for.
     *
     * The run holds open on the shape of an unfinished login -- submitted, form
     * gone, no token, still on the login page -- because recognising the hold
     * by wording or by URL is a guess about someone else's markup, and a miss
     * costs the whole run. But what it knows and what it is guessing are
     * different things, and telling someone to go and tap a notification that
     * may not exist is its own kind of wrong.
     */
    public function test_an_unannounced_hold_is_reported_as_unfinished_not_as_an_approval(): void
    {
        $this->configureWith(
            $this->awaitingApprovalResult(),
            'progress {"event":"awaiting_device_approval","wait_ms":180000,"signal":null}',
        );

        $this->artisan('browser:token', ['--username' => 'someone@example.test'])
            ->expectsQuestion('Portal password (not echoed, not stored)', 'a-secret-password')
            ->expectsOutputToContain('the login did not complete; holding up to 180s in case you are approving it')
            ->assertFailed();
    }

    /**
     * The waiting page is never the thing that is asked.
     *
     * The first version looked for the session only once the page had fallen
     * silent, which never happened: the portal holds a websocket open and its
     * heartbeats read as activity. So the interval is configuration, and it is
     * a schedule rather than a condition.
     */
    public function test_the_child_is_given_an_interval_to_look_for_the_session_on(): void
    {
        $this->configureWith($this->awaitingApprovalResult());

        config(['browser_auth.approval_probe_seconds' => 12]);

        try {
            app(BrowserTokenExtractor::class)->extract('someone@example.test', 'a-secret-password');
        } catch (BrowserTokenExtractionException) {
            // The job is the subject.
        }

        $this->assertStringContainsString('"approval_probe_ms":12000', $this->job());
    }

    /**
     * The likeliest cause of a refusal, said where it will be read.
     *
     * A portal that greets you by a display name still authenticates on the
     * account email, and typing the display name is refused exactly like a
     * wrong password -- with no notification sent, which reads as the device
     * prompt having stopped working. The explanation was already in the failure
     * message, in the middle of a paragraph, alongside four other candidates.
     */
    public function test_a_rejected_username_that_is_not_an_email_says_so(): void
    {
        $this->configureWith((string) json_encode([
            'ok' => false,
            'code' => 'INVALID_CREDENTIALS',
            'message' => 'ignored',
            'evidence' => ['requests' => 906, 'hosts' => ['stockbit.com'], 'login_form_gone' => true],
        ]));

        $this->artisan('browser:token', ['--username' => 'macto'])
            ->expectsQuestion('Portal password (not echoed, not stored)', 'a-secret-password')
            ->expectsOutputToContain('which is not an email address')
            ->assertFailed();
    }

    /**
     * And not when the username is one, because then it explains nothing.
     */
    public function test_an_email_username_is_not_second_guessed(): void
    {
        $this->configureWith((string) json_encode([
            'ok' => false,
            'code' => 'INVALID_CREDENTIALS',
            'message' => 'ignored',
            'evidence' => ['requests' => 906, 'hosts' => ['stockbit.com'], 'login_form_gone' => true],
        ]));

        $this->artisan('browser:token', ['--username' => 'someone@example.test'])
            ->expectsQuestion('Portal password (not echoed, not stored)', 'a-secret-password')
            ->doesntExpectOutputToContain('not an email address')
            ->assertFailed();
    }

    /**
     * A path the browser cannot choose a format from writes nothing.
     *
     * `--screenshot=/tmp/sb` was refused with `unsupported mime type "null"`
     * and the failure was swallowed, so every run asked for a picture that way
     * produced none and said nothing about it -- through an entire debugging
     * session in which the picture was repeatedly the thing to look at.
     */
    public function test_a_screenshot_path_without_an_extension_gets_one(): void
    {
        $this->configureWith($this->awaitingApprovalResult());

        $this->artisan('browser:token', [
            '--username' => 'someone@example.test',
            '--screenshot' => '/tmp/sb',
        ])
            ->expectsQuestion('Portal password (not echoed, not stored)', 'a-secret-password')
            ->assertFailed();

        $this->assertStringContainsString('"screenshot_path":"\/tmp\/sb.png"', $this->job());
    }

    public function test_a_screenshot_path_that_already_names_an_image_is_left_alone(): void
    {
        $this->configureWith($this->awaitingApprovalResult());

        $this->artisan('browser:token', [
            '--username' => 'someone@example.test',
            '--screenshot' => '/tmp/shot.PNG',
        ])
            ->expectsQuestion('Portal password (not echoed, not stored)', 'a-secret-password')
            ->assertFailed();

        $this->assertStringContainsString('"screenshot_path":"\/tmp\/shot.PNG"', $this->job());
    }

    /**
     * The count said 11 bearers were refused; it could not say whether they
     * were opaque session tokens or the word "undefined", which are opposite
     * problems with the same count.
     */
    public function test_the_shape_of_a_refused_bearer_is_reported(): void
    {
        $this->configureWith((string) json_encode([
            'ok' => false,
            'code' => 'TOKEN_NOT_FOUND',
            'message' => 'ignored',
            'evidence' => [
                'requests' => 3827,
                'authorization_headers' => 11,
                'non_jwt_authorization' => 11,
                'non_jwt_bearer_shapes' => ['the literal "undefined"'],
                'hosts' => ['stockbit.com'],
                'screenshot_error' => 'path: unsupported mime type "null"',
            ],
        ]));

        $this->artisan('browser:token', ['--username' => 'someone@example.test'])
            ->expectsQuestion('Portal password (not echoed, not stored)', 'a-secret-password')
            ->expectsOutputToContain('the literal "undefined"')
            ->expectsOutputToContain('unsupported mime type')
            ->assertFailed();
    }

    /**
     * Whether anything was submitted at all, which nothing could previously say.
     *
     * A run that never posted the credentials, a run whose post was refused,
     * and a run whose post was accepted and still produced no session all end
     * the same way from the outside: the form is gone, there is no token, and
     * the page is back on the login screen. They need entirely different work.
     */
    public function test_the_login_post_and_its_answer_are_reported(): void
    {
        $this->configureWith((string) json_encode([
            'ok' => false,
            'code' => 'TOKEN_NOT_FOUND',
            'message' => 'ignored',
            'evidence' => [
                'requests' => 4373,
                'hosts' => ['stockbit.com'],
                'login_form_gone' => true,
                'login_posts' => ['POST exodus.stockbit.com/login 200, 1.4s after the submit'],
            ],
        ]));

        $this->artisan('browser:token', ['--username' => 'someone@example.test'])
            ->expectsQuestion('Portal password (not echoed, not stored)', 'a-secret-password')
            ->expectsOutputToContain('POST exodus.stockbit.com/login 200, 1.4s after the submit')
            ->assertFailed();
    }

    /**
     * And the case that matters most: the form went, and nothing was sent.
     */
    public function test_a_submit_that_posted_nothing_says_so(): void
    {
        $this->configureWith((string) json_encode([
            'ok' => false,
            'code' => 'TOKEN_NOT_FOUND',
            'message' => 'ignored',
            'evidence' => [
                'requests' => 4373,
                'hosts' => ['stockbit.com'],
                'login_form_gone' => true,
                'login_posts' => [],
            ],
        ]));

        $this->artisan('browser:token', ['--username' => 'someone@example.test'])
            ->expectsQuestion('Portal password (not echoed, not stored)', 'a-secret-password')
            ->expectsOutputToContain('none -- nothing was ever posted')
            ->assertFailed();
    }

    public function test_the_page_when_it_stalled_is_reported_separately(): void
    {
        $this->configureWith((string) json_encode([
            'ok' => false,
            'code' => 'TOKEN_NOT_FOUND',
            'message' => 'ignored',
            'evidence' => [
                'requests' => 10,
                'hosts' => ['stockbit.com'],
                'waiting_screenshot' => '/tmp/sb-waiting.png',
                'screenshot' => '/tmp/sb.png',
            ],
        ]));

        $this->artisan('browser:token', ['--username' => 'someone@example.test'])
            ->expectsQuestion('Portal password (not echoed, not stored)', 'a-secret-password')
            ->expectsOutputToContain('/tmp/sb-waiting.png')
            ->assertFailed();
    }

    /**
     * The one thing the browser says about itself that a portal reads first.
     *
     * Playwright's default user agent is `HeadlessChrome/<version>`. The
     * extraction script has always accepted an override and the PHP side never
     * sent one, so the .env value that would set it did not exist and the
     * option was unreachable -- a capability present end to end except for the
     * last link.
     */
    public function test_a_configured_user_agent_reaches_the_child(): void
    {
        $this->configureWith($this->awaitingApprovalResult());

        config(['browser_auth.user_agent' => 'Mozilla/5.0 (X11; Linux x86_64) Chrome/141.0.0.0']);

        try {
            app(BrowserTokenExtractor::class)->extract('someone@example.test', 'a-secret-password');
        } catch (BrowserTokenExtractionException) {
            // The job is the subject.
        }

        $this->assertStringContainsString('"user_agent":"Mozilla\/5.0 (X11; Linux x86_64) Chrome\/141.0.0.0"', $this->job());
    }

    /**
     * And unset stays unset: the default is the browser's own, not a disguise
     * this chose on somebody's behalf.
     */
    public function test_no_user_agent_is_sent_when_none_is_configured(): void
    {
        $this->configureWith($this->awaitingApprovalResult());

        config(['browser_auth.user_agent' => null]);

        try {
            app(BrowserTokenExtractor::class)->extract('someone@example.test', 'a-secret-password');
        } catch (BrowserTokenExtractionException) {
            // The job is the subject.
        }

        $this->assertStringContainsString('"user_agent":null', $this->job());
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

    /**
     * The renewal an app posts on load is not the login being refused.
     *
     * The run that provoked this printed, under a login whose credentials had
     * never left the browser:
     *
     *     login post  POST exodus.stockbit.com/login/refresh 401, 191.8s after the submit
     *     refused     POST exodus.stockbit.com/login/refresh 401, 192s after the submit
     *
     * Both lines are about a request that carried a token rather than a
     * password, made by an app discovering it has no session -- which is the
     * condition this run exists to fix, not a verdict on anything typed. Read
     * as written, they say the portal rejected the credentials. The truth was
     * the opposite: no credentials were sent at all.
     */
    public function test_a_session_renewal_is_not_reported_as_the_login_being_refused(): void
    {
        $this->configureWith((string) json_encode([
            'ok' => false,
            'code' => 'TOKEN_NOT_FOUND',
            'message' => 'ignored',
            'evidence' => [
                'requests' => 4314,
                'hosts' => ['stockbit.com'],
                'login_form_gone' => true,
                'login_posts' => [
                    'POST exodus.stockbit.com/login/refresh 401, 191.8s after the submit'
                        .' (a session renewal, not the credentials)',
                ],
                'credential_posts' => 0,
                'rejected_by' => null,
            ],
        ]));

        $this->artisan('browser:token', ['--username' => 'someone@example.test'])
            ->expectsQuestion('Portal password (not echoed, not stored)', 'a-secret-password')
            ->expectsOutputToContain('a session renewal, not the credentials')
            ->assertFailed();
    }

    /**
     * And the conclusion that follows from it, said in the one line that matters.
     */
    public function test_credentials_that_never_left_the_browser_are_named_as_such(): void
    {
        $this->configureWith((string) json_encode([
            'ok' => false,
            'code' => 'TOKEN_NOT_FOUND',
            'message' => 'ignored',
            'evidence' => [
                'requests' => 4314,
                'hosts' => ['stockbit.com'],
                'login_form_gone' => true,
                'credential_posts' => 0,
            ],
        ]));

        $this->artisan('browser:token', ['--username' => 'someone@example.test'])
            ->expectsQuestion('Portal password (not echoed, not stored)', 'a-secret-password')
            ->expectsOutputToContain('never sent')
            ->assertFailed();
    }

    /**
     * Why a submit that cleared the form might have sent nothing.
     *
     * An exception in the handler and a captcha that never answers produce
     * identical evidence everywhere else -- form gone, spinner up, no request
     * -- and need opposite fixes.
     */
    public function test_an_exception_the_page_threw_is_reported(): void
    {
        $this->configureWith((string) json_encode([
            'ok' => false,
            'code' => 'TOKEN_NOT_FOUND',
            'message' => 'ignored',
            'evidence' => [
                'requests' => 4314,
                'hosts' => ['stockbit.com'],
                'login_form_gone' => true,
                'credential_posts' => 0,
                'page_errors' => ["Cannot read properties of undefined (reading 'token')"],
            ],
        ]));

        $this->artisan('browser:token', ['--username' => 'someone@example.test'])
            ->expectsQuestion('Portal password (not echoed, not stored)', 'a-secret-password')
            ->expectsOutputToContain('Cannot read properties of undefined')
            ->assertFailed();
    }

    public function test_captcha_traffic_is_reported_because_the_host_list_hides_it(): void
    {
        $this->configureWith((string) json_encode([
            'ok' => false,
            'code' => 'TOKEN_NOT_FOUND',
            'message' => 'ignored',
            'evidence' => [
                'requests' => 4314,
                // Served from www.google.com, and so indistinguishable in this
                // list from a webfont or an analytics beacon.
                'hosts' => ['stockbit.com', 'www.google.com'],
                'login_form_gone' => true,
                'credential_posts' => 0,
                'captcha_requests' => 14,
                'captcha_challenged' => true,
            ],
        ]));

        $this->artisan('browser:token', ['--username' => 'someone@example.test'])
            ->expectsQuestion('Portal password (not echoed, not stored)', 'a-secret-password')
            ->expectsOutputToContain('14 request(s)')
            ->assertFailed();
    }

    /**
     * The same fact, in the message the dashboard shows rather than the terminal.
     *
     * "Signed in, but no bearer token was seen" takes a completed login for
     * granted, and every remedy it goes on to offer -- BROWSER_AUTH_TOKEN_KEYS,
     * a post-login URL -- is for a different problem.
     */
    public function test_the_summary_says_the_credentials_were_never_sent(): void
    {
        $this->configureWith((string) json_encode([
            'ok' => false,
            'code' => 'TOKEN_NOT_FOUND',
            'message' => 'ignored',
            'evidence' => [
                'requests' => 4314,
                'authorization_headers' => 12,
                'storage_keys' => 24,
                'cookies' => 10,
                'hosts' => ['stockbit.com'],
                'login_form_gone' => true,
                'credential_posts' => 0,
                'captcha_requests' => 14,
            ],
        ]));

        try {
            app(BrowserTokenExtractor::class)->extract('someone@example.test', 'a-secret-password');
            $this->fail('The extraction should have failed.');
        } catch (BrowserTokenExtractionException $exception) {
            $this->assertStringContainsString(
                'The credentials were never sent',
                $exception->getMessage(),
            );
            $this->assertStringContainsString('captcha', $exception->getMessage());
        }
    }

    /**
     * But not when the portal said it was waiting on another device.
     *
     * A portal that asked a phone to approve this login was plainly sent
     * something, whatever the response listener managed to attribute -- and
     * telling that operator the credentials never left the browser would send
     * them to re-type a password while the notification they need to tap is
     * still on their screen.
     */
    public function test_an_outstanding_approval_is_not_reported_as_credentials_never_sent(): void
    {
        $this->configureWith((string) json_encode([
            'ok' => false,
            'code' => 'AWAITING_DEVICE_APPROVAL',
            'message' => 'ignored',
            'evidence' => [
                'requests' => 596,
                'hosts' => ['stockbit.com'],
                'login_form_gone' => true,
                'credential_posts' => 0,
                'awaiting_approval' => true,
                'approval_signal' => 'refusal-body',
                'approval_waited_ms' => 180_000,
            ],
        ]));

        try {
            app(BrowserTokenExtractor::class)->extract('someone@example.test', 'a-secret-password');
            $this->fail('The extraction should have failed.');
        } catch (BrowserTokenExtractionException $exception) {
            $this->assertStringNotContainsString('never sent', $exception->getMessage());
            $this->assertStringContainsString('approved on another device', $exception->getMessage());
        }
    }

    public function test_the_child_is_told_which_paths_are_session_renewals(): void
    {
        $this->configureWith($this->awaitingApprovalResult());

        config(['browser_auth.refresh_url_hints' => ['/refresh']]);

        try {
            app(BrowserTokenExtractor::class)->extract('someone@example.test', 'a-secret-password');
        } catch (BrowserTokenExtractionException) {
            // The job is the subject.
        }

        $this->assertStringContainsString('"refresh_url_hints":["\/refresh"]', $this->job());
    }

    /**
     * The browser runs headless unless something says otherwise.
     *
     * The child has always accepted this setting and PHP never sent it, so the
     * .env line that would turn it off did not exist -- the same last-link gap
     * the user agent had, found the same way: by needing it.
     */
    public function test_the_run_is_headless_by_default(): void
    {
        $this->configureWith($this->awaitingApprovalResult());

        try {
            app(BrowserTokenExtractor::class)->extract('someone@example.test', 'a-secret-password');
        } catch (BrowserTokenExtractionException) {
            // The job is the subject.
        }

        $this->assertStringContainsString('"headless":true', $this->job());
    }

    public function test_headless_can_be_turned_off_in_configuration(): void
    {
        $this->configureWith($this->awaitingApprovalResult());

        config(['browser_auth.headless' => false]);

        try {
            app(BrowserTokenExtractor::class)->extract('someone@example.test', 'a-secret-password');
        } catch (BrowserTokenExtractionException) {
            // The job is the subject.
        }

        $this->assertStringContainsString('"headless":false', $this->job());
    }

    /**
     * And for one run, without touching a cached config on a live server.
     */
    public function test_headful_switches_a_single_run(): void
    {
        $this->configureWith($this->awaitingApprovalResult());

        $this->artisan('browser:token', [
            '--username' => 'someone@example.test',
            '--headful' => true,
        ])
            ->expectsQuestion('Portal password (not echoed, not stored)', 'a-secret-password')
            ->assertFailed();

        $this->assertStringContainsString('"headless":false', $this->job());
    }

    /**
     * Without the flag the configured value stands, rather than being
     * overwritten by the option's own default.
     */
    public function test_a_run_without_the_flag_keeps_the_configured_value(): void
    {
        $this->configureWith($this->awaitingApprovalResult());

        config(['browser_auth.headless' => false]);

        $this->artisan('browser:token', ['--username' => 'someone@example.test'])
            ->expectsQuestion('Portal password (not echoed, not stored)', 'a-secret-password')
            ->assertFailed();

        $this->assertStringContainsString('"headless":false', $this->job());
    }

    /**
     * A console error is useless without knowing who logged it.
     *
     * "requestStorageAccess: Permission denied" from the captcha's own frame is
     * the login stalling. The identical line from a "sign in with Google"
     * button is noise. The message alone cannot tell them apart, and the run
     * that produced it had both scripts on the page.
     */
    public function test_a_console_error_is_reported_with_the_host_that_logged_it(): void
    {
        $this->configureWith((string) json_encode([
            'ok' => false,
            'code' => 'TOKEN_NOT_FOUND',
            'message' => 'ignored',
            'evidence' => [
                'requests' => 3146,
                'hosts' => ['stockbit.com'],
                'login_form_gone' => true,
                'credential_posts' => 0,
                'console_errors' => ['requestStorageAccess: Permission denied. [www.google.com]'],
            ],
        ]));

        $this->artisan('browser:token', ['--username' => 'someone@example.test'])
            ->expectsQuestion('Portal password (not echoed, not stored)', 'a-secret-password')
            ->expectsOutputToContain('[www.google.com]')
            ->assertFailed();
    }

    private function job(): string
    {
        $this->assertFileExists($this->jobPath(), 'the child was never handed a job');

        return (string) file_get_contents($this->jobPath());
    }
}
