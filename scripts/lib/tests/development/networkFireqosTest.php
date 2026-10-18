<?php
namespace PMSS\Tests;

require_once dirname(__DIR__, 2).'/network/fireqos.php';

class NetworkFireqosTest extends TestCase
{
    private function fireqosThrottleFixture(bool $enabled, ?string $throttle = null): array
    {
        $stateDir = $this->pmssMakeTempDir('pmss-fireqos-state-', 0700);
        $homeDir = $this->pmssMakeTempDir('pmss-fireqos-home-', 0700);
        $this->pmssEnsureDir($homeDir.'/root');
        if ($enabled) {
            @file_put_contents($stateDir.'/root.enabled', '1');
        }
        if ($throttle !== null) {
            @file_put_contents($homeDir.'/root/.throttle', $throttle);
        }
        return [$stateDir, $homeDir];
    }

    private function buildFireqosConfigWithThrottleFixture(
        bool $enabled,
        ?string $throttle,
        array $networkConfig,
        array $users = ['root'],
        array $localnets = [],
        string $templateContent = ''
    ): string {
        [$stateDir, $homeDir] = $this->fireqosThrottleFixture($enabled, $throttle);
        $env = [
            'PMSS_TRAFFIC_LIMIT_STATE_DIR' => $stateDir,
            'PMSS_HOME_DIR' => $homeDir,
        ];
        if ($templateContent !== '') {
            $templatePath = $this->pmssMakeTempPath('pmss-fireqos-template-', '.conf');
            @file_put_contents($templatePath, $templateContent);
            $env['PMSS_FIREQOS_TEMPLATE'] = $templatePath;
        }

        $config = '';
        $this->pmssWithEnv($env, function () use (&$config, $networkConfig, $users, $localnets): void {
            $config = \networkBuildFireqosConfig($networkConfig, $users, $localnets, static function (string $path): array {
                $stats = lstat($path);
                $stats['uid'] = 0;
                return $stats;
            });
        });
        return $config;
    }

    public function testApplyFireqosWritesConfigAndStartsCommand(): void
    {
        $configDir = $this->pmssMakeTempDir('pmss-fireqos-config-', 0700);
        $logDir = $this->pmssMakeTempDir('pmss-fireqos-log-', 0700);
        $markerPath = $this->pmssMakeTempPath('pmss-fireqos-marker-');
        $configPath = $configDir.'/fireqos.conf';
        $logPath = $logDir.'/fireqos.log';
        $binDir = $this->pmssMakeExecutableStub('fireqos', "#!/bin/sh\nprintf '%s\\n' \"$*\" > ".escapeshellarg($markerPath)."\nprintf 'started\\n'\n", 'pmss-fireqos-bin-');

        $this->pmssWithPathPrefixedEnv($binDir, [
            'PMSS_FIREQOS_CONFIG_PATH' => $configPath,
            'PMSS_FIREQOS_LOG_PATH' => $logPath,
        ], function () use ($configPath, $logPath, $markerPath): void {
            $this->assertTrue(\networkApplyFireqos("interface eth0\nrate 1000\n"));

            $this->assertEquals("interface eth0\nrate 1000\n", (string) @file_get_contents($configPath));
            $this->assertEquals('start '.$configPath, trim((string) @file_get_contents($markerPath)));
            $this->assertStringContainsString('started', (string) @file_get_contents($logPath));
        });
    }

    public function testApplyFireqosSkipsStartWhenConfigPathCannotBeCreated(): void
    {
        $blockedParent = $this->pmssMakeTempFile('pmss-fireqos-blocked-');
        $logDir = $this->pmssMakeTempDir('pmss-fireqos-log-', 0700);
        $markerPath = $this->pmssMakeTempPath('pmss-fireqos-marker-');
        $binDir = $this->pmssMakeExecutableStub('fireqos', "#!/bin/sh\nprintf '%s\\n' \"$*\" > ".escapeshellarg($markerPath)."\n", 'pmss-fireqos-bin-');

        $this->pmssWithPathPrefixedEnv($binDir, [
            'PMSS_FIREQOS_CONFIG_PATH' => $blockedParent.'/fireqos.conf',
            'PMSS_FIREQOS_LOG_PATH' => $logDir.'/fireqos.log',
        ], function () use ($blockedParent, $markerPath): void {
            $this->assertFalse(\networkApplyFireqos("interface eth0\nrate 1000\n"));

            $this->assertFalse(file_exists($blockedParent.'/fireqos.conf'));
            $this->assertFalse(file_exists($markerPath));
        });
    }

    public function testApplyFireqosReportsStartExitCode(): void
    {
        $configDir = $this->pmssMakeTempDir('pmss-fireqos-config-', 0700);
        $logDir = $this->pmssMakeTempDir('pmss-fireqos-log-', 0700);
        $binDir = $this->pmssMakeExecutableStub('fireqos', "#!/bin/sh\nexit 7\n", 'pmss-fireqos-bin-');
        $this->pmssWithPathPrefixedEnv($binDir, [
            'PMSS_FIREQOS_CONFIG_PATH' => $configDir.'/fireqos.conf',
            'PMSS_FIREQOS_LOG_PATH' => $logDir.'/fireqos.log',
        ], function () use ($logDir): void {
            $this->assertFalse(\networkApplyFireqos("interface eth0\n"));
            $this->assertStringContainsString('rc=7', (string) file_get_contents($logDir.'/fireqos.log'));
        });
    }

    public function testBuildFireqosConfigRendersPlaceholders(): void
    {
        $template = "iface ##INTERFACE\nrate ##SPEED\n##LOCALNETWORK\n##USERMATCHES\n";
        $path = $this->pmssMakeTempPath('fireqos-template-', '.conf');
        file_put_contents($path, $template);

        $this->pmssWithEnv(['PMSS_FIREQOS_TEMPLATE' => $path], function (): void {
            $config = \networkBuildFireqosConfig(
                ['interface' => 'eth1', 'speed' => 500, 'throttle' => ['max' => 100]],
                [],
                ['10.0.0.0/8']
            );
            $this->assertStringContainsAllStrings(['eth1', '500', 'match dst 10.0.0.0/8'], $config);
        });
    }

    public function testBuildFireqosConfigFallsBackQuietlyWhenTemplateMissing(): void
    {
        $missingPath = $this->pmssMakeTempPath('pmss-fireqos-missing-', '.conf');
        $config = '';

        $this->pmssWithEnv(['PMSS_FIREQOS_TEMPLATE' => $missingPath], function () use (&$config): void {
            $this->pmssAssertNoPhpWarnings(function () use (&$config): void {
                $config = \networkBuildFireqosConfig(
                    ['interface' => 'eth2', 'speed' => 1234, 'throttle' => ['max' => 100]],
                    [],
                    []
                );
            });
        });

        $this->assertStringContainsAllStrings(['interface eth2', 'rate 1234'], $config);
    }

    public function testBuildFireqosConfigUsesUserThrottleCapWhenEnabled(): void
    {
        $config = $this->buildFireqosConfigWithThrottleFixture(true, '25', ['interface' => 'eth0', 'speed' => 1000, 'throttle' => ['max' => 100]]);
        $this->assertStringContainsString('class root ceil 25Mbit', $config);
    }

    public function testBuildFireqosConfigWithRepositoryTemplateKeepsLocalClassInsideInterfaceBlock(): void
    {
        $config = $this->buildFireqosConfigWithThrottleFixture(
            true,
            '25',
            ['interface' => 'eth0', 'speed' => 1000, 'throttle' => ['max' => 100]],
            ['root'],
            ['10.0.0.0/8'],
            $this->pmssReadRepoFile('etc/seedbox/config/template.fireqos')
        );
        $this->assertOrderedStrings([
            'interface $DEVICE outbound output rate $INTERFACE_SPEED',
            'class local commit 10%',
        ], $config);
        $this->assertEquals(1, substr_count($config, 'class local commit 10%'));
        $this->assertStringContainsAllStrings(['class root ceil 25Mbit', 'match dst 10.0.0.0/8'], $config);
    }

    public function testBuildFireqosConfigFallsBackToDefaultCapWhenThrottleMissing(): void
    {
        $config = $this->buildFireqosConfigWithThrottleFixture(true, null, ['interface' => 'eth0', 'speed' => 1000, 'throttle' => ['max' => 90]]);
        $this->assertStringContainsString('class root ceil 90Mbit', $config);
    }

    public function testBuildFireqosConfigSkipsCapWhenNotEnabled(): void
    {
        $config = $this->buildFireqosConfigWithThrottleFixture(
            false,
            '10',
            ['interface' => 'eth0', 'speed' => 1000, 'throttle' => ['max' => 80]],
            ['root'],
            [],
            "interface ##INTERFACE\nrate ##SPEED\n##LOCALNETWORK\n##USERMATCHES\n"
        );
        $this->assertStringNotContainsString('ceil', $config);
    }

    public function testBuildFireqosConfigRejectsInvalidUsernamesBeforeUidLookup(): void
    {
        $markerPath = $this->pmssMakeTempPath('pmss-fireqos-injection-');
        $username = 'root; touch '.escapeshellarg($markerPath);

        try {
            $config = \networkBuildFireqosConfig(
                ['interface' => 'eth0', 'speed' => 1000, 'throttle' => ['max' => 80]],
                [$username, 'root'],
                []
            );
        } finally {
            $created = file_exists($markerPath);
        }

        $this->assertTrue($created === false);
        $this->assertStringContainsString('class root', $config);
        $this->assertStringNotContainsString($username, $config);
    }

    public function testRepositoryTemplateRoutesEveryUidIntoGroupBeforeLeaves(): void
    {
        $users = ['root', 'daemon', 'nobody'];
        $config = $this->buildFireqosConfigWithThrottleFixture(false, null, ['interface' => 'eth0', 'speed' => 1000], $users,
            [], $this->pmssReadRepoFile('etc/seedbox/config/template.fireqos'));
        $lines = explode("\n", $config);
        $group = array_search('class group users commit 50% ceil 95% balanced', $lines, true);
        $firstClass = array_search('    class root', $lines, true);
        $this->assertTrue($group !== false && $firstClass !== false);
        foreach (['root' => 0, 'daemon' => 1, 'nobody' => 65534] as $name => $uid) {
            $match = '      match rawmark '.$uid;
            $class = array_search('    class '.$name, $lines, true);
            $this->assertTrue($class !== false && $lines[$class + 1] === $match);
            $this->assertTrue(in_array($match, array_slice($lines, $group + 1, $firstClass - $group - 1), true));
        }
        $this->assertStringContainsString('interface $DEVICE outbound output rate $INTERFACE_SPEED minrate 1Mbit', $config);
    }

    public function testRenderedCapsRoundTripAndApplyDecision(): void
    {
        $config = $this->buildFireqosConfigWithThrottleFixture(true, '25', ['throttle' => ['max' => 100]],
            ['root', 'daemon'], [], $this->pmssReadRepoFile('etc/seedbox/config/template.fireqos'));
        $this->assertEquals(['root' => 25, 'daemon' => null], \networkFireqosRenderedUserCaps($config));
        $desired = ['root' => 25, 'daemon' => null];
        $this->assertFalse(\networkFireqosNeedsApply($desired, ['daemon' => null, 'root' => 25]));
        $this->assertTrue(\networkFireqosNeedsApply($desired, ['root' => 50, 'daemon' => null]));
        $this->assertTrue(\networkFireqosNeedsApply($desired, ['root' => 25]));
        $this->assertTrue(\networkFireqosNeedsApply($desired, ['root' => 25, 'daemon' => null, 'nobody' => null]));
        $this->assertTrue(\networkFireqosNeedsApply($desired, null));
        $this->assertEquals(null, \networkFireqosRenderedUserCaps('interface eth0'));
    }

    public function testTenantOwnedThrottleFallsBackToDefaultCap(): void
    {
        [$stateDir, $homeDir] = $this->fireqosThrottleFixture(true, '99999');
        $this->pmssWithEnv(['PMSS_TRAFFIC_LIMIT_STATE_DIR' => $stateDir, 'PMSS_HOME_DIR' => $homeDir], function () use ($homeDir): void {
            $tenantStats = static function (string $path): array {
                $stats = lstat($path);
                $stats['uid'] = 1001;
                return $stats;
            };
            $caps = \networkFireqosUserCaps(['throttle' => ['max' => 100]], ['root'], $tenantStats);
            $config = \networkBuildFireqosConfig(['throttle' => ['max' => 100]], ['root'], [], $tenantStats);
            $this->assertEquals(100, $caps['root']['cap']);
            $this->assertStringContainsString('class root ceil 100Mbit', $config);
            $this->assertStringNotContainsString('99999Mbit', $config);
        });
    }
}
