<?php
declare(strict_types=1);

namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

final class TrafficLimitCliWrapperCharacterizationTest extends TestCase
{
    public function testUtilityWrapperKeepsUsageTextButDelegatesExecution(): void
    {
        $this->pmssAssertRepoFileContractCases([
            'scripts/util/userTrafficLimit.php' => [
                'required' => [
                    "require_once __DIR__.'/../lib/runtime.php';",
                    "require_once __DIR__.'/../lib/user/trafficLimit.php';",
                    "pmssRunCliEntrypointWithArgv(__FILE__, 'pmssUserTrafficLimitCli');",
                ],
                'forbidden' => [
                    'pmssParseCliTokens($argv',
                    'pmssTrafficLimitWriteGiBFile($target, $trafficLimit)',
                    '  ./userTrafficLimit.php --user=<username> --limit=<GiB>',
                ],
            ],
        ]);
    }

    public function testLibraryOwnsTheTrafficLimitCliImplementation(): void
    {
        $this->pmssAssertRepoFileContractCases([
            'scripts/lib/user/trafficLimit.php' => ['required' => [
                'function pmssUserGiBSettingCli(array $argv, array $spec): int',
                'function pmssUserGiBSettingUsageText(',
                'function pmssTrafficLimitCliTargetModes(string $userName, string $homeDir): array',
                'function pmssTrafficLimitPersistTargetModes(array $targetModes, int $value, ?string &$error = null): bool',
                'function pmssUserTrafficCliBootstrap(): bool',
                'function pmssUserTrafficLimitCli(array $argv, ?string $usage = null): int',
                "'targetModesResolver' => 'pmssTrafficLimitCliTargetModes'",
                'traffic limit set to %d GiB (monthly quota)',
            ]],
            'scripts/lib/user/bonusTraffic.php' => [
                'forbidden' => ['pmssParseCliTokens($argv)', 'pmssTrafficLimitWriteGiBFile($bonusFile'],
            ],
        ]);
    }
}
