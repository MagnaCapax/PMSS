<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

final class trafficLimitConsumerCharacterizationTest extends TestCase
{
    public function testCanonicalConsumersUseSharedTrafficLimitStateReader(): void
    {
        foreach (['scripts/cron/trafficLimits.php', 'scripts/lib/stats/collect.php', 'etc/skel/www/stats.php', 'etc/skel/www/welcome.php'] as $path) {
            $this->pmssAssertRepoFileContainsString($path, 'pmssTrafficLimitStateRead(');
        }
    }

    public function testWebConsumersNoLongerInlineTrafficLimitFileReads(): void
    {
        foreach ([
            'etc/skel/www/stats.php' => ["file_get_contents('../.trafficLimit')", "file_get_contents('../.bonusTraffic')"],
            'etc/skel/www/welcome.php' => ["file_get_contents('../.trafficLimit')", "pmssWelcomeInteger"."FileRead('../.bonusTraffic'"],
        ] as $path => $needles) {
            $this->pmssAssertRepoFileNotContainsStrings($path, $needles);
        }
    }

    public function testPmssStatsNoLongerCarriesLocalIntegerReader(): void
    {
        $this->pmssAssertRepoFileContainsAndOmitsStrings('scripts/lib/pmssStats.php', ["require_once __DIR__.'/user/trafficLimit.php';"], ['function '.'pmssStats'.'ReadIntegerFile(']);
    }

    public function testSerializedStateConsumersUseSharedArrayReader(): void
    {
        foreach (['scripts/lib/stats/collect.php', 'etc/skel/www/stats.php', 'etc/skel/www/welcome.php'] as $path) {
            $this->pmssAssertRepoFileContainsString($path, 'pmssReadSerializedArrayFile(');
        }
    }

    public function testLegacyWebConsumersNoLongerInlineUnserializeFileReads(): void
    {
        $this->pmssAssertRepoFileNotContainsString('etc/skel/www/stats.php', '@unserialize(@file_get_contents(');
        $this->pmssAssertRepoFileNotContainsStrings('etc/skel/www/welcome.php', [
            '@unserialize(trim(@file_get_contents(',
        ]);
    }

    public function testTrafficLimitCronWritesReadableThrottleFile(): void
    {
        $this->pmssAssertRepoFileContainsAllStrings('scripts/lib/user/trafficLimit.php', [
            'pmssTrafficLimitThrottleFileWrite($throttleFile, (int) $trafficCapMbit, $error)',
            'traffic throttle file write failed',
            'pmssTrafficLimitThrottleFileRemove($throttleFile, $error)',
        ]);
    }

    public function testTrafficLimitCronChecksListUsersExitCode(): void
    {
        $this->pmssAssertRepoFileContainsAllStrings('scripts/cron/trafficLimits.php', [
            'if (($users = pmssListManagedUsersFromResult(pmssListManagedUsersResult(\'/scripts/listUsers.php\'))) === null) {',
            'exit(1);',
        ]);
    }

    public function testTrafficLimitCronChecksRuntimeMarkerWrites(): void
    {
        $this->pmssAssertRepoFileContainsAllStrings('scripts/cron/trafficLimits.php', [
            'if (!pmssTrafficLimitMarkerTouch($thisUser, $userTrafficLimitEnabledFile)) {',
            'if (!pmssTrafficLimitMarkerRemove($thisUser, $userTrafficLimitEnabledFile)) {',
        ]);
        $this->pmssAssertRepoFileContainsAllStrings('scripts/lib/user/trafficLimit.php', [
            'function pmssTrafficLimitMarkerTouch(string $user, string $path): bool',
            'function pmssTrafficLimitMarkerRemove(string $user, string $path): bool',
        ]);
    }

    public function testTrafficLimitCronValidatesThrottleFileBoundary(): void
    {
        $this->pmssAssertRepoFileContainsAllStrings('scripts/lib/user/trafficLimit.php', [
            "function pmssTrafficLimitThrottleFilePath(string \$user, string \$homeRoot = '/home'): ?string",
            'pmssTrafficLimitCliUsernameNormalize($user)',
            '@realpath($home) !== $home',
            'pmssUserFilePathIsSafe($path)',
        ]);
        $this->pmssAssertRepoFileContainsString('scripts/cron/trafficLimits.php', 'pmssTrafficLimitThrottleApply($thisUser, $throttlePlan[\'effectiveCapMbit\']);');
    }

    public function testThrottlePolicyNoLongerUsesSlidingStateFiles(): void
    {
        $slidingKey = 'sliding'.'ThrottleStart';
        $legacyFileKey = 'throttle'.'_mbit';

        foreach ([
            'scripts/cron/trafficLimits.php' => [$slidingKey, $legacyFileKey],
            'scripts/lib/network/fireqos.php' => [$legacyFileKey],
            'scripts/lib/update/networking.php' => [$slidingKey],
        ] as $path => $needles) {
            $this->pmssAssertRepoFileNotContainsStrings($path, $needles);
        }
    }
}
