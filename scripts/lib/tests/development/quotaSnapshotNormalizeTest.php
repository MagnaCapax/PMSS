<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/quotaSnapshot.php';

class QuotaSnapshotNormalizeTest extends TestCase
{
    public function testNormalizeLeavesSuffixedSizesUntouched(): void
    {
        $line = '       /dev/md4    594G   1380G   1725G            5642    690k    863k';

        $this->assertEquals($line, \pmssQuotaSnapshotNormalizeHumanReadableLine($line));
    }

    public function testNormalizeAddsKSuffixToBareNumericSizes(): void
    {
        $line = '       /dev/md4      594    1380    1725            5642    690k    863k';

        $this->assertEquals(
            '       /dev/md4 594K 1380K 1725K 5642 690k 863k',
            \pmssQuotaSnapshotNormalizeHumanReadableLine($line)
        );
    }

    public function testNormalizePreservesOverQuotaMarkers(): void
    {
        $line = '       /dev/md4      594*    1380*    1725*            5642    690k    863k';

        $this->assertEquals(
            '       /dev/md4 594K* 1380K* 1725K* 5642 690k 863k',
            \pmssQuotaSnapshotNormalizeHumanReadableLine($line)
        );
    }

    public function testNormalizeIgnoresQuotaHeaders(): void
    {
        $line = '     Filesystem   space   quota   limit   grace   files   quota   limit   grace';

        $this->assertEquals($line, \pmssQuotaSnapshotNormalizeHumanReadableLine($line));
    }

    public function testNormalizeHandlesDiskByUuidRows(): void
    {
        $line = '       /dev/disk/by-uuid/example 1 2 3 0 0 0';

        $this->assertEquals(
            '       /dev/disk/by-uuid/example 1K 2K 3K 0 0 0',
            \pmssQuotaSnapshotNormalizeHumanReadableLine($line)
        );
    }

    public function testNormalizePreservesTrailingNewline(): void
    {
        $header = "Disk quotas for user alice (uid 1000):\n     Filesystem   space   quota   limit   grace   files   quota   limit   grace\n";
        $input = $header.'       /dev/md4      1      2      3            0       0       0';
        $expected = $header.'       /dev/md4 1K 2K 3K 0 0 0';
        foreach (['', "\n", "\n\n", "\r\n", "\r\n\r\n"] as $ending) {
            $this->assertSame($expected.str_replace("\r\n", PHP_EOL, $ending), \pmssQuotaSnapshotNormalizeHumanReadableOutput($input.$ending));
        }
        foreach (['', "\n", "\n\n", "\r\n", "\r\n\r\n"] as $blank) {
            $this->assertSame(str_replace("\r\n", PHP_EOL, $blank), \pmssQuotaSnapshotNormalizeHumanReadableOutput($blank));
        }
    }
}
