<?php

/*
|--------------------------------------------------------------------------
| Headless-browser authentication
|--------------------------------------------------------------------------
|
| Drives a real browser through a portal's login form and captures the bearer
| token it issues, so a token can be renewed without a person reading it out
| of devtools.
|
| This reverses an earlier deliberate decision. The token lifecycle was built
| around "renewal is a person pasting a token", precisely so that no password
| ever had to reach this server. It is off by default and has to be switched
| on knowingly, because turning it on changes what a compromise of this box
| costs: today a stolen bearer expires, whereas a stolen password does not.
|
| Nothing about a specific portal is hard-coded. The URL and the selectors are
| configuration, so pointing this at a different site is an .env change and a
| deploy, not a patch.
|
*/

return [

    /*
    | Off unless deliberately enabled. Without it the endpoint answers 503 and
    | the browser is never launched.
    */
    'enabled' => (bool) env('BROWSER_AUTH_ENABLED', false),

    /*
    | The login page and the three controls on it. Left empty by default: a
    | guessed selector that silently matches the wrong element is worse than a
    | missing one that says so.
    */
    'login_url' => env('BROWSER_AUTH_LOGIN_URL'),

    /*
    | A page of the app to open once logged in, so it makes the authenticated
    | call that carries the bearer.
    |
    | Some portals sign in and land somewhere that loads no data, leaving the
    | token in the app's hands but never on the wire. This is the automated
    | form of what a person does by hand: open a page of the app and read the
    | Authorization header off the request it makes.
    */
    'post_login_url' => env('BROWSER_AUTH_POST_LOGIN_URL'),

    'selectors' => [
        'username' => env('BROWSER_AUTH_USERNAME_SELECTOR', 'input[type="email"]'),
        'password' => env('BROWSER_AUTH_PASSWORD_SELECTOR', 'input[type="password"]'),
        'submit' => env('BROWSER_AUTH_SUBMIT_SELECTOR', 'button[type="submit"]'),
    ],

    /*
    | Node, and the script it runs. Both are absolute paths on the server and
    | neither is ever built from request input -- the process is invoked with
    | an argument list, never a shell string.
    */
    'node_binary' => env('BROWSER_AUTH_NODE_BINARY', 'node'),

    'script' => env(
        'BROWSER_AUTH_SCRIPT',
        // resources/browser/extract-token.mjs, resolved at call time.
        null,
    ),

    /*
    | A Chromium already installed on the box. Playwright otherwise downloads
    | its own, which is ~400MB plus system libraries -- a lot for a small VPS
    | that already ships one.
    */
    'chromium_path' => env('BROWSER_AUTH_CHROMIUM_PATH'),

    /*
    | Where Playwright keeps its browsers. It defaults to the running user's
    | home directory, which is the trap here: `npx playwright install` is run
    | by the deploy user, and PHP-FPM runs as www-data and cannot read another
    | user's home. Point both at a shared directory instead --
    | PLAYWRIGHT_BROWSERS_PATH=/opt/ms-playwright for the install, and this for
    | the run -- or set chromium_path above at a system Chromium.
    */
    'browsers_path' => env('BROWSER_AUTH_BROWSERS_PATH', env('PLAYWRIGHT_BROWSERS_PATH')),

    /*
    | A directory the browser keeps between runs.
    |
    | Without one, every run is a brand-new device: cookies, storage and
    | whatever identity the portal assigned are discarded on close. A portal
    | with a device-trust step can therefore never be satisfied -- approving
    | the device achieves nothing, because the next run is a different device
    | again.
    |
    | With one, the server signs in once, the device is approved through the
    | portal's own flow, and later runs reuse that session without logging in
    | at all -- which also means no password needs storing.
    |
    | Both the CLI user and the web server user write here, so it belongs
    | outside either home directory, group-owned and group-writable.
    */
    'profile_dir' => env('BROWSER_AUTH_PROFILE_DIR'),

    /*
    | How long a renewal waits for the profile when another job is in it.
    |
    | The index catalogue read borrows the same signed-in profile, and Chromium
    | will not open one twice. Generous, because a renewal that gives up leaves
    | the evening collectors without a bearer, while the reader holding the
    | profile finishes in about a minute.
    */
    'profile_wait_seconds' => (int) env('BROWSER_AUTH_PROFILE_WAIT_SECONDS', 180),

    /*
    | How long before expiry the scheduled renewal starts trying.
    |
    | Wide enough that a failed attempt has room for several retries before the
    | token actually dies, narrow enough that a healthy token is not replaced
    | for nothing. Two hours is roughly a dozen hourly attempts.
    */
    'renew_before_minutes' => (int) env('BROWSER_AUTH_RENEW_BEFORE_MINUTES', 120),

    /*
    | Ceiling on one attempt. The Node side races its own timer, and the PHP
    | side allows a little more so the child reports its own failure rather
    | than being killed mid-sentence -- a killed child produces no JSON, and
    | "no output" is a much worse diagnostic than "TIMEOUT".
    |
    | Generous by default because a portal with a device check spends real time
    | between accepting a password and showing the app, and the run has to
    | outlast that: page load, the wait for the form to go, a redirect, and
    | then the search for the token. Too short does not fail faster in any
    | useful sense -- it fails as a timeout, which says nothing about why.
    */
    'timeout_seconds' => (int) env('BROWSER_AUTH_TIMEOUT_SECONDS', 150),

    /*
    | Where the token may be found in a response body, and which paths are
    | worth parsing. Comma-separated so they stay tunable without a deploy.
    */
    'token_keys' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('BROWSER_AUTH_TOKEN_KEYS', 'access_token,accessToken,token,jwt')),
    ))),

    'url_hints' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('BROWSER_AUTH_URL_HINTS', '/api/,/auth,/login,/token,/session')),
    ))),
];
