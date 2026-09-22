<?php

return [

    /*
    |--------------------------------------------------------------------------
    | How long a refresh token lasts
    |--------------------------------------------------------------------------
    |
    | Google does not say. A refresh token is opaque -- no expiry to read, no
    | claim to inspect -- so this is the operator telling the application what
    | the consent screen implies:
    |
    |   Testing    refresh tokens expire 7 days after they are issued
    |   Published  they do not expire on a timer at all
    |
    | Null means "no timer", which is the right default: inventing a deadline
    | for a published app would produce a weekly warning about nothing, and
    | that is how a reader learns to ignore the row.
    |
    | This does not extend anything. It is a clock for a rule Google enforces
    | at its end, so the warning arrives before the collectors do rather than
    | after they have failed. The actual fix for a testing-mode app is to
    | publish it; this only stops the expiry being a surprise until then.
    |
    */

    'grant_lifetime_days' => env('GOOGLE_DRIVE_GRANT_LIFETIME_DAYS') !== null
        ? (int) env('GOOGLE_DRIVE_GRANT_LIFETIME_DAYS')
        : null,

    /*
    | How much notice to give. Two days by default: enough to cover a weekend
    | between the warning and the expiry, which is when an unattended pipeline
    | is least likely to have anyone watching it.
    */

    'grant_warn_before_days' => (int) env('GOOGLE_DRIVE_GRANT_WARN_BEFORE_DAYS', 2),

    /*
    |--------------------------------------------------------------------------
    | Where Google sends the browser back to
    |--------------------------------------------------------------------------
    |
    | The callback route on this API, which must be registered verbatim as an
    | authorized redirect URI on the OAuth client in Google Cloud Console --
    | Google compares it exactly, down to the scheme and trailing slash.
    |
    |   https://api.example.com/api/v1/integrations/google-drive/callback
    |
    | It points at the API rather than the dashboard because the client secret
    | and the refresh token never leave this side: the browser is only ever
    | redirected through. Leave it unset and the connect button reports the
    | integration as unconfigured rather than failing at Google.
    |
    */

    'redirect_uri' => env('GOOGLE_DRIVE_REDIRECT_URI'),

    /*
    | Where the browser is sent once the round-trip finishes, carrying a
    | ?drive= outcome for the page to report. Falls back to FRONTEND_URL.
    */

    'return_path' => env('GOOGLE_DRIVE_RETURN_PATH', '/dashboard/backups'),

];
