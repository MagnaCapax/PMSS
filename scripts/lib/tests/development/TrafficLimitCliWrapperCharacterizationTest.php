<?php
declare(strict_types=1);

namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

final class TrafficLimitCliWrapperCharacterizationTest extends TestCase
{
    public function testUtilityWrapperKeepsUsageTextButDelegatesExecution(): void
    {
        $path = 'scripts/util/userTrafficLimit.php';

        $this->pmssAssertRepoFileContainsString($path, "require_once __DIR__.'/../lib/runtime.php';");
        $this->pmssAssertRepoFileContainsString($path, "require_once __DIR__.'/../lib/user/trafficLimit.php';");
        $this->pmssAssertRepoFileContainsString($path, '  ./userTrafficLimit.php --user=<username> --limit=<GiB>');
        $this->pmssAssertRepoFileContainsString(
            $path,
            "exit(pmssUserTrafficLimitCli(\$argv ?? (\$_SERVER['argv'] ?? []), \$usage));"
        );
        $this->pmssAssertRepoFileNotContainsString($path, 'pmssParseCliTokens($argv');
        $this->pmssAssertRepoFileNotContainsString($path, 'pmssTrafficLimitWriteGiBFile($target, $trafficLimit)');
    }

    public function testLibraryOwnsTheTrafficLimitCliImplementation(): void
    {
        $this->pmssAssertRepoFileContainsAllStrings(
            'scripts/lib/user/trafficLimit.php',
            [
                'function pmssUserTrafficLimitCli(array $argv, ?string $usage = null): int',
                '$targetModes = [',
                'Traffic limit for {$userName}: {$limit} GiB',
                'traffic limit set to %d GiB (monthly quota)',
            ]
        );
    }
}
