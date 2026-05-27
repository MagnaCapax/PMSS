<?php
namespace PMSS\Tests;

require_once dirname(__DIR__, 2).'/network/fireqos.php';

class NetworkFireqosTest extends TestCase
{
    public function testApplyFireqosWritesConfigAndStartsCommand(): void
    {
        $configDir = $this->pmssMakeTempDir('pmss-fireqos-config-', 0700);
        $logDir = $this->pmssMakeTempDir('pmss-fireqos-log-', 0700);
        $binDir = $this->pmssMakeTempDir('pmss-fireqos-bin-', 0700);
        $markerPath = $this->pmssMakeTempPath('pmss-fireqos-marker-');
        $configPath = $configDir.'/fireqos.conf';
        $logPath = $logDir.'/fireqos.log';

        $this->pmssWriteFile(
            $binDir.'/fireqos',
            "#!/bin/sh\nprintf '%s\\n' \"$*\" > ".escapeshellarg($markerPath)."\nprintf 'started\\n'\n"
        );
        @chmod($binDir.'/fireqos', 0755);

        $this->pmssWithPathPrefixedEnv($binDir, [
            'PMSS_FIREQOS_CONFIG_PATH' => $configPath,
            'PMSS_FIREQOS_LOG_PATH' => $logPath,
        ], function () use ($configPath, $logPath, $markerPath): void {
            \networkApplyFireqos("interface eth0\nrate 1000\n");

            $this->assertEquals("interface eth0\nrate 1000\n", (string) @file_get_contents($configPath));
            $this->assertEquals('start '.$configPath, trim((string) @file_get_contents($markerPath)));
            $this->assertStringContainsString('started', (string) @file_get_contents($logPath));
        });
    }

    public function testApplyFireqosSkipsStartWhenConfigPathCannotBeCreated(): void
    {
        $blockedParent = $this->pmssMakeTempFile('pmss-fireqos-blocked-');
        $logDir = $this->pmssMakeTempDir('pmss-fireqos-log-', 0700);
        $binDir = $this->pmssMakeTempDir('pmss-fireqos-bin-', 0700);
        $markerPath = $this->pmssMakeTempPath('pmss-fireqos-marker-');

        $this->pmssWriteFile(
            $binDir.'/fireqos',
            "#!/bin/sh\nprintf '%s\\n' \"$*\" > ".escapeshellarg($markerPath)."\n"
        );
        @chmod($binDir.'/fireqos', 0755);

        $this->pmssWithPathPrefixedEnv($binDir, [
            'PMSS_FIREQOS_CONFIG_PATH' => $blockedParent.'/fireqos.conf',
            'PMSS_FIREQOS_LOG_PATH' => $logDir.'/fireqos.log',
        ], function () use ($blockedParent, $markerPath): void {
            \networkApplyFireqos("interface eth0\nrate 1000\n");

            $this->assertFalse(file_exists($blockedParent.'/fireqos.conf'));
            $this->assertFalse(file_exists($markerPath));
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
            $this->assertStringContainsString('eth1', $config);
            $this->assertStringContainsString('500', $config);
            $this->assertStringContainsString('match dst 10.0.0.0/8', $config);
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

        $this->assertStringContainsString('interface eth2', $config);
        $this->assertStringContainsString('rate 1234', $config);
    }

    public function testBuildFireqosConfigUsesUserThrottleCapWhenEnabled(): void
    {
        $stateDir = $this->pmssMakeTempDir('pmss-fireqos-state-', 0700);
        $homeDir = $this->pmssMakeTempDir('pmss-fireqos-home-', 0700);
        @mkdir($homeDir.'/root', 0755, true);
        @file_put_contents($stateDir.'/root.enabled', '1');
        @file_put_contents($homeDir.'/root/.throttle', '25');

        $this->pmssWithEnv([
            'PMSS_TRAFFIC_LIMIT_STATE_DIR' => $stateDir,
            'PMSS_HOME_DIR' => $homeDir,
        ], function (): void {
            $config = \networkBuildFireqosConfig(
                ['interface' => 'eth0', 'speed' => 1000, 'throttle' => ['max' => 100]],
                ['root'],
                []
            );
            $this->assertStringContainsString('class root ceil 25Mbit', $config);
        });
    }

    public function testBuildFireqosConfigWithRepositoryTemplateKeepsLocalClassInsideInterfaceBlock(): void
    {
        $stateDir = $this->pmssMakeTempDir('pmss-fireqos-state-', 0700);
        $homeDir = $this->pmssMakeTempDir('pmss-fireqos-home-', 0700);
        $templatePath = $this->pmssMakeTempPath('pmss-fireqos-template-', '.conf');
        @mkdir($homeDir.'/root', 0755, true);
        @file_put_contents($stateDir.'/root.enabled', '1');
        @file_put_contents($homeDir.'/root/.throttle', '25');
        @file_put_contents($templatePath, $this->pmssReadRepoFile('etc/seedbox/config/template.fireqos'));

        $this->pmssWithEnv([
            'PMSS_TRAFFIC_LIMIT_STATE_DIR' => $stateDir,
            'PMSS_HOME_DIR' => $homeDir,
            'PMSS_FIREQOS_TEMPLATE' => $templatePath,
        ], function (): void {
            $config = \networkBuildFireqosConfig(
                ['interface' => 'eth0', 'speed' => 1000, 'throttle' => ['max' => 100]],
                ['root'],
                ['10.0.0.0/8']
            );
            $interfacePos = strpos($config, 'interface $DEVICE outbound output rate $INTERFACE_SPEED');
            $localPos = strpos($config, 'class local commit 10%');
            $this->assertTrue($interfacePos !== false);
            $this->assertTrue($localPos !== false);
            $this->assertTrue($localPos > $interfacePos);
            $this->assertEquals(1, substr_count($config, 'class local commit 10%'));
            $this->assertStringContainsString('class root ceil 25Mbit', $config);
            $this->assertStringContainsString('match dst 10.0.0.0/8', $config);
        });
    }

    public function testBuildFireqosConfigFallsBackToDefaultCapWhenThrottleMissing(): void
    {
        $stateDir = $this->pmssMakeTempDir('pmss-fireqos-state-', 0700);
        $homeDir = $this->pmssMakeTempDir('pmss-fireqos-home-', 0700);
        @mkdir($homeDir.'/root', 0755, true);
        @file_put_contents($stateDir.'/root.enabled', '1');

        $this->pmssWithEnv([
            'PMSS_TRAFFIC_LIMIT_STATE_DIR' => $stateDir,
            'PMSS_HOME_DIR' => $homeDir,
        ], function (): void {
            $config = \networkBuildFireqosConfig(
                ['interface' => 'eth0', 'speed' => 1000, 'throttle' => ['max' => 90]],
                ['root'],
                []
            );
            $this->assertStringContainsString('class root ceil 90Mbit', $config);
        });
    }

    public function testBuildFireqosConfigSkipsCapWhenNotEnabled(): void
    {
        $stateDir = $this->pmssMakeTempDir('pmss-fireqos-state-', 0700);
        $homeDir = $this->pmssMakeTempDir('pmss-fireqos-home-', 0700);
        $templatePath = $this->pmssMakeTempPath('fireqos-template-', '.conf');
        @mkdir($homeDir.'/root', 0755, true);
        @file_put_contents($homeDir.'/root/.throttle', '10');
        @file_put_contents($templatePath, "interface ##INTERFACE\nrate ##SPEED\n##LOCALNETWORK\n##USERMATCHES\n");

        $this->pmssWithEnv([
            'PMSS_TRAFFIC_LIMIT_STATE_DIR' => $stateDir,
            'PMSS_HOME_DIR' => $homeDir,
            'PMSS_FIREQOS_TEMPLATE' => $templatePath,
        ], function (): void {
            $config = \networkBuildFireqosConfig(
                ['interface' => 'eth0', 'speed' => 1000, 'throttle' => ['max' => 80]],
                ['root'],
                []
            );
            $this->assertStringNotContainsString('ceil', $config);
        });
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
        $this->assertStringContainsString('class root ', $config);
        $this->assertStringNotContainsString($username, $config);
    }
}
