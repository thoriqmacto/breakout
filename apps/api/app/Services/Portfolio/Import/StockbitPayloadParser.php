<?php

namespace App\Services\Portfolio\Import;

use Illuminate\Support\Carbon;
use InvalidArgumentException;
use Throwable;

/**
 * Turns a pasted Stockbit API response into plain, validated arrays.
 *
 * Parsing only. Nothing here touches the database, resolves an asset, or
 * decides whether a row should be imported -- that is the importer's job, and
 * keeping the split means the shape-handling can be tested against fixtures
 * without a schema.
 *
 * Two payloads are recognised and told apart by their own structure rather
 * than by anything the caller claims:
 *
 *   history  -> data.history[].history_list[]   a ledger of executions
 *   snapshot -> data.results[]                  the current holdings
 */
class StockbitPayloadParser
{
    public const TYPE_HISTORY = 'history';

    public const TYPE_SNAPSHOT = 'snapshot';

    /**
     * Commands that map onto something in the Breakout ledger. Anything else
     * is surfaced to the user rather than quietly dropped.
     */
    public const COMMAND_BUY = 'BUY';

    public const COMMAND_SELL = 'SELL';

    public const COMMAND_DIV = 'DIV';

    public const SUPPORTED_COMMANDS = [self::COMMAND_BUY, self::COMMAND_SELL, self::COMMAND_DIV];

    /**
     * Status values that mean the trade actually happened. Compared
     * case-insensitively: the payload mixes "MATCH" with "Success".
     */
    private const COMPLETED_STATUSES = ['match', 'matched', 'success', 'done', 'completed', 'settled'];

    /**
     * Decode a pasted string into an array, with a message a person can act on.
     *
     * @return array<string, mixed>
     *
     * @throws InvalidArgumentException
     */
    public function decode(string $raw): array
    {
        $trimmed = trim($raw);

        if ($trimmed === '') {
            throw new InvalidArgumentException('Paste a Stockbit API response first.');
        }

        try {
            $decoded = json_decode($trimmed, true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable $exception) {
            throw new InvalidArgumentException('That is not valid JSON: '.$exception->getMessage());
        }

        if (! is_array($decoded)) {
            throw new InvalidArgumentException('The payload must be a JSON object.');
        }

        return $decoded;
    }

    /**
     * Work out which Stockbit response this is from its own shape.
     *
     * @param  array<string, mixed>  $payload
     *
     * @throws InvalidArgumentException
     */
    public function detectType(array $payload): string
    {
        $data = $payload['data'] ?? null;

        if (is_array($data)) {
            if (isset($data['history']) && is_array($data['history'])) {
                return self::TYPE_HISTORY;
            }

            if (isset($data['results']) && is_array($data['results'])) {
                return self::TYPE_SNAPSHOT;
            }
        }

        // Tolerate a payload pasted without its "data" envelope.
        if (isset($payload['history']) && is_array($payload['history'])) {
            return self::TYPE_HISTORY;
        }

        if (isset($payload['results']) && is_array($payload['results'])) {
            return self::TYPE_SNAPSHOT;
        }

        throw new InvalidArgumentException(
            'Unrecognised Stockbit payload: expected a transaction history (data.history) or a portfolio snapshot (data.results).'
        );
    }

    /**
     * Flatten the month-grouped history into one list of normalised rows,
     * ordered by execution time.
     *
     * Every row is returned, including the ones that will not be imported, so
     * the preview can account for each line the user pasted.
     *
     * @param  array<string, mixed>  $payload
     * @return array<int, array<string, mixed>>
     */
    public function parseHistory(array $payload): array
    {
        $groups = $payload['data']['history'] ?? $payload['history'] ?? [];
        $rows = [];
        $ordinal = 0;

        foreach ($groups as $group) {
            $list = is_array($group) ? ($group['history_list'] ?? null) : null;

            if (! is_array($list)) {
                continue;
            }

            foreach ($list as $entry) {
                if (! is_array($entry)) {
                    continue;
                }

                $rows[] = $this->normalizeHistoryRow($entry, $ordinal++);
            }
        }

        // Stockbit lists newest first. The calculator needs oldest first, and
        // the ordinal breaks ties so two fills at the same second keep the
        // order the broker reported them in.
        usort($rows, static function (array $a, array $b): int {
            return [$a['executed_at'], $a['ordinal']] <=> [$b['executed_at'], $b['ordinal']];
        });

        return $rows;
    }

    /**
     * @param  array<string, mixed>  $entry
     * @return array<string, mixed>
     */
    private function normalizeHistoryRow(array $entry, int $ordinal): array
    {
        $command = strtoupper(trim((string) ($entry['command'] ?? '')));
        $status = trim((string) ($entry['status'] ?? ''));
        $symbol = strtoupper(trim((string) ($entry['symbol'] ?? '')));

        $shares = $this->number($entry['shares'] ?? null);
        $price = $this->number($entry['price'] ?? null);
        $amount = $this->number($entry['amount'] ?? null);
        $fee = $this->number($entry['fee'] ?? null) ?? 0.0;
        $netAmount = $this->number($entry['netamount'] ?? null);

        return [
            'ordinal' => $ordinal,
            'external_id' => $this->externalId($entry),
            'command' => $command,
            'symbol' => $symbol,
            'status' => $status,
            'completed' => $this->isCompleted($status),
            'supported' => in_array($command, self::SUPPORTED_COMMANDS, true),
            'shares' => $shares,
            'lot' => $this->number($entry['lot'] ?? null),
            'price' => $price,
            'amount' => $amount,
            'fee' => $fee,
            'net_amount' => $netAmount,
            'currency' => strtoupper(trim((string) ($entry['currency'] ?? ''))) ?: null,
            'dividend_per_share' => $this->number($entry['dividend_per_share'] ?? null),
            'dividend_type' => trim((string) ($entry['dividend_type'] ?? '')) ?: null,
            'executed_at' => $this->executedAt($entry['date'] ?? null, $entry['time'] ?? null),
            'raw_date' => trim((string) ($entry['date'] ?? '')),
            'raw_time' => trim((string) ($entry['time'] ?? '')),
        ];
    }

    /**
     * Parse the current-holdings payload.
     *
     * @param  array<string, mixed>  $payload
     * @return array{
     *     summary: array<string, mixed>,
     *     positions: array<int, array<string, mixed>>
     * }
     */
    public function parseSnapshot(array $payload): array
    {
        $data = $payload['data'] ?? $payload;
        $results = $data['results'] ?? [];
        $summary = $data['summary'] ?? [];

        $positions = [];

        foreach (is_array($results) ? $results : [] as $result) {
            if (! is_array($result)) {
                continue;
            }

            $symbol = strtoupper(trim((string) ($result['symbol'] ?? '')));

            if ($symbol === '') {
                continue;
            }

            $positions[] = [
                'symbol' => $symbol,
                // qty.balance.share, never stock_on_hand: the latter can read
                // zero while the balance is non-zero, and treating it as the
                // holding would silently wipe a real position from the compare.
                'shares' => $this->number($result['qty']['balance']['share'] ?? null) ?? 0.0,
                'lot' => $this->number($result['qty']['balance']['lot'] ?? null),
                'average_price' => $this->number($result['price']['average']['price'] ?? null),
                'latest_price' => $this->number($result['price']['latest'] ?? null),
                'amount_invested' => $this->number($result['asset']['amount_invested'] ?? null),
                'market_value' => $this->number($result['asset']['unrealised']['market_value'] ?? null),
                'unrealized_pl' => $this->number($result['asset']['unrealised']['profit_loss'] ?? null),
            ];
        }

        return [
            'summary' => [
                'cash_balance' => $this->number($summary['trading']['balance'] ?? null),
                'invested' => $this->number($summary['amount']['invested'] ?? null),
                'net_profit_loss' => $this->number($summary['profit_loss']['net'] ?? null),
                'equity' => $this->number($summary['equity'] ?? null),
            ],
            'positions' => $positions,
        ];
    }

    /**
     * Combine Stockbit's separate date and time fields.
     *
     * The date arrives as "08 Jun 2026". A missing or unparseable time falls
     * back to midnight, which matches how a manual date-only entry is stored.
     */
    private function executedAt(mixed $date, mixed $time): ?string
    {
        $date = trim((string) $date);

        if ($date === '') {
            return null;
        }

        $time = trim((string) $time);
        $candidate = $time !== '' ? $date.' '.$time : $date;

        $hasTime = $time !== '';

        foreach (['d M Y H:i:s', 'd M Y H:i', 'd M Y', 'Y-m-d H:i:s', 'Y-m-d H:i', 'Y-m-d'] as $format) {
            try {
                $parsed = Carbon::createFromFormat($format, $candidate);

                if ($parsed !== false) {
                    // createFromFormat fills unspecified components from the
                    // current clock, so a date-only row would otherwise be
                    // stamped with whatever time the import happened to run
                    // at -- which is both wrong and enough to reorder two
                    // fills on the same day.
                    return ($hasTime ? $parsed : $parsed->startOfDay())->toDateTimeString();
                }
            } catch (Throwable) {
                // Try the next format.
            }
        }

        try {
            $parsed = Carbon::parse($candidate);

            return ($hasTime ? $parsed : $parsed->startOfDay())->toDateTimeString();
        } catch (Throwable) {
            return null;
        }
    }

    private function isCompleted(string $status): bool
    {
        return in_array(strtolower(trim($status)), self::COMPLETED_STATUSES, true);
    }

    /**
     * @param  array<string, mixed>  $entry
     */
    private function externalId(array $entry): ?string
    {
        foreach (['id', 'transaction_id', 'order_id'] as $key) {
            $value = $entry[$key] ?? null;

            if (is_string($value) || is_int($value)) {
                $id = trim((string) $value);

                if ($id !== '') {
                    return $id;
                }
            }
        }

        return null;
    }

    private function number(mixed $value): ?float
    {
        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }

        if (is_string($value)) {
            // Tolerate thousands separators, which the app itself never emits
            // but a hand-edited paste sometimes carries.
            $cleaned = str_replace([',', ' '], '', trim($value));

            if ($cleaned !== '' && is_numeric($cleaned)) {
                return (float) $cleaned;
            }
        }

        return null;
    }
}
