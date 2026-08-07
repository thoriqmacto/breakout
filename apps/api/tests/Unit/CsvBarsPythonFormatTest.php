<?php

namespace Tests\Unit;

use App\Services\CsvBars;
use PHPUnit\Framework\TestCase;

class CsvBarsPythonFormatTest extends TestCase
{
    public function test_read_and_write_python_csv(): void
    {
        $csv = <<<'CSV'
Date,Adj Close_DCII.JK,Close_DCII.JK,High_DCII.JK,Low_DCII.JK,Open_DCII.JK,Volume_DCII.JK
2021-01-06,525.0,525.0,525.0,525.0,525.0,1100
CSV;
        $tmp = tempnam(sys_get_temp_dir(), 'csv');
        file_put_contents($tmp, $csv);

        $rows = CsvBars::read($tmp);
        $this->assertArrayHasKey('2021-01-06', $rows);
        $this->assertSame(525.0, $rows['2021-01-06']['open']);
        $this->assertSame(525.0, $rows['2021-01-06']['close']);
        $this->assertSame(1100, $rows['2021-01-06']['volume']);

        $out = $tmp.'.out';
        CsvBars::write($out, $rows);
        $lines = file($out, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $this->assertSame('Date,Open,High,Low,Close,Volume', $lines[0]);
        $this->assertSame('06/01/2021,525,525,525,525,1100', $lines[1]);
    }
}
