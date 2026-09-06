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
    | Ceiling on one attempt. The Node side races its own timer, and the PHP
    | side allows a little more so the child reports its own failure rather
    | than being killed mid-sentence -- a killed child produces no JSON, and
    | "no output" is a much worse diagnostic than "TIMEOUT".
    */
    'timeout_seconds' => (int) env('BROWSER_AUTH_TIMEOUT_SECONDS', 60),

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
