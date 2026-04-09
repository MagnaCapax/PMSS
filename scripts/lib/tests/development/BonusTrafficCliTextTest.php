<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once __DIR__.'/../../user/bonusTraffic.php';

class BonusTrafficCliTextTest extends TestCase
{
    public function testUsageTextKeepsSupportedFormsAndNotes(): void
    {
        $this->assertOrderedStrings(
            [
                'Usage:',
                '  ./userBonusTraffic.php --user=<username> --bonus=<GiB>',
                '  ./userBonusTraffic.php --user=<username> --show',
                '  ./userBonusTraffic.php --user=<username> --unset',
                '  ./userBonusTraffic.php <username> <GiB>',
                'Notes:',
                '  - Bonus unit is GiB (monthly quota add-on).',
                '  - Use 0 (or --unset) to remove the bonus.',
            ],
            \pmssUserGiBSettingUsageText(
                'userBonusTraffic.php',
                'bonus',
                'Bonus unit is GiB (monthly quota add-on).',
                'Use 0 (or --unset) to remove the bonus.'
            ),
            'Missing usage line: ',
            'Usage line order changed at: '
        );
    }

    public function testBonusLibraryBuildsUsageFromSharedHelper(): void
    {
        $this->pmssAssertRepoFileContainsString('scripts/lib/user/bonusTraffic.php', 'pmssUserGiBSettingUsageText(');
    }
}
