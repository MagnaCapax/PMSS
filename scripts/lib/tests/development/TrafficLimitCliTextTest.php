<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

class TrafficLimitCliTextTest extends TestCase
{
    public function testUsageTextKeepsSupportedFormsAndNotes(): void
    {
        $contents = $this->pmssReadRepoFile('scripts/util/userTrafficLimit.php');
        $this->assertOrderedStrings([
            'Usage:',
            '  ./userTrafficLimit.php --user=<username> --limit=<GiB>',
            '  ./userTrafficLimit.php --user=<username> --show',
            '  ./userTrafficLimit.php --user=<username> --unset',
            '  ./userTrafficLimit.php <username> <GiB>',
            'Notes:',
            '  - Limit unit is GiB (monthly quota).',
            '  - Use 0 (or --unset) to remove a limit.',
        ], $contents, 'Missing usage line: ', 'Usage line order changed at: ');
    }

    public function testMissingUsernamePathStillPrintsUsageText(): void
    {
        $this->assertStringContainsString(
            'Error: missing username.\\n".$usage."\\n',
            $this->pmssReadRepoFile('scripts/util/userTrafficLimit.php')
        );
    }
}
