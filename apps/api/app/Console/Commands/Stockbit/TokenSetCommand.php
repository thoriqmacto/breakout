<?php

namespace App\Console\Commands\Stockbit;

use App\Services\Stockbit\StockbitTokenResolver;
use App\Services\StockbitExodusClient;
use Illuminate\Console\Command;

class TokenSetCommand extends Command
{
    protected $signature = 'stockbit:token:set
        {token? : Bearer token (omit to read from STDIN or --from-clipboard)}
        {--from-clipboard : Read token from the system clipboard (pbpaste/xclip/xsel/Get-Clipboard)}';

    protected $description = 'Persist a Stockbit bearer token to the encrypted local store and runtime cache.';

    public function handle(StockbitTokenResolver $resolver): int
    {
        $token = $this->resolveInputToken();

        if ($token === '') {
            $this->error('No token provided. Pass it as an argument, pipe it via STDIN, or use --from-clipboard.');

            return self::FAILURE;
        }

        if (! str_contains($token, '.')) {
            $this->error('The provided value does not look like a JWT (no "." separator).');

            return self::FAILURE;
        }

        $expiresAt = StockbitExodusClient::jwtExpiresAt($token);
        if ($expiresAt !== null && $expiresAt <= new \DateTimeImmutable('now')) {
            $this->error('The provided token is already expired (exp: '.$expiresAt->format('Y-m-d H:i:s T').').');

            return self::FAILURE;
        }

        $resolver->persist($token);

        $tail = substr($token, -4);
        $this->info('Stockbit token saved.');
        $this->line('  fingerprint: ****'.$tail);
        if ($expiresAt !== null) {
            $remaining = $expiresAt->getTimestamp() - time();
            $this->line('  expires_at:  '.$expiresAt->format('Y-m-d H:i:s T'));
            $this->line('  expires_in:  '.$this->formatDuration(max(0, $remaining)));
        } else {
            $this->warn('  expires_at:  unknown (token has no parseable "exp" claim).');
        }

        return self::SUCCESS;
    }

    private function resolveInputToken(): string
    {
        $argument = $this->argument('token');
        if (is_string($argument) && trim($argument) !== '') {
            return trim($argument);
        }

        if ($this->option('from-clipboard')) {
            $clipboard = $this->readClipboard();
            if ($clipboard !== null) {
                return $clipboard;
            }

            $this->warn('Unable to read clipboard. Falling back to STDIN.');
        }

        if (! $this->input->isInteractive() && ! defined('STDIN')) {
            return '';
        }

        // Try non-interactive STDIN first (piped input).
        $stdin = $this->readStdin();
        if ($stdin !== '') {
            return $stdin;
        }

        if ($this->input->isInteractive()) {
            $token = $this->secret('Paste Stockbit bearer token (input hidden)');

            return is_string($token) ? trim($token) : '';
        }

        return '';
    }

    private function readStdin(): string
    {
        if (! defined('STDIN')) {
            return '';
        }

        $stat = @fstat(STDIN);
        if ($stat === false) {
            return '';
        }

        // posix_isatty returns true for interactive terminals. If STDIN is a
        // tty we shouldn't block on fread; only pull when the stream has data.
        if (function_exists('posix_isatty') && @posix_isatty(STDIN)) {
            return '';
        }

        $contents = '';
        while (! feof(STDIN)) {
            $chunk = fread(STDIN, 4096);
            if ($chunk === false) {
                break;
            }
            $contents .= $chunk;
        }

        return trim($contents);
    }

    private function readClipboard(): ?string
    {
        $candidates = [
            ['pbpaste'],
            ['xclip', '-selection', 'clipboard', '-o'],
            ['xsel', '--clipboard', '--output'],
            ['powershell.exe', '-NoProfile', '-Command', 'Get-Clipboard'],
        ];

        foreach ($candidates as $cmd) {
            $output = $this->runProcess($cmd);
            if ($output !== null) {
                $output = trim($output);
                if ($output !== '') {
                    return $output;
                }
            }
        }

        return null;
    }

    /**
     * @param  array<int, string>  $cmd
     */
    private function runProcess(array $cmd): ?string
    {
        if (! function_exists('proc_open')) {
            return null;
        }

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = @proc_open($cmd, $descriptors, $pipes);
        if (! is_resource($process)) {
            return null;
        }

        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]) ?: '';
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($process);

        return $exit === 0 ? $stdout : null;
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

        return $secs.'s';
    }
}
