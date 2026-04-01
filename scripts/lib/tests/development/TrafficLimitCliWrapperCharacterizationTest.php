<?php
declare(strict_types=1);

namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

final class TrafficLimitCliWrapperCharacterizationTest extends TestCase
{
    public function testUtilityWrapperKeepsUsageTextButDelegatesExecution(): void
    {
        $path = 'scripts/util/userTrafficLimit.php';

        $this->pmssAssertRepoFileContainsAllStrings(
            $path,
            [
                "require_once __DIR__.'/../lib/runtime.php';",
                "require_once __DIR__.'/../lib/user/trafficLimit.php';",
                '  ./userTrafficLimit.php --user=<username> --limit=<GiB>',
                "exit(pmssUserTrafficLimitCli(\$argv ?? (\$_SERVER['argv'] ?? []), \$usage));",
            ]
        );
        $this->pmssAssertRepoFileNotContainsStrings(
            $path,
            ['pmssParseCliTokens($argv', 'pmssTrafficLimitWriteGiBFile($target, $trafficLimit)']
        );
    }

    public function testLibraryOwnsTheTrafficLimitCliImplementation(): void
    {
        $this->pmssAssertRepoFileContainsAllStrings(
            'scripts/lib/user/trafficLimit.php',
            [
                'function pmssUserGiBSettingCli(array $argv, array $spec): int',
                'function pmssTrafficLimitCliTargetModes(string $userName, string $homeDir): array',
                'function pmssUserTrafficCliBootstrap(): bool',
                'function pmssUserTrafficLimitCli(array $argv, ?string $usage = null): int',
                "'targetModesResolver' => 'pmssTrafficLimitCliTargetModes'",
                'traffic limit set to %d GiB (monthly quota)',
            ]
        );
        $this->pmssAssertRepoFileNotContainsStrings(
            'scripts/lib/user/bonusTraffic.php',
            ['pmssParseCliTokens($argv)', 'pmssTrafficLimitWriteGiBFile($bonusFile']
        );
    }
}
