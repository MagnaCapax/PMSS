<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

final class trafficLimitConsumerCharacterizationTest extends TestCase
{
    public function testTrafficStateReadersAvoidInlineParsing(): void
    {
        $this->pmssAssertRepoFileContainsAndOmitsStrings('scripts/cron/trafficLimits.php',
            ['pmssTrafficLimitRootStateRead('], ['/.bonusTraffic']);
        $this->pmssAssertRepoFileContainsAllStrings('scripts/lib/stats/collect.php',
            ['pmssTrafficLimitStateRead(', 'pmssReadSerializedArrayFile(']);
        $this->pmssAssertRepoFileContainsAndOmitsStrings('scripts/lib/pmssStats.php',
            ["require_once __DIR__.'/user/trafficLimit.php';"], ['function '.'pmssStats'.'ReadIntegerFile(']);
        $this->pmssAssertRepoFileContainsAndOmitsStrings('etc/skel/www/stats.php',
            ['pmssStatsRenderTrafficUsageBlock(', 'pmssStatsSerializedStateRead('],
            ["file_get_contents('../.trafficLimit')", "file_get_contents('../.bonusTraffic')", '@unserialize(@file_get_contents(']);
        $this->pmssAssertRepoFileContainsAllStrings('etc/skel/www/statsTraffic.php',
            ['pmssTrafficLimitStateRead(', 'pmssStatsSerializedStateRead(']);
        $this->pmssAssertRepoFileContainsAndOmitsStrings('etc/skel/www/welcome.php',
            ['pmssTrafficLimitStateRead(', 'pmssCustomerSerializedArrayFileRead('],
            ["file_get_contents('../.trafficLimit')", "pmssWelcomeInteger"."FileRead('../.bonusTraffic'", '@unserialize(trim(@file_get_contents(']);
    }

    public function testTrafficLimitCronUsesSharedSafeHelpers(): void
    {
        $this->pmssAssertRepoFileContainsAllStrings('scripts/lib/user/trafficLimitThrottle.php', [
            'pmssTrafficLimitThrottleFileWrite($throttleFile, (int) $trafficCapMbit, $error)',
            'traffic throttle file write failed',
            'pmssTrafficLimitThrottleFileRemove($throttleFile, $error)',
            "function pmssTrafficLimitThrottleFilePath(string \$user, string \$homeRoot = '/home'): ?string",
            'pmssTrafficLimitCliUsernameNormalize($user)',
            '@realpath($home) !== $home',
            'pmssUserFilePathIsSafe($path)',
            "require_once __DIR__.'/trafficLimitMarkers.php';",
            "require_once __DIR__.'/trafficLimitReconcile.php';",
        ]);
        $this->pmssAssertRepoFileContainsAllStrings('scripts/lib/user/trafficLimitMarkers.php', [
            'function pmssTrafficLimitMarkerTouch(string $user, string $path, ?int $capMbit = null): bool',
            'function pmssTrafficLimitMarkerRemove(string $user, string $path): bool',
        ]);
        $this->pmssAssertRepoFileContainsAllStrings('scripts/lib/user/trafficLimitReconcile.php', [
            'if (!pmssTrafficLimitMarkerRemove($user, $enabledMarkerPath)) {',
        ]);
        $this->pmssAssertRepoFileContainsAllStrings('scripts/cron/trafficLimits.php', [
            'if (($users = pmssListManagedUsersFromResult(pmssListManagedUsersResult(\'/scripts/listUsers.php\'))) === null) {',
            'exit(1);',
            'if (!pmssTrafficLimitMarkerTouch($thisUser, $userTrafficLimitEnabledFile, $throttlePlan[\'effectiveCapMbit\'])) {',
            'pmssTrafficLimitThrottleLiftAfterCooldown($thisUser, $userTrafficLimitEnabledFile, ',
            'pmssTrafficLimitThrottleApply($thisUser, $throttlePlan[\'effectiveCapMbit\']);',
        ]);
    }

    public function testTrafficLimitCronClearsUnlimitedBeforeReadingUsage(): void
    {
        $this->pmssAssertRepoFileContainsOrderedStrings('scripts/cron/trafficLimits.php', [
            'pmssTrafficLimitThrottleClearUnlimited($thisUser, $userTrafficLimitEnabledFile)',
            'if (!file_exists($trafficDataFile)) {',
        ]);
    }

    public function testTrafficLimitCronReconcilesThrottleWhenUsageDataUnavailable(): void
    {
        $this->pmssAssertRepoFileContainsOrderedStrings('scripts/cron/trafficLimits.php', [
            'pmssTrafficLimitThrottleReconcileWithoutUsage($thisUser, $userTrafficLimitEnabledFile, $trafficLimitPeriod)',
            '$trafficLimit = (float) $trafficLimitState[\'effectiveLimitGiB\'];',
        ]);
    }

    public function testThrottlePolicyNoLongerUsesSlidingStateFiles(): void
    {
        $slidingKey = 'sliding'.'ThrottleStart';
        $legacyFileKey = 'throttle'.'_mbit';

        $this->pmssAssertRepoFileContainsAndOmitsStrings('scripts/cron/trafficLimits.php', [], [$slidingKey, $legacyFileKey]);
        $this->pmssAssertRepoFileContainsAndOmitsStrings('scripts/lib/network/fireqos.php', [], [$legacyFileKey]);
        $this->pmssAssertRepoFileContainsAndOmitsStrings('scripts/lib/update/networking.php', [], [$slidingKey]);
    }

    public function testUserResourcesTrafficStateReadingDelegatesToSharedHelpers(): void
    {
        $this->pmssAssertRepoFileContainsAndOmitsStrings('scripts/lib/user/resourcesListModel.php', [
            "'traffic.php'",
            "'trafficLimit.php'",
            "pmssTrafficLimitReadGiBFile(pmssIntegerSettingRuntimeUserPath('trafficLimits', \$user))",
            'pmssReadUserTrafficMonth(pmssTrafficStatsPath($user))',
            'max($diskQuotaGiB * 500, 15000)',
        ], ['unserialize(', '/.trafficLimit', '/.trafficData']);
    }

    public function testTrafficScriptsUseSharedSerializedPayloadReader(): void
    {
        $showTrafficReader = 'pmssShowTrafficRead'.'StatsPayload';
        $trafficLimitsReader = 'pmssRead'.'TrafficData';

        $this->pmssAssertRepoFileContainsAndOmitsStrings('scripts/lib/traffic/reportModel.php',
            ['pmssTrafficStatsPath($thisUser, $statsDir)', 'pmssReadSerializedArrayFile($statsPath)', 'pmssTrafficReadRootOwnedStatsPayload(pmssTrafficIngressStatsPath($thisUser), $baseUser)'],
            ['function '.$showTrafficReader, 'unserialize(']);
        $this->pmssAssertRepoFileContainsAndOmitsStrings('scripts/lib/resources/show.php',
            ['pmssReadSerializedArrayFile("{$statsDir}/{$thisUser}")'], ['unserialize(']);
        $this->pmssAssertRepoFileContainsAndOmitsStrings('scripts/cron/trafficLimits.php',
            ['$trafficDataFile = pmssTrafficStatsPath($thisUser);', 'pmssTrafficReadRootOwnedStatsPayload($trafficDataFile, $thisUser)'],
            ['function '.$trafficLimitsReader, '@unserialize', '"/home/{$thisUser}/.trafficData"']);
    }
}
