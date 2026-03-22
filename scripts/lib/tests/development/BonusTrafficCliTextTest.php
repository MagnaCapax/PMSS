<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

class BonusTrafficCliTextTest extends TestCase
{
    public function testUsageTextKeepsSupportedFormsAndNotes(): void
    {
        $contents = $this->pmssReadRepoFile('scripts/lib/user/bonusTraffic.php');
        $expectedLines = [
            'Usage:',
            '  ./userBonusTraffic.php --user=<username> --bonus=<GiB>',
            '  ./userBonusTraffic.php --user=<username> --show',
            '  ./userBonusTraffic.php --user=<username> --unset',
            '  ./userBonusTraffic.php <username> <GiB>',
            'Notes:',
            '  - Bonus unit is GiB (monthly quota add-on).',
            '  - Use 0 (or --unset) to remove the bonus.',
        ];

        $offset = -1;
        foreach ($expectedLines as $line) {
            $position = strpos($contents, $line);
            $this->assertTrue($position !== false, 'Missing usage line: '.$line);
            $this->assertTrue($position > $offset, 'Usage line order changed at: '.$line);
            $offset = $position;
        }
    }

    public function testMissingUsernamePathStillPrintsUsageText(): void
    {
        $this->assertStringContainsString(
            'Error: missing username.\\n".$usage."\\n',
            $this->pmssReadRepoFile('scripts/lib/user/bonusTraffic.php')
        );
    }
}
