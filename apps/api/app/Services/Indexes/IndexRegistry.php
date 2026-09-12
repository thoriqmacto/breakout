<?php

namespace App\Services\Indexes;

use InvalidArgumentException;

/**
 * The configured indexes, and what a symbol of one is allowed to look like.
 *
 * Every other class here asks this one rather than reading config directly, so
 * "is JII70 something we know about" has a single answer. An index code that
 * arrives over HTTP is checked here before it reaches a query -- the API takes
 * a code from the URL, and an unchecked one is a filter on arbitrary text.
 */
class IndexRegistry
{
    /**
     * @return array<string, array<string, mixed>> keyed by index code
     */
    public function all(): array
    {
        $configured = config('market_indexes.indexes', []);

        if (! is_array($configured)) {
            return [];
        }

        $indexes = [];

        foreach ($configured as $code => $definition) {
            if (! is_array($definition)) {
                continue;
            }

            $indexes[$this->normaliseCode((string) $code)] = $definition;
        }

        return $indexes;
    }

    /**
     * @return array<int, string>
     */
    public function codes(): array
    {
        return array_keys($this->all());
    }

    public function has(string $code): bool
    {
        return array_key_exists($this->normaliseCode($code), $this->all());
    }

    /**
     * @return array<string, mixed>|null
     */
    public function definition(string $code): ?array
    {
        return $this->all()[$this->normaliseCode($code)] ?? null;
    }

    /**
     * @return array<string, mixed>
     *
     * @throws InvalidArgumentException when nothing is configured under that code
     */
    public function require(string $code): array
    {
        $definition = $this->definition($code);

        if ($definition === null) {
            throw new InvalidArgumentException(sprintf(
                'Unknown index "%s". Configured: %s.',
                $code,
                $this->codes() === [] ? 'none' : implode(', ', $this->codes()),
            ));
        }

        return $definition;
    }

    public function defaultCode(): string
    {
        $default = $this->normaliseCode((string) config('market_indexes.default', ''));

        if ($default !== '' && $this->has($default)) {
            return $default;
        }

        return $this->codes()[0] ?? '';
    }

    public function normaliseCode(string $code): string
    {
        return strtoupper(trim($code));
    }

    /**
     * Clean a list of raw symbols into the ones that can be members.
     *
     * Uppercased, trimmed, de-duplicated, order preserved. Anything that does
     * not look like a ticker is dropped rather than stored: a catalogue page
     * carries navigation text and other index names among its links, and one
     * "MORE" written into the table is a badge on a stock that does not exist.
     *
     * @param  array<int, mixed>  $symbols
     * @return array{symbols: array<int, string>, rejected: array<int, string>}
     */
    public function filterSymbols(array $symbols): array
    {
        $pattern = (string) config('market_indexes.symbol_pattern', '/^[A-Z][A-Z0-9]{2,5}$/');
        $denylist = array_map(
            static fn ($value): string => strtoupper((string) $value),
            (array) config('market_indexes.symbol_denylist', []),
        );

        $accepted = [];
        $rejected = [];

        foreach ($symbols as $raw) {
            if (! is_string($raw) && ! is_numeric($raw)) {
                continue;
            }

            $symbol = strtoupper(trim((string) $raw));

            if ($symbol === '' || isset($accepted[$symbol])) {
                continue;
            }

            if (in_array($symbol, $denylist, true) || preg_match($pattern, $symbol) !== 1) {
                $rejected[$symbol] = true;

                continue;
            }

            $accepted[$symbol] = true;
        }

        return [
            'symbols' => array_keys($accepted),
            'rejected' => array_keys($rejected),
        ];
    }
}
