<?php
declare(strict_types=1);

namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

final class BonusTrafficCliWrapperCharacterizationTest extends TestCase
{
    public function testUtilityWrapperDelegatesToSharedTrafficLimitLibrary(): void
    {
        $path = 'scripts/util/userBonusTraffic.php';

        $this->pmssAssertRepoFileContainsAllStrings(
            $path,
            [
                "require_once __DIR__.'/../lib/user/trafficLimit.php';",
                "exit(pmssUserBonusTrafficCli(\$argv ?? (\$_SERVER['argv'] ?? [])));",
            ]
        );
        $this->pmssAssertRepoFileNotContainsStrings(
            $path,
            [
                "require_once '/scripts/lib/user/bonusTraffic.php';",
                'pmssParseCliTokens($argv',
                '  ./userBonusTraffic.php --user=<username> --bonus=<GiB>',
            ]
        );
    }

    public function testCompatibilityShimKeepsLegacyLibraryPathAlive(): void
    {
        $path = 'scripts/lib/user/bonusTraffic.php';

        $this->pmssAssertRepoFileContainsAllStrings(
            $path,
            [
                'Backward-compatible bonus traffic entrypoint.',
                "require_once __DIR__.'/trafficLimit.php';",
            ]
        );
        $this->pmssAssertRepoFileNotContainsStrings(
            $path,
            [
                'function pmssUserBonusTrafficCli(array $argv): int',
                'pmssParseCliTokens($argv)',
            ]
        );
    }
}
