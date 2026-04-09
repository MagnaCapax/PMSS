<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once __DIR__.'/../../user/trafficLimit.php';

class TrafficLimitCliTextTest extends TestCase
{
    public function testUsageTextKeepsSupportedFormsAndNotes(): void
    {
        $this->assertOrderedStrings(
            [
                'Usage:',
                '  ./userTrafficLimit.php --user=<username> --limit=<GiB>',
                '  ./userTrafficLimit.php --user=<username> --show',
                '  ./userTrafficLimit.php --user=<username> --unset',
                '  ./userTrafficLimit.php <username> <GiB>',
                'Notes:',
                '  - Limit unit is GiB (monthly quota).',
                '  - Use 0 (or --unset) to remove a limit.',
            ],
            \pmssUserGiBSettingUsageText(
                'userTrafficLimit.php',
                'limit',
                'Limit unit is GiB (monthly quota).',
                'Use 0 (or --unset) to remove a limit.'
            ),
            'Missing usage line: ',
            'Usage line order changed at: '
        );
    }

    public function testWrapperDelegatesToSharedLibraryUsageHandling(): void
    {
        $this->pmssAssertRepoFileContainsString(
            'scripts/util/userTrafficLimit.php',
            "exit(pmssUserTrafficLimitCli(\$argv ?? (\$_SERVER['argv'] ?? [])));"
        );
    }
}
