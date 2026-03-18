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
        $content = implode("\n", array(
            'Disk quotas for user alice (uid 1000):',
            '     Filesystem   space   quota   limit   grace   files   quota   limit   grace',
            '       /dev/md4      1      2      3            0       0       0',
            '',
        ));

        $this->assertEquals(
            implode("\n", array(
                'Disk quotas for user alice (uid 1000):',
                '     Filesystem   space   quota   limit   grace   files   quota   limit   grace',
                '       /dev/md4 1K 2K 3K 0 0 0',
                '',
            )),
            \pmssQuotaSnapshotNormalizeHumanReadableOutput($content)
        );
    }
}
