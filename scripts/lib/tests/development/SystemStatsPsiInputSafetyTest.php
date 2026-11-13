<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/systemStats.php';
require_once dirname(__DIR__, 2).'/diskIostat.php';

/** Boundary coverage for the shared validator in scripts/lib/systemStats/parse.php. */
class SystemStatsPsiInputSafetyTest extends TestCase
{
    public function testNulCorruptionCannotBeHiddenByValidFields(): void
    {
        $raw = "some avg10=1.23 avg60=4.56 avg300=7.89 total=123\n"
            ."full avg10=0.12 avg60=0.34 avg300=0.567 total=456\n";
        foreach ([
            "\0".$raw,
            $raw."\0",
            str_replace('some ', "some \0 ", $raw),
            str_replace('avg300=0.567', "avg300=0.\0".'567', $raw),
            str_replace('avg10=1.23', "avg10=1.23\0", $raw),
            str_replace('total=123', "total=123 \0", $raw),
            $raw."other payload\0\n",
        ] as $invalid) {
            // Check other fields too: corruption must not become a partial trusted sample.
            foreach (['some', 'full'] as $row) {
                foreach (['avg10', 'avg60', 'avg300', 'total'] as $field) {
                    $this->assertSame(null, \pmssSystemStatsPsiMetricFromRaw($invalid, $row, $field));
                }
            }
            $this->assertSame('na', \pmssSystemStatsPsiFromRaw($invalid));
        }
    }

    public function testReadConsumersKeepTheirUnavailableResultsForCorruptRecords(): void
    {
        $path = $this->pmssMakeTempFile('pmss-psi-input-');
        foreach ([
            "some avg10=0\nfull avg300=9\0\n",
            "some avg10=0 \0\nfull avg300=9\n",
            "some avg10=0\nfull ignored=\0 avg300=9\n",
            "some avg10=0\nfull avg300=9\nother \0\n",
            "\0\nsome avg10=0\nfull avg300=9\n",
        ] as $raw) {
            // Only the fixture file is read; no kernel probe or command runs here.
            $this->pmssWriteFile($path, $raw);
            $this->assertSame('na', \pmssSystemStatsPsiRead($path));
            $this->assertSame(null, \pmssDiskIostatReadPsiFullAvg300($path));
        }
    }

    public function testValidRecordsRetainFormattingAndNumericPrecision(): void
    {
        $path = $this->pmssMakeTempFile('pmss-psi-valid-');
        foreach ([
            ["some avg10=1.23 avg60=4.56 avg300=7.89 total=123\nfull avg10=0.12 avg60=0.34 avg300=0.567 total=456\n",
                '1.2/4.6/0.1/7.9/0.3/0.6/123/456', 0.567],
            ["some\tavg10=0\r\nfull\tavg300=100\r\n", '0.0/na/na/na/na/100.0/na/na', 100.0],
            ["some avg10=0\nfull avg300=0\n", '0.0/na/na/na/na/0.0/na/na', 0.0],
            ["some avg10=0\nfull avg300=1.25 avg300=9\n", '0.0/na/na/na/na/1.3/na/na', 1.25],
            ["full avg300=12.345\n", 'na', 12.345],
            ['', 'na', null],
        ] as [$raw, $formatted, $numeric]) {
            // The formatter and scalar reader deliberately have distinct missing-field policies.
            $this->pmssWriteFile($path, $raw);
            $this->assertSame($formatted, \pmssSystemStatsPsiRead($path));
            $this->assertSame($numeric, \pmssDiskIostatReadPsiFullAvg300($path));
        }
    }
}
