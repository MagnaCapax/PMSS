<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/runtime.php';

/** File-boundary coverage for scripts/lib/runtime/filesystemHost.php without host probes. */
class RuntimeMeminfoInputSafetyTest extends TestCase
{
    public function testNulRowsCannotSupplyOrOverwriteMemoryCounters(): void
    {
        $path = $this->pmssMakeTempFile('pmss-meminfo-input-');
        foreach ([
            'leading' => "\0MemTotal: 1048576 kB",
            'field name' => "Mem\0Total: 1048576 kB",
            'after colon' => "MemTotal:\0 1048576 kB",
            'before value' => "MemTotal: \0".'1048576 kB',
            'inside value' => "MemTotal: 1048\0".'576 kB',
            'after value' => "MemTotal: 1048576\0 kB",
            'before unit' => "MemTotal: 1048576 \0kB",
            'inside unit' => "MemTotal: 1048576 k\0B",
            'trailing' => "MemTotal: 1048576 kB\0",
            'after extra whitespace' => "MemTotal: 1048576 kB \t\0\t",
        ] as $label => $row) {
            foreach ([
                [$row, [], 0],
                ["MemTotal: 2048 kB\n".$row, ['MemTotal' => 2048], 2],
                [$row."\nMemTotal: 4096 kB", ['MemTotal' => 4096], 4],
            ] as [$rows, $expected, $mib]) {
                // Corrupt rows cannot discard valid neighbours or change duplicate precedence.
                $this->pmssWriteFile($path, "MemFree: 512 kB\n".$rows."\nHugePages_Total: 0\n");
                $this->assertSame(['MemFree' => 512] + $expected + ['HugePages_Total' => 0],
                    \pmssProcMeminfoFieldsRead($path), $label);
                $this->assertSame($mib, \pmssProcMeminfoTotalMiBRead($path), $label);
            }
        }
    }

    public function testValidRowsKeepWhitespaceZeroesUnitsAndDuplicateOrdering(): void
    {
        $path = $this->pmssMakeTempFile('pmss-meminfo-valid-');
        foreach ([
            ["MemTotal:\t0002048 kB\r\nMemFree: 0 kB\r\n", ['MemTotal' => 2048, 'MemFree' => 0], 2],
            ["MemFree: 0\nMemTotal: 2048\nMemTotal: 4096 kB\n", ['MemFree' => 0, 'MemTotal' => 4096], 4],
            ["MemTotal: 2048 kB\nMemTotal: ".PHP_INT_MAX."0 kB\n", ['MemTotal' => 2048], 2],
            ["MemTotal: 0 kB\nHugePages_Total: 0\n", ['MemTotal' => 0, 'HugePages_Total' => 0], 0],
            ["invalid\nMemTotal: nope\nMemFree: 512 kB\n", ['MemFree' => 512], 0],
            ['', [], 0],
        ] as [$raw, $expected, $mib]) {
            // Only private fixture files are read; the kernel and system commands stay untouched.
            $this->pmssWriteFile($path, $raw);
            $this->assertSame($expected, \pmssProcMeminfoFieldsRead($path));
            $this->assertSame($mib, \pmssProcMeminfoTotalMiBRead($path));
        }
    }
}
