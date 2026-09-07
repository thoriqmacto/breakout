<?php

namespace App\Console\Commands;

use App\Services\Stockbit\BrowserTokenExtractor;
use Illuminate\Console\Command;

/**
 * Report the login form's real controls, so the selectors stop being guesses.
 *
 * SELECTOR_NOT_FOUND is the only failure in this feature whose answer is not
 * on this server: it depends on markup the portal owns and changes without
 * telling anyone. Reading the page with curl does not help either, because a
 * login page is usually drawn by JavaScript and its HTML arrives empty.
 *
 *     php artisan browser:form
 *
 * Opens BROWSER_AUTH_LOGIN_URL in the same browser a login would use, lists
 * every field and button on it, and proposes the three selector values. It
 * submits nothing and takes no credentials.
 */
class BrowserFormCommand extends Command
{
    protected $signature = 'browser:form {--json : Machine-readable output}';

    protected $description = 'Inspect the configured login form and propose selector values.';

    public function handle(BrowserTokenExtractor $extractor): int
    {
        if (! $extractor->enabled()) {
            $this->error('Headless login is not configured: set BROWSER_AUTH_ENABLED=true and BROWSER_AUTH_LOGIN_URL.');

            return self::FAILURE;
        }

        $probe = $extractor->probePath('form-probe.mjs');

        if (! is_file($probe)) {
            $this->error(sprintf('The form probe is missing at %s.', $probe));

            return self::FAILURE;
        }

        $process = $extractor->runProbe('form-probe.mjs');

        if (! $process->isSuccessful()) {
            $stderr = trim($process->getErrorOutput());

            $this->error($stderr === ''
                ? sprintf('The probe exited with code %s.', (string) $process->getExitCode())
                : mb_substr((string) preg_replace('/\s+/', ' ', $stderr), 0, 300));

            return self::FAILURE;
        }

        $report = json_decode($process->getOutput(), true);

        if (! is_array($report)) {
            $this->error('The probe returned something this could not read.');

            return self::FAILURE;
        }

        if ($this->option('json')) {
            $this->line((string) json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->render($report);

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $report
     */
    private function render(array $report): void
    {
        // The URL after navigation, not the one configured: a login page that
        // redirects to an SSO host is a different page with different markup,
        // and that difference is invisible from the .env alone.
        $this->info(sprintf('%s', (string) ($report['url'] ?? '')));
        $this->line(sprintf('<fg=gray>%s</>', (string) ($report['title'] ?? '')));
        $this->newLine();

        $fields = is_array($report['fields'] ?? null) ? $report['fields'] : [];
        $controls = is_array($report['controls'] ?? null) ? $report['controls'] : [];

        if ($fields === [] && $controls === []) {
            $this->warn(
                'The page has no fields or buttons. It may render its form only after a '
                .'redirect, behind a cookie banner, or for a browser it recognises.',
            );

            return;
        }

        $this->line('<options=bold>Fields</>');

        foreach ($fields as $field) {
            $this->line(sprintf(
                '  %-28s %s',
                (string) ($field['type'] ?? ''),
                $this->describe($field),
            ));
        }

        $this->newLine();
        $this->line('<options=bold>Buttons</>');

        foreach ($controls as $control) {
            $this->line(sprintf(
                '  %-28s %s',
                mb_substr((string) ($control['text'] ?? ''), 0, 28),
                $this->describe($control),
            ));
        }

        $this->newLine();

        $suggestion = is_array($report['suggestion'] ?? null) ? $report['suggestion'] : [];

        $this->line('<options=bold>Suggested .env</>');
        $this->newLine();

        foreach ([
            'BROWSER_AUTH_USERNAME_SELECTOR' => $suggestion['username'] ?? null,
            'BROWSER_AUTH_PASSWORD_SELECTOR' => $suggestion['password'] ?? null,
            'BROWSER_AUTH_SUBMIT_SELECTOR' => $suggestion['submit'] ?? null,
        ] as $name => $value) {
            // Single-quoted, always. A .env value is only literal inside
            // quotes: unquoted, a leading # makes the whole line a comment and
            // the variable silently arrives empty.
            $this->line(is_string($value) && $value !== ''
                ? sprintf("  %s='%s'", $name, $value)
                : sprintf('  <fg=red>%s= (nothing on the page matched)</>', $name));
        }

        $this->newLine();
        $this->line(
            '<fg=gray>Proposed from the attributes above, not verified by logging in. '
            .'Check they name the fields you would use, then set them and try the '
            .'dashboard again.</>',
        );
    }

    /**
     * @param  array<string, mixed>  $element
     */
    private function describe(array $element): string
    {
        $parts = [];

        foreach (['name', 'id', 'testId', 'autocomplete', 'placeholder', 'ariaLabel'] as $attribute) {
            $value = $element[$attribute] ?? '';

            if (is_string($value) && $value !== '') {
                $parts[] = sprintf('%s="%s"', $attribute, $value);
            }
        }

        if (($element['visible'] ?? true) === false) {
            $parts[] = '<fg=gray>hidden</>';
        }

        return $parts === [] ? '<fg=gray>no distinguishing attributes</>' : implode('  ', $parts);
    }
}
