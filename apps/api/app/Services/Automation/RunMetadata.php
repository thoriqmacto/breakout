<?php

namespace App\Services\Automation;

/**
 * A per-run scratchpad the executed command writes structured facts into.
 *
 * Artisan output is a stream of prose. "17 of 412 tickers failed" needs to be
 * queryable and rendered as a table cell, not grepped out of a log, so a
 * command resolves this from the container and records what it did. The runner
 * resets it before each execution and reads it afterwards.
 *
 * Resolved as a singleton. When a command runs from a terminal instead of the
 * scheduler nothing reads it, and collecting into it is harmless.
 */
class RunMetadata
{
    /**
     * @var array<string, mixed>
     */
    private array $data = [];

    public function reset(): void
    {
        $this->data = [];
    }

    public function set(string $key, mixed $value): void
    {
        $this->data[$key] = $value;
    }

    /**
     * @param  array<string, mixed>  $values
     */
    public function merge(array $values): void
    {
        $this->data = array_replace($this->data, $values);
    }

    /**
     * Append to a list-valued key, e.g. per-ticker failures.
     */
    public function push(string $key, mixed $value): void
    {
        $existing = $this->data[$key] ?? [];

        if (! is_array($existing)) {
            $existing = [$existing];
        }

        $existing[] = $value;
        $this->data[$key] = $existing;
    }

    /**
     * @return array<string, mixed>
     */
    public function all(): array
    {
        return $this->data;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->data[$key] ?? $default;
    }
}
