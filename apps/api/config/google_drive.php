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

];
