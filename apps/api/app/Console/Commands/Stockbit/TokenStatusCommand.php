<?php

namespace App\Console\Commands\Stockbit;

use App\Services\Stockbit\StockbitTokenResolver;
use App\Services\StockbitExodusClient;
use Illuminate\Console\Command;

class TokenStatusCommand extends Command
{
    protected $signature = 'stockbit:token:status';

    protected $description = 'Show the current Stockbit bearer token source and expiry without printing the token itself.';

    public function handle(StockbitTokenResolver $resolver): int
    {
        $resolved = $resolver->resolveWithSource();
        $token = $resolved['token'] ?? null;
        $source = $resolved['source'] ?? StockbitTokenResolver::SOURCE_NONE;

        if (!is_string($token) || $token === '') {
            $this->warn('No Stockbit bearer token is currently available.');
            $this->line('Set one via: php artisan stockbit:token:set');

            return self::SUCCESS;
        }

        $expiresAt = StockbitExodusClient::jwtExpiresAt($token);
        $tail = substr($token, -4);

        $this->info('Stockbit token is configured.');
        $this->line('  source:      ' . $source);
        $this->line('  fingerprint: ****' . $tail);
        if ($expiresAt !== null) {
            $remaining = $expiresAt->getTimestamp() - time();
            $this->line('  expires_at:  ' . $expiresAt->format('Y-m-d H:i:s T'));
            if ($remaining <= 0) {
                $this->warn('  status:      EXPIRED');
            } else {
                $this->line('  expires_in:  ' . $this->formatDuration($remaining));
            }
        } else {
            $this->line('  expires_at:  unknown (token has no parseable "exp" claim).');
        }

        return self::SUCCESS;
    }

    private function formatDuration(int $seconds): string
    {
        if ($seconds <= 0) {
            return '0s';
        }

        $hours = intdiv($seconds, 3600);
        $minutes = intdiv($seconds % 3600, 60);
        $secs = $seconds % 60;

        if ($hours > 0) {
            return sprintf('%dh%02dm', $hours, $minutes);
        }

        if ($minutes > 0) {
            return sprintf('%dm%02ds', $minutes, $secs);
        }

        return $secs . 's';
    }
}
