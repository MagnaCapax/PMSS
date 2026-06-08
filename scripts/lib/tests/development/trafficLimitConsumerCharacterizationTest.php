<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

final class trafficLimitConsumerCharacterizationTest extends TestCase
{
    public function testTrafficConsumersUseSharedReaders(): void
    {
        $this->pmssAssertRepoFileContractCases([
            'scripts/cron/trafficLimits.php' => ['required' => ['pmssTrafficLimitStateRead(']],
            'scripts/lib/stats/collect.php' => ['required' => ['pmssTrafficLimitStateRead(', 'pmssReadSerializedArrayFile(']],
            'etc/skel/www/stats.php' => ['required' => ['pmssTrafficLimitStateRead(', 'pmssStatsSerializedStateRead(']],
            'etc/skel/www/welcome.php' => ['required' => ['pmssTrafficLimitStateRead(', 'pmssReadSerializedArrayFile(']],
        ]);
    }

    public function testWebConsumersNoLongerInlineTrafficLimitFileReads(): void
    {
        $this->pmssAssertRepoFileContractCases([
            'etc/skel/www/stats.php' => ['forbidden' => ["file_get_contents('../.trafficLimit')", "file_get_contents('../.bonusTraffic')"]],
            'etc/skel/www/welcome.php' => ['forbidden' => ["file_get_contents('../.trafficLimit')", "pmssWelcomeInteger"."FileRead('../.bonusTraffic'"]],
        ]);
    }

    public function testPmssStatsNoLongerCarriesLocalIntegerReader(): void
    {
        $this->pmssAssertRepoFileContainsAndOmitsStrings('scripts/lib/pmssStats.php', ["require_once __DIR__.'/user/trafficLimit.php';"], ['function '.'pmssStats'.'ReadIntegerFile(']);
    }

    public function testLegacyWebConsumersNoLongerInlineUnserializeFileReads(): void
    {
        $this->pmssAssertRepoFileContractCases([
            'etc/skel/www/stats.php' => ['forbidden' => ['@unserialize(@file_get_contents(']],
            'etc/skel/www/welcome.php' => ['forbidden' => ['@unserialize(trim(@file_get_contents(']],
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
        $this->pmssAssertRepoFileContractCases([
            'scripts/cron/trafficLimits.php' => ['required' => [
                'if (!pmssTrafficLimitMarkerTouch($thisUser, $userTrafficLimitEnabledFile)) {',
                'if (!pmssTrafficLimitMarkerRemove($thisUser, $userTrafficLimitEnabledFile)) {',
            ]],
            'scripts/lib/user/trafficLimit.php' => ['required' => [
                'function pmssTrafficLimitMarkerTouch(string $user, string $path): bool',
                'function pmssTrafficLimitMarkerRemove(string $user, string $path): bool',
            ]],
        ]);
    }

    public function testTrafficLimitCronValidatesThrottleFileBoundary(): void
    {
        $this->pmssAssertRepoFileContractCases([
            'scripts/lib/user/trafficLimit.php' => ['required' => [
                "function pmssTrafficLimitThrottleFilePath(string \$user, string \$homeRoot = '/home'): ?string",
                'pmssTrafficLimitCliUsernameNormalize($user)',
                '@realpath($home) !== $home',
                'pmssUserFilePathIsSafe($path)',
            ]],
            'scripts/cron/trafficLimits.php' => ['required' => [
                'pmssTrafficLimitThrottleApply($thisUser, $throttlePlan[\'effectiveCapMbit\']);',
            ]],
        ]);
    }

    public function testThrottlePolicyNoLongerUsesSlidingStateFiles(): void
    {
        $slidingKey = 'sliding'.'ThrottleStart';
        $legacyFileKey = 'throttle'.'_mbit';

        $this->pmssAssertRepoFileContractCases([
            'scripts/cron/trafficLimits.php' => ['forbidden' => [$slidingKey, $legacyFileKey]],
            'scripts/lib/network/fireqos.php' => ['forbidden' => [$legacyFileKey]],
            'scripts/lib/update/networking.php' => ['forbidden' => [$slidingKey]],
        ]);
    }
}
