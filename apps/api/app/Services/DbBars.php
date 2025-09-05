<?php
namespace App\Services;

use Illuminate\Support\Facades\DB;

class DbBars
{
    /**
     * @var array<int, array<string, mixed>>
     */
    private array $batch = [];

    private int $inserted = 0;

    public function __construct(
        private int $chunkSize,
        private bool $dryRun = false,
    ) {
    }

    /**
     * Add a row to the batch and flush if the chunk size is reached.
     *
     * @param array<string, mixed> $row
     */
    public function add(array $row): void
    {
        $this->batch[] = $row;
        if (count($this->batch) >= $this->chunkSize) {
            $this->flush();
        }
    }

    /**
     * Flush the current batch to the database.
     */
    public function flush(): void
    {
        $count = count($this->batch);
        if ($count === 0) {
            return;
        }

        if (!$this->dryRun) {
            DB::table('price_bars')->upsert(
                $this->batch,
                ['asset_id', 'date'],
                ['open', 'high', 'low', 'close', 'volume', 'updated_at']
            );
        }

        $this->inserted += $count;
        $this->batch = [];
    }

    public function inserted(): int
    {
        return $this->inserted;
    }
}

