<?php

namespace App\Console\Commands;

use App\Support\StockbitCredentialStore;
use Illuminate\Console\Command;

/**
 * Store, inspect or erase the portal credentials used for unattended renewal.
 *
 *     php artisan stockbit:credentials            # prompt, hidden input
 *     php artisan stockbit:credentials --status   # what is stored, not the secret
 *     php artisan stockbit:credentials --forget   # complete undo
 *
 * The password is never a command argument. Arguments are visible to every
 * user on the machine through `ps` for as long as the process runs, which for
 * an interactive command is as long as someone takes to answer a prompt.
 * It is read from a hidden prompt, or from stdin for a non-interactive install:
 *
 *     printf '%s' "$PASSWORD" | php artisan stockbit:credentials --username=me --stdin
 *
 * Run it as the user the scheduler runs as. The file is encrypted with the app
 * key, but it still has to be readable by whoever renews the token.
 */
class StockbitCredentialsCommand extends Command
{
    protected $signature = 'stockbit:credentials
                            {--username= : The portal username or email}
                            {--stdin : Read the password from standard input rather than prompting}
                            {--status : Show what is stored without changing it}
                            {--forget : Erase the stored credentials}';

    protected $description = 'Store the portal credentials used to renew the Stockbit token unattended.';

    public function handle(StockbitCredentialStore $store): int
    {
        if ($this->option('status')) {
            return $this->report($store);
        }

        if ($this->option('forget')) {
            $store->forget();
            $this->info('Stored credentials erased. Unattended renewal is off; the paste flow still works.');

            return self::SUCCESS;
        }

        $username = trim((string) ($this->option('username') ?: $this->ask('Portal username or email')));

        if ($username === '') {
            $this->error('A username is required.');

            return self::FAILURE;
        }

        $password = $this->option('stdin')
            ? rtrim((string) file_get_contents('php://stdin'), "\r\n")
            : (string) $this->secret('Portal password (not echoed, not logged)');

        if ($password === '') {
            $this->error('A password is required.');

            return self::FAILURE;
        }

        $store->put($username, $password);

        $this->info(sprintf('Stored credentials for %s, encrypted with this app key.', $username));
        $this->newLine();
        $this->warn(
            'This is a real change in what a compromise of this server costs: a stolen bearer '
            .'expires within hours, a stolen password does not. Erase them with --forget, and '
            .'change the portal password if this box is ever suspect.'
        );

        return self::SUCCESS;
    }

    private function report(StockbitCredentialStore $store): int
    {
        $status = $store->status();

        if (! $status['stored']) {
            $this->line('No credentials stored. Renewal is manual: paste a token, or use the dashboard card.');

            return self::SUCCESS;
        }

        if (! $status['readable']) {
            $this->error(
                'Credentials are stored but cannot be decrypted with this APP_KEY. They were '
                .'written under a different key -- run this command again to replace them.'
            );

            return self::FAILURE;
        }

        $this->info(sprintf(
            'Stored for %s%s.',
            (string) $status['username'],
            $status['stored_at'] ? ' since '.$status['stored_at'] : '',
        ));

        return self::SUCCESS;
    }
}
