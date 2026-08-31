<?php

namespace App\Services;

use App\Models\TradingCalendarDay;
use App\Models\TradingDay;
use Carbon\CarbonPeriod;
use Carbon\Exceptions\InvalidFormatException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use InvalidArgumentException;
use JsonException;
use RuntimeException;

class TradingCalendarBuilder
{
    private const SOURCE = '^JKSE';

    private const CHUNK_SIZE = 1000;

    public function __construct(
        private readonly TradingDay $tradingDay,
        private readonly TradingCalendarDay $calendarDay,
    ) {}

    public function build(string $from, string $to, array $holidayReasons = []): int
    {
        $startDate = Carbon::parse($from)->startOfDay();
        $endDate = Carbon::parse($to)->startOfDay();

        if ($endDate->lessThan($startDate)) {
            throw new InvalidArgumentException('The end date must be greater than or equal to the start date.');
        }

        $tradingDates = $this->tradingDay->newQuery()
            ->whereDate('date', '>=', $startDate->toDateString())
            ->whereDate('date', '<=', $endDate->toDateString())
            ->pluck('date')
            ->map(static fn ($date) => Carbon::parse($date)->toDateString())
            ->all();

        $tradingLookup = array_fill_keys($tradingDates, true);
        $holidayLookup = [];

        foreach ($holidayReasons as $date => $reason) {
            $holidayLookup[$date] = Str::limit($reason, 255, '');
        }

        $period = CarbonPeriod::create($startDate, $endDate);
        $now = Carbon::now();
        $records = [];

        foreach ($period as $current) {
            $dateString = $current->toDateString();
            $isWeekend = $current->dayOfWeekIso >= 6;
            $isTradingDay = ! $isWeekend && isset($tradingLookup[$dateString]);
            $isHoliday = ! $isWeekend && ! $isTradingDay;
            $holidayReason = $holidayLookup[$dateString] ?? ($isHoliday ? 'Bourse Holiday' : null);

            $records[] = [
                'date' => $dateString,
                'is_trading_day' => $isTradingDay,
                'is_weekend' => $isWeekend,
                'is_holiday' => $isHoliday,
                'holiday_reason' => $holidayReason,
                'source' => self::SOURCE,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        if ($records === []) {
            return 0;
        }

        Collection::make($records)
            ->chunk(self::CHUNK_SIZE)
            ->each(function (Collection $chunk): void {
                $this->calendarDay->newQuery()->upsert(
                    $chunk->all(),
                    ['date'],
                    ['is_trading_day', 'is_weekend', 'is_holiday', 'holiday_reason', 'source', 'updated_at']
                );
            });

        return count($records);
    }

    public function loadHolidayReasons(?string $path): array
    {
        if ($path === null) {
            return [];
        }

        $resolvedPath = $this->resolvePath($path);

        if ($resolvedPath === null) {
            throw new InvalidArgumentException(sprintf('Holiday file not found: %s', $path));
        }

        $extension = strtolower(pathinfo($resolvedPath, PATHINFO_EXTENSION));

        return match ($extension) {
            'json' => $this->parseJsonHolidayReasons($resolvedPath),
            'csv' => $this->parseCsvHolidayReasons($resolvedPath),
            default => throw new InvalidArgumentException(sprintf('Unsupported holiday file format: %s', $extension)),
        };
    }

    private function resolvePath(string $path): ?string
    {
        if (is_file($path)) {
            return $path;
        }

        $candidate = base_path($path);

        return is_file($candidate) ? $candidate : null;
    }

    private function parseJsonHolidayReasons(string $path): array
    {
        $contents = file_get_contents($path);

        if ($contents === false) {
            throw new RuntimeException(sprintf('Unable to read holiday JSON file: %s', $path));
        }

        try {
            $data = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new InvalidArgumentException('Holiday JSON is invalid: '.$exception->getMessage(), 0, $exception);
        }

        if (! is_array($data)) {
            throw new InvalidArgumentException('Holiday JSON must decode to an associative array.');
        }

        return $this->normalizeHolidayMap($data);
    }

    private function parseCsvHolidayReasons(string $path): array
    {
        $handle = fopen($path, 'rb');

        if ($handle === false) {
            throw new RuntimeException(sprintf('Unable to open holiday CSV file: %s', $path));
        }

        $rows = [];

        while (($data = fgetcsv($handle)) !== false) {
            if ($data === [null] || $data === false) {
                continue;
            }

            $date = $data[0] ?? null;
            $reason = $data[1] ?? null;

            if ($date === null || $reason === null) {
                continue;
            }

            $normalizedDate = trim((string) $date);
            $normalizedReason = trim((string) $reason);

            if ($normalizedDate === '') {
                continue;
            }

            if (strtolower($normalizedDate) === 'date' && ($normalizedReason === '' || strtolower($normalizedReason) === 'reason')) {
                continue;
            }

            $rows[$normalizedDate] = $normalizedReason;
        }

        fclose($handle);

        return $this->normalizeHolidayMap($rows);
    }

    private function normalizeHolidayMap(array $entries): array
    {
        $normalized = [];

        foreach ($entries as $date => $reason) {
            if (! is_string($date) || ! is_string($reason)) {
                continue;
            }

            $date = trim($date);
            $reason = trim($reason);

            if ($date === '' || $reason === '') {
                continue;
            }

            try {
                Carbon::createFromFormat('Y-m-d', $date);
            } catch (InvalidFormatException) {
                continue;
            }

            $normalized[$date] = Str::limit($reason, 255, '');
        }

        return $normalized;
    }
}
