<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once __DIR__.'/../../user/homeMarkerRegistry.php';

/**
 * ADR 0213: a file under a home directory is display only; root code takes settings from root stores.
 *
 * Root-run code under scripts/ may name a per-account home file only where listed here, and each
 * listing says why: it writes or repairs the display copy, it moves or rebuilds the home itself,
 * or it reads an account switch or preference that only selects among the
 * account's own services (ADR 0213 Decision 4). A new reference fails until it is reviewed and
 * listed. Code under etc/skel runs as the account and is not covered.
 */
class HomeFilesDisplayOnlyContractTest extends TestCase
{
    private const WATCHED = [
        '.bonus', '.bonusTraffic', '.bonusQuota', '.billingId', '.billingServiceId', '.billingClientId', '.notifyEmail',
        '.trafficLimit', '.trafficData', '.trafficDataLocal', '.trafficDataIngress', '.torrentThrottle', '.throttle',
        '.delugeWebPort', '.delugePort', '.rclonePort', '.qbittorrentPort', '.quota', '.resourceData', '.iopsLimit',
        '.media-stack-status.json', 'pmss-user.json', '.product',
        '.rtorrentDisable', '.delugeEnable', '.transmissionDisable', '.usageAlertsEnabled', '.rcloneEnable', '.qbittorrentEnable',
    ];

    private const ALLOWED = [
        // Writers and repairers of display copies.
        'scripts/cron/updateQuotas.php' => ['.quota'],
        'scripts/lib/resources/processor.php' => ['.resourceData'],
        'scripts/lib/traffic/storage.php' => ['.trafficLimit', '.trafficData', '.trafficDataLocal', '.trafficDataIngress'],
        'scripts/lib/user/add/artifactVerification.php' => ['.quota'],
        'scripts/lib/user/add/postProvision.php' => ['.bonusQuota'],
        'scripts/lib/user/homeMarkerRegistry.php' => ['.bonus', '.bonusTraffic', '.bonusQuota', '.billingId', '.billingServiceId', '.billingClientId', '.notifyEmail', '.trafficLimit'],
        'scripts/lib/user/iopsLimit.php' => ['.iopsLimit'],
        'scripts/lib/user/rootArtifactPermissions.php' => ['.billingId', '.billingServiceId', '.billingClientId', '.notifyEmail', '.quota'],
        'scripts/lib/user/traffic.php' => ['.torrentThrottle', '.quota'],
        'scripts/lib/user/trafficLimit.php' => ['.trafficLimit'],
        'scripts/lib/user/trafficLimitCommands.php' => ['.bonusTraffic', '.trafficLimit'],
        'scripts/lib/user/trafficLimitThrottle.php' => ['.throttle'],
        'scripts/lib/user/userConfigStore.php' => ['pmss-user.json'],
        'scripts/util/userPermissions.php' => ['.billingId', '.billingServiceId', '.billingClientId', '.notifyEmail', '.trafficData', '.throttle', '.quota', '.resourceData', '.trafficDataIngress'],
        // Moving or rebuilding the home itself.
        'scripts/lib/user/recreateRestore.php' => ['.billingId', '.billingServiceId', '.billingClientId', '.notifyEmail'],
        'scripts/lib/userTransfer/remoteScripts.php' => ['.bonusTraffic', '.bonusQuota', '.billingId', '.billingServiceId', '.billingClientId', '.trafficLimit', '.trafficData', '.qbittorrentPort', '.quota', 'pmss-user.json', '.resourceData', '.trafficDataIngress', '.trafficDataLocal'],
        // Display for the account (panel text, account-run stats and support reports, ADR 0152).
        'scripts/lib/stats/collect.php' => ['.bonusTraffic', '.trafficLimit', '.trafficData', '.quota', '.resourceData', '.trafficDataIngress'],
        'scripts/lib/support/diagnostics.php' => ['.billingId', '.billingServiceId', '.billingClientId'],
        'scripts/lib/welcomeMessage.php' => ['pmss-user.json', '.product'],
        // Account switches and the account's own applications (ADR 0213 Decision 4).
        'scripts/cron/checkRcloneInstances.php' => ['.rclonePort'],
        'scripts/cron/checkRtorrent.php' => ['.rtorrentDisable'],
        'scripts/lib/lighttpd/delugeWebConf.php' => ['.delugeWebPort', '.delugePort'],
        'scripts/lib/lighttpd/userConfigApply.php' => ['.rclonePort', '.qbittorrentPort'],
        'scripts/lib/mediaStackWatchdog.php' => ['.media-stack-status.json'],
        'scripts/lib/user/add/userConfigApply.php' => ['.delugeWebPort', '.delugePort', '.rclonePort', '.qbittorrentPort'],
        'scripts/lib/user/appSwitch.php' => ['.rtorrentDisable', '.delugeEnable', '.rcloneEnable', '.qbittorrentEnable'],
        'scripts/lib/user/deluge.php' => ['.delugePort', '.delugeEnable'],
        'scripts/lib/user/delugeManagedConfig.php' => ['.delugeEnable'],
        'scripts/lib/user/delugeProvisioning.php' => ['.delugeWebPort'],
        'scripts/lib/user/qbittorrentPortRepair.php' => ['.qbittorrentPort'],
        'scripts/lib/user/restart.php' => ['.media-stack-status.json'],
        'scripts/lib/user/torrentPort.php' => ['.delugeWebPort', '.delugePort', '.qbittorrentPort'],
        'scripts/lib/user/transmissionSettings.php' => ['.transmissionDisable'],
        'scripts/lib/user/usageAlerts.php' => ['.media-stack-status.json', '.usageAlertsEnabled'],
        'scripts/lib/userTransfer/qbittorrentRestart.php' => ['.qbittorrentEnable'],
        'scripts/lib/userTransfer/rtorrentRestart.php' => ['.rtorrentDisable'],
        'scripts/util/userConfig.php' => ['.rclonePort', '.qbittorrentPort'],
    ];

    public function testEveryRegistryMarkerIsDisplayOnly(): void
    {
        foreach (\pmssHomeMarkerRegistry() as $marker => $entry) {
            $this->assertSame('display', $entry['class'], $marker);
            $this->assertTrue(array_key_exists('store', $entry), $marker.' must name its root store key or null');
        }
    }

    public function testRootCodeNamesHomeFilesOnlyWhereAllowed(): void
    {
        $found = [];
        foreach ($this->pmssRepoFiles('scripts') as $path => $file) {
            if (strpos($path, 'scripts/lib/tests/') === 0 || strpos($path, 'scripts/lib/testing/') === 0) continue;
            $source = (string) @file_get_contents($file->getPathname());
            if (strpos(substr($source, 0, 64), '<?php') === false) continue;
            $strings = '';
            foreach (token_get_all($source) as $token) {
                if (is_array($token) && in_array($token[0], [T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE], true)) $strings .= $token[1]."\n";
            }
            foreach (self::WATCHED as $name) {
                if (preg_match('~[\'"/]'.preg_quote($name, '~').'(?![A-Za-z0-9_.-])~', $strings) === 1) $found[$path][] = $name;
            }
        }

        $unexpected = [];
        foreach ($found as $path => $names) {
            $extra = array_diff($names, self::ALLOWED[$path] ?? []);
            if ($extra !== []) $unexpected[] = $path.': '.implode(', ', $extra);
        }
        $this->assertSame([], $unexpected, 'Root code names a home file outside the ADR 0213 allowlist');
        $this->assertTrue(isset($found['scripts/util/userPermissions.php']), 'scanner found nothing; check the scan');
    }

    public function testStoreValuesSurviveWholeRecordWritesAndReadBackValidated(): void
    {
        require_once __DIR__.'/../../user/userConfigStore.php';
        require_once __DIR__.'/../../cgroup/policy.php';
        $store = new \UserConfigStore($this->pmssMakeTempDir('pmss-store-values-').'/config');
        $base = ['ramMiB' => 512, 'rtorrentPort' => 5000, 'quota' => 5, 'quotaBurst' => 6];
        $this->assertTrue($store->set('alice', $base));
        $this->assertTrue($store->setValue('alice', 'bonusPercent', 100));
        $this->assertTrue($store->setValue('alice', 'bonusTrafficGiB', 50));
        $this->assertTrue($store->setValue('alice', 'notificationEmail', 'alice@example.test'));
        // A whole-record write (userConfig.php) keeps the values the provisioning CLIs set.
        $this->assertTrue($store->set('alice', $base + ['bonusPercent' => 0, 'notificationEmail' => 'other@example.test']));
        $record = $store->get('alice');
        $this->assertSame(100, $record['bonusPercent']);
        $this->assertSame(100, \pmssUserBonusPercentFromConfig($record));
        $this->assertSame(50, $store->getInt('alice', 'bonusTrafficGiB'));
        $this->assertSame('alice@example.test', $store->readNotificationEmail('alice'));
        $this->assertTrue($store->setValue('alice', 'bonusTrafficGiB', null));
        $this->assertSame(0, $store->getInt('alice', 'bonusTrafficGiB'));
        $this->assertSame(0, \pmssUserBonusPercentFromConfig(['bonusPercent' => 301]));
        $this->assertSame(0, \pmssUserBonusPercentFromConfig(['bonusPercent' => '50']));
        $this->assertFalse($store->setValue('bob', 'bonusPercent', 10));
    }

    public function testSettingReadersUseRootStores(): void
    {
        $readers = [
            'scripts/lib/cgroup/policy.php' => "\$config['bonusPercent']",
            'scripts/cron/cgroupBonusCpuWeightApply.php' => 'pmssUserBonusPercentFromConfig($json)',
            'scripts/cron/trafficLimits.php' => 'pmssTrafficLimitRootStateRead($thisUser, $userConfigStore)',
            'scripts/lib/user/traffic.php' => "getInt(\$username, 'uploadThrottleKib')",
            'scripts/lib/network/fireqos.php' => '$limitStateDir."/{$username}.enabled"',
            'scripts/lib/nginxConfig/userConfigsGenerate.php' => "\$runtimePortDir.'/deluge-web-'.\$user",
            'scripts/lib/user/userConfigStore.php' => "\$payload['notificationEmail']",
            'scripts/lib/lighttpd/watchdogQuotaProbe.php' => 'pmssQuotaRootCopyPath($username)',
            'scripts/lib/user/resourcesListModel.php' => 'pmssTrafficStatsPath($user)',
        ];
        foreach ($readers as $path => $needle) {
            $this->assertStringContainsString($needle, $this->pmssReadRepoExecutableSource($path), $path);
        }
    }
}
