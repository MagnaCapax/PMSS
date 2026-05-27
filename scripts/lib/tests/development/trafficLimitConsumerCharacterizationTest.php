<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

final class trafficLimitConsumerCharacterizationTest extends TestCase
{
    public function testCanonicalConsumersUseSharedTrafficLimitStateReader(): void
    {
        foreach (['scripts/cron/trafficLimits.php', 'scripts/lib/pmssStats.php', 'etc/skel/www/stats.php', 'etc/skel/www/welcome.php'] as $path) {
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
        $this->pmssAssertRepoFileNotContainsString('scripts/lib/pmssStats.php', 'function '.'pmssStats'.'ReadIntegerFile(');
        $this->pmssAssertRepoFileContainsString('scripts/lib/pmssStats.php', "require_once __DIR__.'/user/trafficLimit.php';");
    }

    public function testSerializedStateConsumersUseSharedArrayReader(): void
    {
        foreach (['scripts/lib/pmssStats.php', 'etc/skel/www/stats.php', 'etc/skel/www/welcome.php'] as $path) {
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
        $source = $this->pmssReadRepoFile('scripts/cron/trafficLimits.php');

        $this->assertStringContainsString('@chmod($throttleFile, 0644);', $source);
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
            'function pmssTrafficLimitMarkerTouch(string $user, string $path): bool',
            'function pmssTrafficLimitMarkerRemove(string $user, string $path): bool',
            'if (!pmssTrafficLimitMarkerTouch($thisUser, $userTrafficLimitEnabledFile)) {',
            'if (!pmssTrafficLimitMarkerRemove($thisUser, $userTrafficLimitEnabledFile)) {',
        ]);
    }

    public function testTrafficLimitCronValidatesThrottleFileBoundary(): void
    {
        $this->pmssAssertRepoFileContainsAllStrings('scripts/cron/trafficLimits.php', [
            'function pmssTrafficLimitThrottleFilePath(string $user): ?string',
            'pmssValidateUsername($user)',
            '@realpath($home) !== $home',
            'pmssUserFilePathIsSafe($path)',
        ]);
    }

    public function testThrottlePolicyNoLongerUsesSlidingStateFiles(): void
    {
        $slidingKey = 'sliding'.'ThrottleStart';
        $legacyFileKey = 'throttle'.'_mbit';

        $this->pmssAssertRepoFileNotContainsStrings('scripts/cron/trafficLimits.php', [$slidingKey, $legacyFileKey]);
        $this->pmssAssertRepoFileNotContainsString('scripts/lib/network/fireqos.php', $legacyFileKey);
        $this->pmssAssertRepoFileNotContainsString('scripts/lib/update/networking.php', $slidingKey);
    }
}
