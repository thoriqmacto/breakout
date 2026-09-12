<?php

namespace App\Services\Indexes;

/**
 * What one membership sync did, or refused to do.
 *
 * A refusal is a result rather than an exception because it is an ordinary
 * outcome with information in it: the caller wants to report "63 of 70 read,
 * refused because 18 members would have been dropped" to a person, and an
 * exception carrying a sentence is a worse shape for that than a value with
 * the numbers still attached.
 */
final class IndexSyncResult
{
    /**
     * @param  array<int, string>  $joined  symbols that were not current before
     * @param  array<int, string>  $removed  symbols that were current and are not in the new list
     * @param  array<int, string>  $rejected  inputs that did not look like a ticker
     */
    private function __construct(
        public readonly string $code,
        public readonly string $source,
        public readonly string $effectiveOn,
        public readonly bool $accepted,
        public readonly ?string $refusedReason,
        public readonly int $received,
        public readonly int $previousCount,
        public readonly int $memberCount,
        public readonly array $joined,
        public readonly array $removed,
        public readonly int $unchanged,
        public readonly array $rejected,
    ) {}

    /**
     * @param  array<int, string>  $joined
     * @param  array<int, string>  $removed
     * @param  array<int, string>  $rejected
     */
    public static function applied(
        string $code,
        string $source,
        string $effectiveOn,
        int $received,
        int $previousCount,
        int $memberCount,
        array $joined,
        array $removed,
        int $unchanged,
        array $rejected,
    ): self {
        return new self(
            $code, $source, $effectiveOn, true, null,
            $received, $previousCount, $memberCount,
            $joined, $removed, $unchanged, $rejected,
        );
    }

    /**
     * @param  array<int, string>  $rejected
     */
    public static function refused(
        string $code,
        string $source,
        string $effectiveOn,
        string $reason,
        int $received,
        int $previousCount,
        array $rejected = [],
    ): self {
        return new self(
            $code, $source, $effectiveOn, false, $reason,
            $received, $previousCount, $previousCount,
            [], [], 0, $rejected,
        );
    }

    public function changed(): bool
    {
        return $this->joined !== [] || $this->removed !== [];
    }

    public function summary(): string
    {
        if (! $this->accepted) {
            return sprintf('%s not updated: %s', $this->code, (string) $this->refusedReason);
        }

        if (! $this->changed()) {
            return sprintf('%s unchanged at %d members.', $this->code, $this->memberCount);
        }

        return sprintf(
            '%s now holds %d members: %d joined (%s), %d left (%s).',
            $this->code,
            $this->memberCount,
            count($this->joined),
            $this->joined === [] ? 'none' : implode(', ', $this->joined),
            count($this->removed),
            $this->removed === [] ? 'none' : implode(', ', $this->removed),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'index' => $this->code,
            'source' => $this->source,
            'effective_on' => $this->effectiveOn,
            'accepted' => $this->accepted,
            'refused_reason' => $this->refusedReason,
            'received' => $this->received,
            'previous_count' => $this->previousCount,
            'member_count' => $this->memberCount,
            'joined' => $this->joined,
            'removed' => $this->removed,
            'unchanged' => $this->unchanged,
            'rejected' => $this->rejected,
        ];
    }
}
