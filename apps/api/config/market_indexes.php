<?php

/*
|--------------------------------------------------------------------------
| Published indexes worth watching
|--------------------------------------------------------------------------
|
| An index is a list of symbols somebody else maintains. This installation
| tracks a subset of the market, and the question the dashboard could not
| answer was which of the tracked symbols belong to an index -- and, more
| usefully, which members of the index are not tracked yet.
|
| Nothing about a particular index is hard-coded anywhere else. Adding one is
| an entry here plus a sync run.
|
*/

return [

    /*
    | The index the Assets page opens on when none is named.
    */
    'default' => env('MARKET_INDEX_DEFAULT', 'JII70'),

    'indexes' => [

        'JII70' => [
            'name' => 'Jakarta Islamic Index 70',
            'description' => 'The 70 most liquid sharia-compliant IDX constituents, reviewed by the exchange twice a year.',
            'url' => env('MARKET_INDEX_JII70_URL', 'https://stockbit.com/catalog/indeks/jii70'),

            /*
            | What the index is supposed to hold. Used only to describe the
            | result -- "63 of an expected 70" is a more useful line than "63"
            | -- never to pad or truncate a fetched list.
            */
            'expected_size' => 70,

            /*
            | Below this, a fetched list is treated as a broken fetch rather
            | than as a shrunken index. A page that renders half its table
            | before a timeout produces a plausible-looking short list, and
            | accepting one would silently un-badge half the members.
            */
            'min_members' => (int) env('MARKET_INDEX_JII70_MIN_MEMBERS', 40),
        ],

    ],

    /*
    | The largest share of current members one sync may drop.
    |
    | A real index review replaces a handful of names. Anything larger is far
    | more likely to be a partially loaded page, so the sync refuses it and
    | says so rather than writing it. `--force` is the deliberate override for
    | the day the exchange really does rebuild the index.
    */
    'max_shrink_ratio' => (float) env('MARKET_INDEX_MAX_SHRINK_RATIO', 0.25),

    /*
    | What a symbol is allowed to look like. IDX tickers are four letters;
    | the bound is a little wider so a legitimate oddity is not silently
    | dropped, and narrow enough that page furniture ("MORE", "IDX30") does
    | not enter the table as a constituent.
    */
    'symbol_pattern' => '/^[A-Z][A-Z0-9]{2,5}$/',

    /*
    | Words that look like symbols but are not: index names and navigation
    | labels that appear as links on a catalogue page.
    */
    'symbol_denylist' => ['IDX30', 'LQ45', 'JII', 'JII70', 'IHSG', 'COMPOSITE', 'KOMPAS100', 'SRIKEHATI', 'MORE', 'ALL'],

    /*
    | The headless browser that reads the catalogue page.
    |
    | Defaults are shared with the token extractor because they describe the
    | same machine: the node binary, where Playwright keeps its browsers, an
    | already-installed Chromium.
    |
    | The profile is shared too, which was not the original intention. This was
    | built assuming a catalogue page is public; the first run against the real
    | one landed on https://stockbit.com/login. The session the token renewal
    | keeps alive is the only way past that, so the reader borrows the same
    | profile -- and, because Chromium holds a profile exclusively, the same
    | lock. Leave MARKET_INDEX_PROFILE_DIR empty to read anonymously, which is
    | right for any catalogue that really is public.
    */
    'browser' => [
        'node_binary' => env('MARKET_INDEX_NODE_BINARY', env('BROWSER_AUTH_NODE_BINARY', 'node')),
        'chromium_path' => env('MARKET_INDEX_CHROMIUM_PATH', env('BROWSER_AUTH_CHROMIUM_PATH')),
        'browsers_path' => env('MARKET_INDEX_BROWSERS_PATH', env('BROWSER_AUTH_BROWSERS_PATH', env('PLAYWRIGHT_BROWSERS_PATH'))),
        'timeout_seconds' => (int) env('MARKET_INDEX_TIMEOUT_SECONDS', 90),
        'profile_dir' => env('MARKET_INDEX_PROFILE_DIR', env('BROWSER_AUTH_PROFILE_DIR')),

        /*
        | How long a read waits for the profile before giving up. Short on
        | purpose: the renewal has to win, and a read that loses costs a day
        | of badge staleness rather than a day of missing bars.
        */
        'profile_wait_seconds' => (int) env('MARKET_INDEX_PROFILE_WAIT_SECONDS', 20),
    ],

];
