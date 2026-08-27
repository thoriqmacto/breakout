<?php

/*
|--------------------------------------------------------------------------
| Database-managed automation
|--------------------------------------------------------------------------
|
| Scheduled work lives in the `scheduled_tasks` table rather than in
| routes/console.php, so it can be enabled, edited and disabled from the
| dashboard without a deploy. Laravel keeps exactly one static entry --
| `scheduler:dispatch`, every minute -- and that dispatcher decides which
| database rows are due.
|
| Nothing here is ever executed as a shell string. A task names an Artisan
| command from `commands` below and carries structured parameters that are
| validated against that command's declared specification before they reach
| Artisan::call().
|
*/

return [

    /*
    | The market this scheduler serves is IDX, so every schedule and every
    | market-day calculation is evaluated in WIB. The application's own
    | default timezone (config/app.php) stays UTC and is deliberately not
    | changed: timestamps are stored in UTC, only the *interpretation* of a
    | cron expression and of "today" is Jakarta.
    */
    'timezone' => env('AUTOMATION_TIMEZONE', 'Asia/Jakarta'),

    /*
    | Minutes of missed occurrences the dispatcher will still pick up. A cron
    | that stalls for two minutes should not silently drop the 16:00 run, but
    | a machine that was off all weekend must not replay every occurrence, so
    | the window is deliberately small. Duplicate protection is a unique index
    | on (scheduled_task_id, scheduled_for), not this number.
    */
    'catch_up_minutes' => (int) env('AUTOMATION_CATCH_UP_MINUTES', 5),

    /*
    | Longest a single dispatcher pass may spend running due tasks before it
    | stops and leaves the rest to the next minute. Tasks are executed in
    | priority order, so the important ones have already run.
    */
    'dispatch_budget_seconds' => (int) env('AUTOMATION_DISPATCH_BUDGET_SECONDS', 3300),

    'locks' => [
        /*
        | Held for the whole of one task's execution so the same task can never
        | overlap itself, even across processes or servers.
        */
        'task_seconds' => (int) env('AUTOMATION_TASK_LOCK_SECONDS', 7200),

        /*
        | Every bulk Stockbit job takes this one in addition to its own task
        | lock, so the daily OHLCV scrape and the weekly broker-summary scrape
        | queue behind each other instead of hitting the API together.
        */
        'stockbit_seconds' => (int) env('AUTOMATION_STOCKBIT_LOCK_SECONDS', 7200),

        /*
        | How long a bulk Stockbit job waits for the shared lock before giving
        | up. The weekly job is dispatched right behind the daily one at the
        | same nominal minute and must wait for it, not fail past it.
        */
        'stockbit_wait_seconds' => (int) env('AUTOMATION_STOCKBIT_LOCK_WAIT_SECONDS', 3000),
    ],

    /*
    | Captured Artisan output is stored so a run can be diagnosed from the
    | dashboard, but a verbose scrape prints a line per ticker and the table
    | must not grow without bound. Output beyond this many characters is
    | truncated from the front, keeping the tail where failures surface.
    */
    'max_output_length' => (int) env('AUTOMATION_MAX_OUTPUT_LENGTH', 20000),

    'stockbit' => [
        /*
        | Minimum token lifetime, in minutes, required before a bulk scrape is
        | allowed to start. A token that expires two minutes into an hour-long
        | run is not usable, and finding that out halfway through leaves a
        | partial import behind.
        */
        'min_ttl_minutes' => (int) env('AUTOMATION_STOCKBIT_MIN_TTL_MINUTES', 90),

        /*
        | Lifetime, in minutes, below which the token is reported as expiring
        | soon and the daily reminder raises an alert.
        */
        'warn_ttl_minutes' => (int) env('AUTOMATION_STOCKBIT_WARN_TTL_MINUTES', 720),
    ],

    /*
    | Disk the broker-summary JSON archive is mirrored to. Null disables the
    | mirror, which is the default for local development.
    */
    'broker_summary_mirror_disk' => env('BROKER_SUMMARY_MIRROR_DISK', env('CSV_MIRROR_DISK')) ?: null,

    /*
    |--------------------------------------------------------------------------
    | Artisan command allowlist
    |--------------------------------------------------------------------------
    |
    | The dashboard may only schedule commands named here, and may only pass
    | the arguments and options each entry declares. Anything else is rejected
    | by validation before it is stored, and again before it is executed.
    |
    | Parameter types: boolean, integer, date (YYYY-MM-DD), enum, string,
    | symbol_list (an array of ticker symbols).
    |
    */
    'commands' => [

        'automation:ohlcv-daily' => [
            'label' => 'Daily OHLCV sync',
            'description' => 'Scrape and persist today\'s daily bar for every price-synced asset, then mirror the touched CSVs.',
            'stockbit_bulk' => true,
            'arguments' => [],
            'options' => [
                'date' => ['type' => 'date', 'label' => 'Trading date (defaults to today in Asia/Jakarta)'],
                'tickers' => ['type' => 'symbol_list', 'label' => 'Limit to specific tickers'],
                'no-mirror' => ['type' => 'boolean', 'label' => 'Skip the Google Drive mirror'],
                'disk' => ['type' => 'enum', 'values' => ['gdrive', 'local'], 'label' => 'Mirror disk override'],
            ],
        ],

        'automation:broker-summary-weekly' => [
            'label' => 'Weekly broker summary',
            'description' => 'Fetch one aggregate broker-summary window for the current trading week, import it, and mirror the JSON.',
            'stockbit_bulk' => true,
            'arguments' => [],
            'options' => [
                'from' => ['type' => 'date', 'label' => 'Week start override'],
                'to' => ['type' => 'date', 'label' => 'Week end override'],
                'tickers' => ['type' => 'symbol_list', 'label' => 'Limit to specific tickers'],
                'no-import' => ['type' => 'boolean', 'label' => 'Scrape without importing'],
                'no-mirror' => ['type' => 'boolean', 'label' => 'Skip the Google Drive mirror'],
            ],
        ],

        'automation:token-check' => [
            'label' => 'Stockbit token reminder',
            'description' => 'Inspect the stored Stockbit JWT and raise a dashboard reminder when it needs renewing.',
            'stockbit_bulk' => false,
            'arguments' => [],
            'options' => [
                'warn-minutes' => ['type' => 'integer', 'min' => 1, 'max' => 20160, 'label' => 'Warn when fewer minutes remain'],
            ],
        ],

        'stockbit:scrape' => [
            'label' => 'Stockbit scrape',
            'description' => 'The raw scraper. --token is deliberately not schedulable; the stored token is always used.',
            'stockbit_bulk' => true,
            'arguments' => [
                'tickers' => ['type' => 'symbol_list', 'label' => 'Tickers'],
            ],
            'options' => [
                'all' => ['type' => 'boolean'],
                'historical' => ['type' => 'boolean'],
                'market-detector' => ['type' => 'boolean'],
                'from' => ['type' => 'date'],
                'to' => ['type' => 'date'],
                'no-profile-sync' => ['type' => 'boolean'],
                'no-persist' => ['type' => 'boolean'],
                'disk' => ['type' => 'enum', 'values' => ['gdrive', 'local']],
            ],
        ],

        'asset:sync' => [
            'label' => 'Asset sync',
            'description' => 'Historical price synchronisation with the python/yfinance fallback.',
            'stockbit_bulk' => true,
            'arguments' => [],
            'options' => [
                'eod' => ['type' => 'boolean'],
                'continue' => ['type' => 'boolean'],
                'chk-date' => ['type' => 'date'],
                'historical-date' => ['type' => 'date'],
                'broker-summary' => ['type' => 'boolean'],
                'broker-summary-import-only' => ['type' => 'boolean'],
                'broker-summary-date' => ['type' => 'date'],
                'broker-summary-from' => ['type' => 'date'],
                'broker-summary-to' => ['type' => 'date'],
                'disk' => ['type' => 'enum', 'values' => ['gdrive', 'local']],
            ],
        ],

        'bars:mirror-push' => [
            'label' => 'Push OHLCV CSVs to Drive',
            'description' => 'Upload local seed CSVs to the mirror disk.',
            'stockbit_bulk' => false,
            'arguments' => [],
            'options' => [
                'disk' => ['type' => 'enum', 'values' => ['gdrive', 'local']],
                'symbol' => ['type' => 'symbol_list'],
                'force' => ['type' => 'boolean'],
            ],
        ],

        'broker-summary:mirror-push' => [
            'label' => 'Push broker-summary JSON to Drive',
            'description' => 'Upload the local broker-summary JSON archive to the cold-storage disk.',
            'stockbit_bulk' => false,
            'arguments' => [],
            'options' => [
                'disk' => ['type' => 'enum', 'values' => ['gdrive', 'local']],
                'since' => ['type' => 'date'],
                'force' => ['type' => 'boolean'],
            ],
        ],

        'broker-summary:rebuild' => [
            'label' => 'Rebuild broker-summary windows',
            'description' => 'Recovery only: re-reads the whole JSON archive. The weekly job imports just its own files.',
            'stockbit_bulk' => false,
            'arguments' => [],
            'options' => [
                'disk' => ['type' => 'enum', 'values' => ['gdrive', 'local']],
                'dir' => ['type' => 'string', 'pattern' => '/^[A-Za-z0-9._\/-]{1,128}$/'],
                'dry-run' => ['type' => 'boolean'],
            ],
        ],

        'trading-calendar:build' => [
            'label' => 'Rebuild the trading calendar',
            'description' => 'Refresh trading_calendar, which every trading-day condition reads.',
            'stockbit_bulk' => false,
            'arguments' => [],
            'options' => [
                'from' => ['type' => 'date'],
                'to' => ['type' => 'date'],
            ],
        ],

        'trading-days:build' => [
            'label' => 'Rebuild trading days from Yahoo',
            'description' => 'Populate trading_days, the source trading_calendar is derived from.',
            'stockbit_bulk' => false,
            'arguments' => [],
            'options' => [
                'from' => ['type' => 'date'],
                'to' => ['type' => 'date'],
            ],
        ],

        'asset:metrics' => [
            'label' => 'Recalculate asset metrics',
            'description' => 'Recompute per-asset metrics.',
            'stockbit_bulk' => false,
            'arguments' => [],
            'options' => [
                'all' => ['type' => 'boolean'],
                'sym' => ['type' => 'string', 'pattern' => '/^[A-Za-z0-9,.\- ]{1,512}$/'],
                'persist' => ['type' => 'boolean'],
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Seeded system automations
    |--------------------------------------------------------------------------
    |
    | Created by the migration that adds the table and re-creatable with
    | `php artisan db:seed --class=AutomationSeeder`. They stay fully editable
    | from the dashboard -- `is_system` only marks where they came from.
    |
    */
    'defaults' => [
        [
            'name' => 'Daily OHLCV Sync',
            'slug' => 'daily-ohlcv-sync',
            'description' => 'Every IDX trading day at 16:00 WIB, scrape that day\'s daily bar for every price-synced asset and mirror the changed CSVs to Google Drive.',
            'command' => 'automation:ohlcv-daily',
            'parameters' => ['arguments' => [], 'options' => []],
            'cron_expression' => '0 16 * * *',
            'condition' => 'trading_day',
            'priority' => 10,
            'enabled' => true,
            'sync_gdrive_after_success' => true,
        ],
        [
            'name' => 'Weekly Broker Summary',
            'slug' => 'weekly-broker-summary',
            'description' => 'On the final valid IDX trading day of each week at 16:00 WIB, fetch one aggregate broker-summary window covering that week, import it, and mirror the JSON to Google Drive. Runs after the daily OHLCV job.',
            'command' => 'automation:broker-summary-weekly',
            'parameters' => ['arguments' => [], 'options' => []],
            'cron_expression' => '0 16 * * *',
            'condition' => 'last_trading_day_of_week',
            'priority' => 20,
            'enabled' => true,
            'sync_gdrive_after_success' => true,
        ],
        [
            'name' => 'Stockbit Token Reminder',
            'slug' => 'stockbit-token-reminder',
            'description' => 'Every day at 09:00 WIB, check the stored Stockbit JWT and raise a dashboard reminder when it is missing, expired or expiring soon.',
            'command' => 'automation:token-check',
            'parameters' => ['arguments' => [], 'options' => []],
            'cron_expression' => '0 9 * * *',
            'condition' => 'none',
            'priority' => 5,
            'enabled' => true,
            'sync_gdrive_after_success' => false,
        ],
    ],
];
