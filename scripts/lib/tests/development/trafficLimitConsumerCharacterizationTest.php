<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

final class trafficLimitConsumerCharacterizationTest extends TestCase
{
    public function testTrafficStateReadersAvoidInlineParsing(): void
    {
        $this->pmssAssertRepoFileContractCases([
            'scripts/cron/trafficLimits.php' => ['required' => ['pmssTrafficLimitStateRead(']],
            'scripts/lib/stats/collect.php' => ['required' => ['pmssTrafficLimitStateRead(', 'pmssReadSerializedArrayFile(']],
            'scripts/lib/pmssStats.php' => [
                'required' => ["require_once __DIR__.'/user/trafficLimit.php';"],
                'forbidden' => ['function '.'pmssStats'.'ReadIntegerFile('],
            ],
            'etc/skel/www/stats.php' => [
                'required' => ['pmssStatsRenderTrafficUsageBlock(', 'pmssStatsSerializedStateRead('],
                'forbidden' => ["file_get_contents('../.trafficLimit')", "file_get_contents('../.bonusTraffic')", '@unserialize(@file_get_contents('],
            ],
            'etc/skel/www/statsHelpers.php' => ['required' => ['pmssTrafficLimitStateRead(', 'pmssStatsSerializedStateRead(']],
            'etc/skel/www/welcome.php' => [
                'required' => ['pmssTrafficLimitStateRead(', 'pmssCustomerSerializedArrayFileRead('],
                'forbidden' => ["file_get_contents('../.trafficLimit')", "pmssWelcomeInteger"."FileRead('../.bonusTraffic'", '@unserialize(trim(@file_get_contents('],
            ],
        ]);
    }

    public function testTrafficLimitCronUsesSharedSafeHelpers(): void
    {
        $this->pmssAssertRepoFileContractCases([
            'scripts/lib/user/trafficLimit.php' => ['required' => [
                'pmssTrafficLimitThrottleFileWrite($throttleFile, (int) $trafficCapMbit, $error)',
                'traffic throttle file write failed',
                'pmssTrafficLimitThrottleFileRemove($throttleFile, $error)',
                'function pmssTrafficLimitMarkerTouch(string $user, string $path): bool',
                'function pmssTrafficLimitMarkerRemove(string $user, string $path): bool',
                "function pmssTrafficLimitThrottleFilePath(string \$user, string \$homeRoot = '/home'): ?string",
                'pmssTrafficLimitCliUsernameNormalize($user)',
                '@realpath($home) !== $home',
                'pmssUserFilePathIsSafe($path)',
            ]],
            'scripts/cron/trafficLimits.php' => ['required' => [
                'if (($users = pmssListManagedUsersFromResult(pmssListManagedUsersResult(\'/scripts/listUsers.php\'))) === null) {',
                'exit(1);',
                'if (!pmssTrafficLimitMarkerTouch($thisUser, $userTrafficLimitEnabledFile)) {',
                'if (!pmssTrafficLimitMarkerRemove($thisUser, $userTrafficLimitEnabledFile)) {',
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

    public function testUserResourcesTrafficStateReadingDelegatesToSharedHelpers(): void
    {
        $this->pmssAssertRepoFileContainsAndOmitsStrings('scripts/lib/user/resourcesList.php', [
            '"/home/{$user}/.trafficLimit"',
            '"/home/{$user}/.trafficData"',
            "require_once __DIR__.'/traffic.php';",
            "require_once __DIR__.'/trafficLimit.php';",
            'pmssTrafficLimitReadGiBFile($trafficLimitPath)',
            'pmssReadUserTrafficMonth($trafficDataPath)',
            'max($diskQuotaGiB * 500, 15000)',
        ], ['unserialize(']);
    }

    public function testTrafficScriptsUseSharedSerializedPayloadReader(): void
    {
        $showTrafficReader = 'pmssShowTrafficRead'.'StatsPayload';
        $trafficLimitsReader = 'pmssRead'.'TrafficData';

        $this->pmssAssertRepoFileContractCases([
            'scripts/lib/traffic/report.php' => [
                'required' => ['pmssTrafficStatsPath($thisUser, $statsDir)', 'pmssReadSerializedArrayFile($statsPath)', 'pmssTrafficReadRootOwnedStatsPayload($ingressPath, $baseUser)'],
                'forbidden' => ['function '.$showTrafficReader, 'unserialize('],
            ],
            'scripts/lib/resources/show.php' => ['required' => ['pmssReadSerializedArrayFile("{$statsDir}/{$thisUser}")'], 'forbidden' => ['unserialize(']],
            'scripts/cron/trafficLimits.php' => ['required' => ['pmssTrafficReadRootOwnedStatsPayload($trafficDataFile, $thisUser)'], 'forbidden' => ['function '.$trafficLimitsReader, '@unserialize']],
        ]);
    }
}
