<?php

namespace App\Services\Automation;

use InvalidArgumentException;

/**
 * The allowlist that stands between the dashboard and Artisan.
 *
 * A scheduled task never carries a command *line*. It carries the name of a
 * command declared in config/automation.php plus a structured
 * {arguments, options} map, and this class is the only thing that decides
 * whether that pair is acceptable. The generated preview shown in the UI is
 * built from the same structure, purely for reading -- it is never parsed
 * back, and nothing in this system passes a string to a shell.
 */
class CommandRegistry
{
    /**
     * Parameter names that may never be scheduled, whatever a command's
     * specification says.
     *
     * The Stockbit bearer lives in the encrypted token store and is resolved
     * at execution time. Persisting one as a task parameter would put a
     * credential in a table the dashboard reads and writes, so `stockbit:scrape`
     * is allowlisted *without* --token and this is the backstop that keeps a
     * future edit to the config from quietly reintroducing it.
     */
    private const FORBIDDEN_PARAMETERS = [
        'token', 'bearer', 'secret', 'password', 'passwd', 'api-key', 'apikey',
        'authorization', 'auth', 'credential', 'credentials', 'key',
    ];

    /**
     * Every allowed command, keyed by Artisan name.
     *
     * @return array<string, array<string, mixed>>
     */
    public function all(): array
    {
        $commands = config('automation.commands', []);

        return is_array($commands) ? $commands : [];
    }

    public function has(string $command): bool
    {
        return array_key_exists($command, $this->all());
    }

    /**
     * @return array<string, mixed>|null
     */
    public function definition(string $command): ?array
    {
        return $this->all()[$command] ?? null;
    }

    /**
     * @return array<int, string>
     */
    public function names(): array
    {
        return array_keys($this->all());
    }

    /**
     * Whether this command performs a bulk Stockbit fetch and must therefore
     * take the shared Stockbit lock and pass a token preflight.
     */
    public function isStockbitBulk(string $command): bool
    {
        return (bool) ($this->definition($command)['stockbit_bulk'] ?? false);
    }

    /**
     * The catalogue the dashboard renders its command picker from. Contains no
     * secrets and no executable content -- names, labels and parameter shapes.
     *
     * @return array<int, array<string, mixed>>
     */
    public function catalog(): array
    {
        $catalog = [];

        foreach ($this->all() as $name => $definition) {
            $catalog[] = [
                'command' => $name,
                'label' => $definition['label'] ?? $name,
                'description' => $definition['description'] ?? null,
                'stockbit_bulk' => (bool) ($definition['stockbit_bulk'] ?? false),
                'arguments' => $this->describeParameters($definition['arguments'] ?? []),
                'options' => $this->describeParameters($definition['options'] ?? []),
            ];
        }

        return $catalog;
    }

    /**
     * Validate and normalise a {arguments, options} payload for a command.
     *
     * Returns the canonical form to store. Throws with a human-readable reason
     * on anything unrecognised -- an unknown command, an unknown parameter, a
     * value of the wrong shape, or a forbidden name.
     *
     * @param  array<string, mixed>  $parameters
     * @return array{arguments: array<string, mixed>, options: array<string, mixed>}
     *
     * @throws InvalidArgumentException
     */
    public function validate(string $command, array $parameters): array
    {
        $definition = $this->definition($command);

        if ($definition === null) {
            throw new InvalidArgumentException(sprintf(
                'The command "%s" is not on the automation allowlist.',
                $command,
            ));
        }

        $arguments = $parameters['arguments'] ?? [];
        $options = $parameters['options'] ?? [];

        if (! is_array($arguments) || ! is_array($options)) {
            throw new InvalidArgumentException('Parameters must be given as {"arguments": {...}, "options": {...}}.');
        }

        return [
            'arguments' => $this->validateGroup($command, 'argument', $definition['arguments'] ?? [], $arguments),
            'options' => $this->validateGroup($command, 'option', $definition['options'] ?? [], $options),
        ];
    }

    /**
     * Build the argv map Artisan::call() takes: bare names for arguments,
     * "--name" keys for options.
     *
     * Values are already validated, and each is handed over as a discrete
     * array element. Nothing is quoted, escaped, joined or interpolated,
     * because nothing is ever handed to a shell.
     *
     * @param  array<string, mixed>  $parameters
     * @return array<string, mixed>
     */
    public function toArtisanParameters(string $command, array $parameters): array
    {
        $validated = $this->validate($command, $parameters);
        $definition = $this->definition($command) ?? [];

        $call = [];

        foreach ($validated['arguments'] as $name => $value) {
            $call[$name] = $value;
        }

        foreach ($validated['options'] as $name => $value) {
            $spec = $definition['options'][$name] ?? [];
            $type = $spec['type'] ?? 'string';

            if ($type === 'boolean') {
                // Symfony treats the presence of a flag as true; passing
                // false would still set it, so an unwanted flag is omitted.
                if ($value === true) {
                    $call['--'.$name] = true;
                }

                continue;
            }

            $call['--'.$name] = $value;
        }

        return $call;
    }

    /**
     * A readable rendering of the command for the UI.
     *
     * Presentation only. It is never executed, never stored as the source of
     * truth, and never parsed back into parameters.
     *
     * @param  array<string, mixed>  $parameters
     */
    public function preview(string $command, array $parameters): string
    {
        try {
            $validated = $this->validate($command, $parameters);
        } catch (InvalidArgumentException) {
            return 'php artisan '.$command;
        }

        $parts = ['php', 'artisan', $command];

        foreach ($validated['arguments'] as $value) {
            foreach ($this->flatten($value) as $item) {
                $parts[] = $item;
            }
        }

        $definition = $this->definition($command) ?? [];

        foreach ($validated['options'] as $name => $value) {
            $type = $definition['options'][$name]['type'] ?? 'string';

            if ($type === 'boolean') {
                if ($value === true) {
                    $parts[] = '--'.$name;
                }

                continue;
            }

            foreach ($this->flatten($value) as $item) {
                $parts[] = '--'.$name.'='.$item;
            }
        }

        return implode(' ', $parts);
    }

    /**
     * @param  array<string, mixed>  $specs
     * @param  array<string, mixed>  $given
     * @return array<string, mixed>
     */
    private function validateGroup(string $command, string $kind, array $specs, array $given): array
    {
        $normalized = [];

        foreach ($given as $name => $value) {
            if (! is_string($name)) {
                throw new InvalidArgumentException(sprintf('%s names must be strings.', ucfirst($kind)));
            }

            $this->assertNotForbidden($name);

            if (! array_key_exists($name, $specs)) {
                throw new InvalidArgumentException(sprintf(
                    'The %s "%s" is not accepted by "%s".',
                    $kind,
                    $name,
                    $command,
                ));
            }

            // An omitted value means "do not pass this parameter", which is
            // how the UI clears a field without deleting the row.
            if ($value === null || $value === '') {
                continue;
            }

            $normalized[$name] = $this->coerce($command, $kind, $name, $specs[$name], $value);
        }

        ksort($normalized);

        return $normalized;
    }

    private function assertNotForbidden(string $name): void
    {
        $needle = strtolower(trim($name));

        foreach (self::FORBIDDEN_PARAMETERS as $forbidden) {
            if ($needle === $forbidden) {
                throw new InvalidArgumentException(sprintf(
                    'The parameter "%s" may never be stored on a scheduled task. '.
                    'Credentials are resolved at execution time from the encrypted store.',
                    $name,
                ));
            }
        }
    }

    /**
     * @param  array<string, mixed>  $spec
     */
    private function coerce(string $command, string $kind, string $name, array $spec, mixed $value): mixed
    {
        $type = $spec['type'] ?? 'string';
        $label = sprintf('%s "%s" of "%s"', $kind, $name, $command);

        return match ($type) {
            'boolean' => $this->coerceBoolean($label, $value),
            'integer' => $this->coerceInteger($label, $spec, $value),
            'date' => $this->coerceDate($label, $value),
            'enum' => $this->coerceEnum($label, $spec, $value),
            'symbol_list' => $this->coerceSymbolList($label, $value),
            default => $this->coerceString($label, $spec, $value),
        };
    }

    private function coerceBoolean(string $label, mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (in_array($value, [1, '1', 'true', 'on', 'yes'], true)) {
            return true;
        }

        if (in_array($value, [0, '0', 'false', 'off', 'no'], true)) {
            return false;
        }

        throw new InvalidArgumentException(sprintf('The %s must be true or false.', $label));
    }

    /**
     * @param  array<string, mixed>  $spec
     */
    private function coerceInteger(string $label, array $spec, mixed $value): int
    {
        if (! is_int($value) && ! (is_string($value) && preg_match('/^-?\d+$/', $value) === 1)) {
            throw new InvalidArgumentException(sprintf('The %s must be a whole number.', $label));
        }

        $number = (int) $value;

        if (isset($spec['min']) && $number < (int) $spec['min']) {
            throw new InvalidArgumentException(sprintf('The %s must be at least %d.', $label, (int) $spec['min']));
        }

        if (isset($spec['max']) && $number > (int) $spec['max']) {
            throw new InvalidArgumentException(sprintf('The %s must not exceed %d.', $label, (int) $spec['max']));
        }

        return $number;
    }

    private function coerceDate(string $label, mixed $value): string
    {
        if (! is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            throw new InvalidArgumentException(sprintf('The %s must be a YYYY-MM-DD date.', $label));
        }

        [$year, $month, $day] = array_map('intval', explode('-', $value));

        if (! checkdate($month, $day, $year)) {
            throw new InvalidArgumentException(sprintf('The %s is not a real calendar date.', $label));
        }

        return $value;
    }

    /**
     * @param  array<string, mixed>  $spec
     */
    private function coerceEnum(string $label, array $spec, mixed $value): string
    {
        $allowed = array_values(array_filter((array) ($spec['values'] ?? []), 'is_string'));

        if (! is_string($value) || ! in_array($value, $allowed, true)) {
            throw new InvalidArgumentException(sprintf(
                'The %s must be one of: %s.',
                $label,
                implode(', ', $allowed) ?: '(nothing is allowed)',
            ));
        }

        return $value;
    }

    /**
     * @return array<int, string>
     */
    private function coerceSymbolList(string $label, mixed $value): array
    {
        $items = is_array($value) ? $value : preg_split('/[\s,]+/', (string) $value, -1, PREG_SPLIT_NO_EMPTY);
        $symbols = [];

        foreach ($items ?: [] as $item) {
            if (! is_string($item) && ! is_numeric($item)) {
                throw new InvalidArgumentException(sprintf('The %s must contain only ticker symbols.', $label));
            }

            $symbol = strtoupper(trim((string) $item));

            if ($symbol === '') {
                continue;
            }

            // Deliberately narrow. Whatever a browser sends can only ever
            // become an alphanumeric ticker, never a path, a flag or a
            // separator.
            //
            // The leading character is constrained separately: "--all" is
            // otherwise a perfectly ordinary string of allowed characters, and
            // Symfony would read it as an option rather than as the argument
            // it was passed as. Real IDX tickers start alphanumeric (BBCA,
            // BBCA-W).
            if (preg_match('/^[A-Z0-9][A-Z0-9.\-]{0,11}$/', $symbol) !== 1) {
                throw new InvalidArgumentException(sprintf(
                    'The %s contains "%s", which is not a valid ticker symbol.',
                    $label,
                    $symbol,
                ));
            }

            $symbols[$symbol] = $symbol;
        }

        if ($symbols === []) {
            throw new InvalidArgumentException(sprintf('The %s must list at least one ticker symbol.', $label));
        }

        $symbols = array_values($symbols);
        sort($symbols);

        if (count($symbols) > 1000) {
            throw new InvalidArgumentException(sprintf('The %s lists more than 1000 symbols.', $label));
        }

        return $symbols;
    }

    /**
     * @param  array<string, mixed>  $spec
     */
    private function coerceString(string $label, array $spec, mixed $value): string
    {
        if (! is_string($value) && ! is_numeric($value)) {
            throw new InvalidArgumentException(sprintf('The %s must be text.', $label));
        }

        $string = trim((string) $value);
        $pattern = $spec['pattern'] ?? '/^[A-Za-z0-9._\/: -]{1,255}$/';

        if (preg_match((string) $pattern, $string) !== 1) {
            throw new InvalidArgumentException(sprintf('The %s has an unacceptable value.', $label));
        }

        return $string;
    }

    /**
     * @return array<int, string>
     */
    private function flatten(mixed $value): array
    {
        if (is_array($value)) {
            return array_map(static fn ($item): string => (string) $item, $value);
        }

        return [(string) $value];
    }

    /**
     * @param  array<string, mixed>  $specs
     * @return array<int, array<string, mixed>>
     */
    private function describeParameters(array $specs): array
    {
        $described = [];

        foreach ($specs as $name => $spec) {
            $described[] = [
                'name' => $name,
                'type' => $spec['type'] ?? 'string',
                'label' => $spec['label'] ?? $name,
                'values' => array_values(array_filter((array) ($spec['values'] ?? []), 'is_string')) ?: null,
            ];
        }

        return $described;
    }
}
