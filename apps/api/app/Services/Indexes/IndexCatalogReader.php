<?php

namespace App\Services\Indexes;

use App\Support\BrowserProfileLock;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

/**
 * Run the catalogue page through a headless browser and return its symbols.
 *
 * The page is a client-rendered app, so an HTTP fetch returns a shell with no
 * constituents in it. A browser is the only reader that sees what a person
 * sees.
 *
 * This was built on the assumption that a catalogue page is public, which
 * turned out to be false for Stockbit: the first run against the real page
 * landed on https://stockbit.com/login. So the reader reports LOGIN_REQUIRED
 * as its own outcome rather than blaming the markup, and the paste box carries
 * the membership in the meantime.
 *
 * It still involves no bearer and no saved profile. That is now a limitation
 * rather than a virtue, but it is also what keeps a change here from reaching
 * the token store.
 *
 * The child is invoked with an argument list and fed JSON on stdin. There is
 * no shell in this path and nothing is interpolated into a command string.
 */
class IndexCatalogReader
{
    public function available(): bool
    {
        return File::exists($this->scriptPath());
    }

    /**
     * @param  array{diagnose?: bool, dump_html?: string|null}  $options
     *                                                                    diagnose gathers samples of the page for a person to read;
     *                                                                    dump_html writes the rendered HTML to that path. Both are
     *                                                                    operator tools, off unless asked for.
     * @return array{symbols: array<int, string>, evidence: array<string, mixed>}
     *
     * @throws IndexCatalogReadException
     */
    public function read(string $url, array $options = []): array
    {
        $script = $this->scriptPath();

        if (! File::exists($script)) {
            throw new IndexCatalogReadException(
                IndexCatalogReadException::NOT_INSTALLED,
                'The catalogue reader script is missing from resources/browser.',
            );
        }

        $timeout = max(15, (int) config('market_indexes.browser.timeout_seconds', 90));

        $job = [
            'url' => $url,
            // The child gets the shorter budget so it reports its own timeout
            // rather than being killed mid-sentence.
            'timeout_ms' => ($timeout - 5) * 1000,
            'chromium_path' => config('market_indexes.browser.chromium_path'),
            'diagnose' => (bool) ($options['diagnose'] ?? false),
            'dump_html' => $options['dump_html'] ?? null,
            'profile_dir' => $this->profileDir(),
        ];

        $process = new Process(
            [(string) config('market_indexes.browser.node_binary', 'node'), $script],
            dirname($script),
            $this->childEnvironment(),
            json_encode($job, JSON_THROW_ON_ERROR),
            $timeout,
        );

        // Only when a profile is in play: an anonymous read shares nothing and
        // has no reason to queue behind the token renewal.
        $lock = null;

        if ($job['profile_dir'] !== null) {
            $lock = BrowserProfileLock::make($timeout + 60);

            if (! BrowserProfileLock::acquire($lock, BrowserProfileLock::readWait())) {
                throw new IndexCatalogReadException(
                    IndexCatalogReadException::PROFILE_BUSY,
                    'The saved browser profile is in use, most likely by the Stockbit token renewal.',
                );
            }
        }

        try {
            $process->run();
        } catch (ProcessTimedOutException) {
            throw new IndexCatalogReadException(
                IndexCatalogReadException::TIMEOUT,
                sprintf('The catalogue page did not finish loading within %d seconds.', $timeout),
            );
        } finally {
            if ($process->isRunning()) {
                $process->stop(1);
            }

            BrowserProfileLock::release($lock);
        }

        return $this->interpret($process, $url);
    }

    /**
     * @return array{symbols: array<int, string>, evidence: array<string, mixed>}
     *
     * @throws IndexCatalogReadException
     */
    private function interpret(Process $process, string $url): array
    {
        $decoded = json_decode(trim($process->getOutput()), true);

        if (! is_array($decoded)) {
            // The child writes JSON for every outcome it anticipates, so no
            // JSON at all means it never got to run: node missing from the
            // web server's PATH, an unreadable module tree, a crash.
            throw new IndexCatalogReadException(
                IndexCatalogReadException::NOT_INSTALLED,
                $this->describeCrash($process),
            );
        }

        if (($decoded['ok'] ?? false) !== true) {
            $reason = (string) ($decoded['code'] ?? IndexCatalogReadException::UNEXPECTED);

            throw new IndexCatalogReadException(
                in_array($reason, [
                    IndexCatalogReadException::BROWSER_LAUNCH_FAILED,
                    IndexCatalogReadException::NAVIGATION_FAILED,
                    IndexCatalogReadException::NO_SYMBOLS_FOUND,
                    IndexCatalogReadException::LOGIN_REQUIRED,
                    IndexCatalogReadException::PROFILE_BUSY,
                    IndexCatalogReadException::TIMEOUT,
                ], true) ? $reason : IndexCatalogReadException::UNEXPECTED,
                (string) ($decoded['message'] ?? 'The catalogue could not be read.'),
                is_array($decoded['evidence'] ?? null) ? $decoded['evidence'] : [],
            );
        }

        $symbols = array_values(array_filter(
            (array) ($decoded['symbols'] ?? []),
            static fn ($symbol): bool => is_string($symbol) && $symbol !== '',
        ));

        $evidence = is_array($decoded['evidence'] ?? null) ? $decoded['evidence'] : [];
        $evidence['requested_url'] = $url;

        return ['symbols' => $symbols, 'evidence' => $evidence];
    }

    /**
     * The environment the child needs, which PHP-FPM's is not.
     *
     * PLAYWRIGHT_BROWSERS_PATH in particular is usually set in the deploy
     * user's profile and absent under the web server, which is how a browser
     * that works from a terminal fails from the dashboard.
     *
     * @return array<string, string>
     */
    private function childEnvironment(): array
    {
        $environment = [];

        $browsers = config('market_indexes.browser.browsers_path');

        if (is_string($browsers) && $browsers !== '') {
            $environment['PLAYWRIGHT_BROWSERS_PATH'] = $browsers;
        }

        $chromium = config('market_indexes.browser.chromium_path');

        if (is_string($chromium) && $chromium !== '') {
            $environment['BROWSER_AUTH_CHROMIUM_PATH'] = $chromium;
        }

        return $environment;
    }

    private function describeCrash(Process $process): string
    {
        $stderr = trim($process->getErrorOutput());

        if (str_contains($stderr, 'not found') && str_contains($stderr, 'node')) {
            return 'Node could not be started. Set MARKET_INDEX_NODE_BINARY to an absolute path to node.';
        }

        if (str_contains($stderr, 'Cannot find package') || str_contains($stderr, 'ERR_MODULE_NOT_FOUND')) {
            return 'Playwright is not installed for the catalogue reader. Run "npm install" in apps/api/resources/browser.';
        }

        return $stderr === ''
            ? 'The catalogue reader produced no output at all.'
            : 'The catalogue reader failed before it could report: '.$stderr;
    }

    /**
     * The signed-in profile to read through, or null to read anonymously.
     */
    private function profileDir(): ?string
    {
        $dir = config('market_indexes.browser.profile_dir');

        return is_string($dir) && trim($dir) !== '' ? trim($dir) : null;
    }

    private function scriptPath(): string
    {
        return resource_path('browser/index-constituents.mjs');
    }
}
