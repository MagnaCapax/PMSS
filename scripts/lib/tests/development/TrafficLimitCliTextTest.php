<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once __DIR__.'/../../user/trafficLimit.php';

class TrafficLimitCliTextTest extends TestCase
{
    public function testUsageTextKeepsSupportedFormsAndNotes(): void
    {
        foreach ([
            ['userTrafficLimit.php', 'limit', 'Limit unit is GiB (monthly quota).', 'Use 0 (or --unset) to remove a limit.'],
            ['userBonusTraffic.php', 'bonus', 'Bonus unit is GiB (monthly quota add-on).', 'Use 0 (or --unset) to remove the bonus.'],
        ] as [$script, $option, $unitNote, $removeNote]) {
            $this->assertOrderedStrings(
                [
                    'Usage:',
                    "  ./{$script} --user=<username> --{$option}=<GiB>",
                    "  ./{$script} --user=<username> --show",
                    "  ./{$script} --user=<username> --unset",
                    "  ./{$script} <username> <GiB>",
                    'Notes:',
                    '  - '.$unitNote,
                    '  - '.$removeNote,
                ],
                \pmssUserGiBSettingUsageText($script, $option, $unitNote, $removeNote),
                'Missing usage line: ',
                'Usage line order changed at: '
            );
        }
    }

    public function testWrappersDelegateToSharedLibraryUsageHandling(): void
    {
        $this->pmssAssertRepoFileContractCases([
            'scripts/lib/user/trafficLimit.php' => ['required' => ['pmssUserGiBSettingUsageText(']],
            'scripts/util/userTrafficLimit.php' => ['required' => ["pmssRunCliEntrypointWithArgv(__FILE__, 'pmssUserTrafficLimitCli');"]],
            'scripts/lib/user/bonusTraffic.php' => ['required' => ["require_once __DIR__.'/trafficLimit.php';"]],
        ]);
    }
}
