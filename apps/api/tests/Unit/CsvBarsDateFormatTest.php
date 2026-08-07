<?php

namespace Tests\Unit;

use App\Services\CsvBars;
use PHPUnit\Framework\TestCase;

class CsvBarsDateFormatTest extends TestCase
{
    public function test_read_and_write_normalizes_dates(): void
    {
        $csv = <<<'CSV'
Date,Open,High,Low,Close,Volume
19/08/2025,1810,1815,1790,1800,79046600
2025-08-15,1840,1850,1810,1810,45821800
CSV;
        $tmp = tempnam(sys_get_temp_dir(), 'csv');
        file_put_contents($tmp, $csv);

        $rows = CsvBars::read($tmp);
        $this->assertArrayHasKey('2025-08-19', $rows);
        $this->assertArrayHasKey('2025-08-15', $rows);

        $out = $tmp.'.out';
        CsvBars::write($out, $rows);

        $outLines = file($out, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $this->assertSame('Date,Open,High,Low,Close,Volume', $outLines[0]);
        $this->assertSame('15/08/2025,1840,1850,1810,1810,45821800', $outLines[1]);
        $this->assertSame('19/08/2025,1810,1815,1790,1800,79046600', $outLines[2]);
    }
}
