<?php

namespace App\Console\Commands\Stockbit;

use App\Services\Stockbit\StockbitTokenResolver;
use Illuminate\Console\Command;

class TokenClearCommand extends Command
{
    protected $signature = 'stockbit:token:clear {--force : Skip confirmation prompt}';

    protected $description = 'Forget the locally stored Stockbit bearer token and remove it from cache.';

    public function handle(StockbitTokenResolver $resolver): int
    {
        if (! $this->option('force') && $this->input->isInteractive()) {
            $confirmed = $this->confirm('Remove the locally stored Stockbit token?', false);
            if (! $confirmed) {
                $this->line('Aborted.');

                return self::SUCCESS;
            }
        }

        $resolver->forget();
        $this->info('Stockbit token cleared from local store and cache.');

        return self::SUCCESS;
    }
}
