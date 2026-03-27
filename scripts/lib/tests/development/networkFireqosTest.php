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
        $path = getenv('PATH');
        $path = is_string($path) ? $path : '';

        $this->pmssWriteFile(
            $binDir.'/fireqos',
            "#!/bin/sh\nprintf '%s\\n' \"$*\" > ".escapeshellarg($markerPath)."\nprintf 'started\\n'\n"
        );
        @chmod($binDir.'/fireqos', 0755);

        $this->pmssWithEnv([
            'PATH' => $binDir.($path !== '' ? ':'.$path : ''),
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
        $path = getenv('PATH');
        $path = is_string($path) ? $path : '';

        $this->pmssWriteFile(
            $binDir.'/fireqos',
            "#!/bin/sh\nprintf '%s\\n' \"$*\" > ".escapeshellarg($markerPath)."\n"
        );
        @chmod($binDir.'/fireqos', 0755);

        $this->pmssWithEnv([
            'PATH' => $binDir.($path !== '' ? ':'.$path : ''),
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
            $this->assertTrue(strpos($config, 'eth1') !== false);
            $this->assertTrue(strpos($config, '500') !== false);
            $this->assertTrue(strpos($config, 'match dst 10.0.0.0/8') !== false);
        });
    }

    public function testBuildFireqosConfigFallsBackQuietlyWhenTemplateMissing(): void
    {
        $missingPath = $this->pmssMakeTempPath('pmss-fireqos-missing-', '.conf');
        $config = '';
        $warnings = [];

        $this->pmssWithEnv(['PMSS_FIREQOS_TEMPLATE' => $missingPath], function () use (&$config, &$warnings): void {
            set_error_handler(static function (int $severity, string $message) use (&$warnings): bool {
                $warnings[] = [$severity, $message];
                return true;
            });

            try {
                $config = \networkBuildFireqosConfig(
                    ['interface' => 'eth2', 'speed' => 1234, 'throttle' => ['max' => 100]],
                    [],
                    []
                );
            } finally {
                restore_error_handler();
            }
        });

        $this->assertEquals([], $warnings);
        $this->assertTrue(strpos($config, 'interface eth2') !== false);
        $this->assertTrue(strpos($config, 'rate 1234') !== false);
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
            $this->assertTrue(strpos($config, 'class root ceil 25Mbit') !== false);
        });
    }

    public function testBuildFireqosConfigUsesSlidingThrottleWhenPresent(): void
    {
        $stateDir = $this->pmssMakeTempDir('pmss-fireqos-state-', 0700);
        $homeDir = $this->pmssMakeTempDir('pmss-fireqos-home-', 0700);
        @mkdir($homeDir.'/root', 0755, true);
        @file_put_contents($stateDir.'/root.enabled', '1');
        @file_put_contents($homeDir.'/root/.throttle', '25');
        @file_put_contents($stateDir.'/root.throttle_mbit', '333');

        $this->pmssWithEnv([
            'PMSS_TRAFFIC_LIMIT_STATE_DIR' => $stateDir,
            'PMSS_HOME_DIR' => $homeDir,
        ], function (): void {
            $config = \networkBuildFireqosConfig(
                ['interface' => 'eth0', 'speed' => 1000, 'throttle' => ['max' => 100]],
                ['root'],
                []
            );
            $this->assertTrue(strpos($config, 'class root ceil 333Mbit') !== false);
        });
    }

    public function testBuildFireqosConfigFallsBackWhenSlidingThrottleInvalid(): void
    {
        $stateDir = $this->pmssMakeTempDir('pmss-fireqos-state-', 0700);
        $homeDir = $this->pmssMakeTempDir('pmss-fireqos-home-', 0700);
        @mkdir($homeDir.'/root', 0755, true);
        @file_put_contents($stateDir.'/root.enabled', '1');
        @file_put_contents($homeDir.'/root/.throttle', '25');
        @file_put_contents($stateDir.'/root.throttle_mbit', 'nope');

        $this->pmssWithEnv([
            'PMSS_TRAFFIC_LIMIT_STATE_DIR' => $stateDir,
            'PMSS_HOME_DIR' => $homeDir,
        ], function (): void {
            $config = \networkBuildFireqosConfig(
                ['interface' => 'eth0', 'speed' => 1000, 'throttle' => ['max' => 100]],
                ['root'],
                []
            );
            $this->assertTrue(strpos($config, 'class root ceil 25Mbit') !== false);
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
            $this->assertTrue(strpos($config, 'class root ceil 90Mbit') !== false);
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
            $this->assertTrue(strpos($config, 'ceil') === false);
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
        $this->assertTrue(strpos($config, 'class root ') !== false);
        $this->assertTrue(strpos($config, $username) === false);
    }
}
