<?php
declare(strict_types=1);

namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

final class TrafficLimitCliWrapperCharacterizationTest extends TestCase
{
    private function assertStringNotContainsString(string $needle, string $haystack, string $message = ''): void
    {
        $this->assertTrue(
            strpos($haystack, $needle) === false,
            $message !== '' ? $message : sprintf('Expected string to not contain %s, but it did', var_export($needle, true))
        );
    }

    public function testUtilityWrapperKeepsUsageTextButDelegatesExecution(): void
    {
        $source = $this->pmssReadRepoFile('scripts/util/userTrafficLimit.php');

        $this->assertStringContainsString("require_once __DIR__.'/../lib/runtime.php';", $source);
        $this->assertStringContainsString("require_once __DIR__.'/../lib/user/trafficLimit.php';", $source);
        $this->assertStringContainsString('  ./userTrafficLimit.php --user=<username> --limit=<GiB>', $source);
        $this->assertStringContainsString(
            "exit(pmssUserTrafficLimitCli(\$argv ?? (\$_SERVER['argv'] ?? []), \$usage));",
            $source
        );
        $this->assertStringNotContainsString('pmssParseCliTokens($argv', $source);
        $this->assertStringNotContainsString('pmssTrafficLimitWriteGiBFile($target, $trafficLimit)', $source);
    }

    public function testLibraryOwnsTheTrafficLimitCliImplementation(): void
    {
        $source = $this->pmssReadRepoFile('scripts/lib/user/trafficLimit.php');

        $this->assertStringContainsString('function pmssUserTrafficLimitCli(array $argv, ?string $usage = null): int', $source);
        $this->assertStringContainsString('$targetModes = [', $source);
        $this->assertStringContainsString('Traffic limit for {$userName}: {$limit} GiB', $source);
        $this->assertStringContainsString('traffic limit set to %d GiB (monthly quota)', $source);
    }
}
