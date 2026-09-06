<?php

namespace App\Console\Commands;

use App\Services\Stockbit\BrowserTokenExtractor;
use Illuminate\Console\Command;
use Symfony\Component\Process\Process;

/**
 * Answer "would a headless login work, as me?" step by step.
 *
 * The point is the "as me". The setup is run by the deploy user and the
 * endpoint runs as www-data, and every failure so far has lived in that gap:
 * node on one user's PATH, Chromium in one user's home. Running this under
 * each user turns a 502 into a specific line.
 *
 *     php artisan browser:check                  # as you
 *     sudo -u www-data php artisan browser:check # as the web server
 *
 * It launches a browser but never touches the portal and never asks for
 * credentials, so it is safe to run at any time.
 */
class BrowserAuthCheckCommand extends Command
{
    protected $signature = 'browser:check {--json : Machine-readable output}';

    protected $description = 'Check whether a headless login could run as the current user.';

    public function handle(BrowserTokenExtractor $extractor): int
    {
        $checks = [
            'configuration' => $this->checkConfiguration($extractor),
            'script' => $this->checkScript($extractor),
            'node' => $this->checkNode(),
            'dependencies' => $this->checkDependencies($extractor),
            'chromium' => $this->checkChromium($extractor),
        ];

        if ($this->option('json')) {
            $this->line((string) json_encode($checks, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return $this->failed($checks) ? self::FAILURE : self::SUCCESS;
        }

        $this->info(sprintf('Running as %s.', $this->currentUser()));
        $this->newLine();

        foreach ($checks as $name => $check) {
            $this->line(sprintf(
                ' %s  %-14s %s',
                $check['ok'] ? '<fg=green>ok</>' : '<fg=red>--</>',
                $name,
                $check['detail'],
            ));
        }

        $this->newLine();

        if ($this->failed($checks)) {
            $this->error('A headless login would fail as this user.');

            return self::FAILURE;
        }

        $this->info('A headless login could run as this user.');

        return self::SUCCESS;
    }

    /**
     * @param  array<string, array{ok: bool, detail: string}>  $checks
     */
    private function failed(array $checks): bool
    {
        foreach ($checks as $check) {
            if (! $check['ok']) {
                return true;
            }
        }

        return false;
    }

    private function currentUser(): string
    {
        if (function_exists('posix_geteuid') && function_exists('posix_getpwuid')) {
            $user = posix_getpwuid(posix_geteuid());

            if (is_array($user) && isset($user['name'])) {
                return (string) $user['name'];
            }
        }

        return get_current_user() ?: 'unknown';
    }

    /**
     * @return array{ok: bool, detail: string}
     */
    private function checkConfiguration(BrowserTokenExtractor $extractor): array
    {
        if (! $extractor->enabled()) {
            return [
                'ok' => false,
                'detail' => 'disabled: set BROWSER_AUTH_ENABLED=true and BROWSER_AUTH_LOGIN_URL',
            ];
        }

        return ['ok' => true, 'detail' => 'enabled, login URL set'];
    }

    /**
     * @return array{ok: bool, detail: string}
     */
    private function checkScript(BrowserTokenExtractor $extractor): array
    {
        $script = $extractor->scriptPath();

        if (! is_file($script)) {
            return ['ok' => false, 'detail' => sprintf('missing at %s', $script)];
        }

        if (! is_readable($script)) {
            return ['ok' => false, 'detail' => sprintf('%s is not readable by this user', $script)];
        }

        return ['ok' => true, 'detail' => $script];
    }

    /**
     * @return array{ok: bool, detail: string}
     */
    private function checkNode(): array
    {
        $binary = (string) config('browser_auth.node_binary', 'node');

        $process = new Process([$binary, '--version']);

        try {
            $process->run();
        } catch (\Throwable $exception) {
            return [
                'ok' => false,
                'detail' => sprintf(
                    '"%s" could not be executed (%s). Set BROWSER_AUTH_NODE_BINARY to an absolute path.',
                    $binary,
                    $exception->getMessage(),
                ),
            ];
        }

        if (! $process->isSuccessful()) {
            return [
                'ok' => false,
                'detail' => sprintf(
                    '"%s" is not on this user\'s PATH. Set BROWSER_AUTH_NODE_BINARY to an absolute path.',
                    $binary,
                ),
            ];
        }

        return ['ok' => true, 'detail' => sprintf('%s (%s)', trim($process->getOutput()), $binary)];
    }

    /**
     * @return array{ok: bool, detail: string}
     */
    private function checkDependencies(BrowserTokenExtractor $extractor): array
    {
        $modules = dirname($extractor->scriptPath()).'/node_modules/playwright';

        if (! is_dir($modules)) {
            return [
                'ok' => false,
                'detail' => 'playwright is not installed: run "npm install" in resources/browser',
            ];
        }

        if (! is_readable($modules)) {
            return [
                'ok' => false,
                'detail' => sprintf('%s is not readable by this user', $modules),
            ];
        }

        return ['ok' => true, 'detail' => $modules];
    }

    /**
     * The only check that proves rather than infers: it launches a browser.
     *
     * @return array{ok: bool, detail: string}
     */
    private function checkChromium(BrowserTokenExtractor $extractor): array
    {
        $directory = dirname($extractor->scriptPath());
        $probe = $directory.'/launch-probe.mjs';

        if (! is_file($probe)) {
            return ['ok' => false, 'detail' => sprintf('missing at %s', $probe)];
        }

        $environment = [];
        $browsers = config('browser_auth.browsers_path');
        $chromium = config('browser_auth.chromium_path');

        if (is_string($browsers) && trim($browsers) !== '') {
            $environment['PLAYWRIGHT_BROWSERS_PATH'] = trim($browsers);
        }

        if (is_string($chromium) && trim($chromium) !== '') {
            $environment['BROWSER_AUTH_CHROMIUM_PATH'] = trim($chromium);
        }

        $process = new Process(
            [(string) config('browser_auth.node_binary', 'node'), $probe],
            $directory,
            $environment === [] ? null : $environment,
            null,
            60,
        );

        try {
            $process->run();
        } catch (\Throwable $exception) {
            return ['ok' => false, 'detail' => $exception->getMessage()];
        }

        if (! $process->isSuccessful()) {
            $stderr = trim($process->getErrorOutput());

            return [
                'ok' => false,
                'detail' => $stderr === ''
                    ? sprintf('the probe exited with code %s', (string) $process->getExitCode())
                    : mb_substr((string) preg_replace('/\s+/', ' ', $stderr), 0, 300),
            ];
        }

        return ['ok' => true, 'detail' => trim($process->getOutput()) ?: 'launched'];
    }
}
