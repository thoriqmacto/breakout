<?php

namespace App\Services\Strategies;

/**
 * The built-in strategies, from one list that everything reads.
 *
 * The list used to live inline in AssetBacktest::handle(), reachable only by
 * reading that method, which is why the dashboard printed "6" as a string
 * literal: there was nothing to ask. The number was right at the time and
 * would have stayed on the screen after it stopped being.
 *
 * Read-only on purpose. These are classes with constructor parameters and a
 * signal() method, not the rules JSON that StrategyRunner executes, so there
 * is nothing here for a person to edit -- changing one means changing the
 * class. The defaults travel with the entry so the page can show what a run
 * actually uses without implying it can be changed from there.
 */
class StrategyCatalogue
{
    /**
     * Every built-in, in the order the catalogue declares them.
     *
     * @return array<int, array<string, mixed>>
     */
    public function all(): array
    {
        return array_values(array_filter(
            (array) config('strategy_catalogue.built_in', []),
            static fn ($entry): bool => is_array($entry) && ($entry['key'] ?? '') !== '',
        ));
    }

    public function count(): int
    {
        return count($this->all());
    }

    /**
     * The map the backtest command resolves --strategy through.
     *
     * Keyed by the CLI value, which is part of the command's contract and is
     * therefore never renamed for tidiness.
     *
     * @return array<string, class-string>
     */
    public function classMap(): array
    {
        $map = [];

        foreach ($this->all() as $entry) {
            $map[(string) $entry['key']] = (string) $entry['class'];
        }

        return $map;
    }

    /**
     * Resolve a --strategy value, accepting the aliases the CLI has always
     * accepted (HLSL for HLSLBreakout), case-insensitively.
     */
    public function find(string $key): ?array
    {
        $needle = strtolower(trim($key));

        foreach ($this->all() as $entry) {
            if (strtolower((string) $entry['key']) === $needle) {
                return $entry;
            }

            foreach ((array) ($entry['aliases'] ?? []) as $alias) {
                if (strtolower((string) $alias) === $needle) {
                    return $entry;
                }
            }
        }

        return null;
    }

    /**
     * The keys a person can pass, aliases included, for an error message that
     * lists what would have worked.
     *
     * @return array<int, string>
     */
    public function keys(): array
    {
        return array_map(static fn (array $entry): string => (string) $entry['key'], $this->all());
    }
}
