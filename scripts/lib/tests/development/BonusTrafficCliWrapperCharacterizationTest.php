<?php
declare(strict_types=1);

namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

final class BonusTrafficCliWrapperCharacterizationTest extends TestCase
{
    public function testWrapperAndCompatibilityShimContractsRemainStable(): void
    {
        $this->pmssAssertRepoFileContractCases([
            'scripts/util/userBonusTraffic.php' => [
                'required' => [
                    "require_once __DIR__.'/../lib/user/trafficLimit.php';",
                    "pmssRunCliEntrypointWithArgv(__FILE__, 'pmssUserBonusTrafficCli');",
                ],
                'forbidden' => [
                    "require_once '/scripts/lib/user/bonusTraffic.php';",
                    'pmssParseCliTokens($argv',
                    '  ./userBonusTraffic.php --user=<username> --bonus=<GiB>',
                ],
            ],
            'scripts/lib/user/bonusTraffic.php' => [
                'required' => [
                    'Backward-compatible bonus traffic entrypoint.',
                    "require_once __DIR__.'/trafficLimit.php';",
                ],
                'forbidden' => [
                    'function pmssUserBonusTrafficCli(array $argv): int',
                    'pmssParseCliTokens($argv)',
                ],
            ],
        ]);
    }
}
