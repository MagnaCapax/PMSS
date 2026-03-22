<?php
namespace PMSS\Tests;

require_once dirname(__DIR__, 2).'/network/fireqos.php';

class NetworkFireqosTest extends TestCase
{
    public function testBuildFireqosConfigRendersPlaceholders(): void
    {
        $template = "iface ##INTERFACE\nrate ##SPEED\n##LOCALNETWORK\n##USERMATCHES\n";
        $path = sys_get_temp_dir().'/fireqos-template-'.bin2hex(random_bytes(4)).'.conf';
        file_put_contents($path, $template);
        putenv('PMSS_FIREQOS_TEMPLATE='.$path);

        try {
            $config = \networkBuildFireqosConfig(
                ['interface' => 'eth1', 'speed' => 500, 'throttle' => ['max' => 100]],
                [],
                ['10.0.0.0/8']
            );
            $this->assertTrue(strpos($config, 'eth1') !== false);
            $this->assertTrue(strpos($config, '500') !== false);
            $this->assertTrue(strpos($config, 'match dst 10.0.0.0/8') !== false);
        } finally {
            @unlink($path);
            putenv('PMSS_FIREQOS_TEMPLATE');
        }
    }

    public function testBuildFireqosConfigUsesUserThrottleCapWhenEnabled(): void
    {
        $stateDir = $this->pmssMakeTempDir('pmss-fireqos-state-', 0700);
        $homeDir = $this->pmssMakeTempDir('pmss-fireqos-home-', 0700);
        @mkdir($homeDir.'/root', 0755, true);
        @file_put_contents($stateDir.'/root.enabled', '1');
        @file_put_contents($homeDir.'/root/.throttle', '25');

        $prevStateDir = getenv('PMSS_TRAFFIC_LIMIT_STATE_DIR');
        $prevHomeDir = getenv('PMSS_HOME_DIR');
        putenv('PMSS_TRAFFIC_LIMIT_STATE_DIR='.$stateDir);
        putenv('PMSS_HOME_DIR='.$homeDir);

        try {
            $config = \networkBuildFireqosConfig(
                ['interface' => 'eth0', 'speed' => 1000, 'throttle' => ['max' => 100]],
                ['root'],
                []
            );
            $this->assertTrue(strpos($config, 'class root ceil 25Mbit') !== false);
        } finally {
            $this->pmssRestoreEnv('PMSS_TRAFFIC_LIMIT_STATE_DIR', $prevStateDir, true);
            $this->pmssRestoreEnv('PMSS_HOME_DIR', $prevHomeDir, true);
            $this->pmssRemoveTree($stateDir);
            $this->pmssRemoveTree($homeDir);
        }
    }

    public function testBuildFireqosConfigUsesSlidingThrottleWhenPresent(): void
    {
        $stateDir = $this->pmssMakeTempDir('pmss-fireqos-state-', 0700);
        $homeDir = $this->pmssMakeTempDir('pmss-fireqos-home-', 0700);
        @mkdir($homeDir.'/root', 0755, true);
        @file_put_contents($stateDir.'/root.enabled', '1');
        @file_put_contents($homeDir.'/root/.throttle', '25');
        @file_put_contents($stateDir.'/root.throttle_mbit', '333');

        $prevStateDir = getenv('PMSS_TRAFFIC_LIMIT_STATE_DIR');
        $prevHomeDir = getenv('PMSS_HOME_DIR');
        putenv('PMSS_TRAFFIC_LIMIT_STATE_DIR='.$stateDir);
        putenv('PMSS_HOME_DIR='.$homeDir);

        try {
            $config = \networkBuildFireqosConfig(
                ['interface' => 'eth0', 'speed' => 1000, 'throttle' => ['max' => 100]],
                ['root'],
                []
            );
            $this->assertTrue(strpos($config, 'class root ceil 333Mbit') !== false);
        } finally {
            $this->pmssRestoreEnv('PMSS_TRAFFIC_LIMIT_STATE_DIR', $prevStateDir, true);
            $this->pmssRestoreEnv('PMSS_HOME_DIR', $prevHomeDir, true);
            $this->pmssRemoveTree($stateDir);
            $this->pmssRemoveTree($homeDir);
        }
    }

    public function testBuildFireqosConfigFallsBackWhenSlidingThrottleInvalid(): void
    {
        $stateDir = $this->pmssMakeTempDir('pmss-fireqos-state-', 0700);
        $homeDir = $this->pmssMakeTempDir('pmss-fireqos-home-', 0700);
        @mkdir($homeDir.'/root', 0755, true);
        @file_put_contents($stateDir.'/root.enabled', '1');
        @file_put_contents($homeDir.'/root/.throttle', '25');
        @file_put_contents($stateDir.'/root.throttle_mbit', 'nope');

        $prevStateDir = getenv('PMSS_TRAFFIC_LIMIT_STATE_DIR');
        $prevHomeDir = getenv('PMSS_HOME_DIR');
        putenv('PMSS_TRAFFIC_LIMIT_STATE_DIR='.$stateDir);
        putenv('PMSS_HOME_DIR='.$homeDir);

        try {
            $config = \networkBuildFireqosConfig(
                ['interface' => 'eth0', 'speed' => 1000, 'throttle' => ['max' => 100]],
                ['root'],
                []
            );
            $this->assertTrue(strpos($config, 'class root ceil 25Mbit') !== false);
        } finally {
            $this->pmssRestoreEnv('PMSS_TRAFFIC_LIMIT_STATE_DIR', $prevStateDir, true);
            $this->pmssRestoreEnv('PMSS_HOME_DIR', $prevHomeDir, true);
            $this->pmssRemoveTree($stateDir);
            $this->pmssRemoveTree($homeDir);
        }
    }

    public function testBuildFireqosConfigFallsBackToDefaultCapWhenThrottleMissing(): void
    {
        $stateDir = $this->pmssMakeTempDir('pmss-fireqos-state-', 0700);
        $homeDir = $this->pmssMakeTempDir('pmss-fireqos-home-', 0700);
        @mkdir($homeDir.'/root', 0755, true);
        @file_put_contents($stateDir.'/root.enabled', '1');

        $prevStateDir = getenv('PMSS_TRAFFIC_LIMIT_STATE_DIR');
        $prevHomeDir = getenv('PMSS_HOME_DIR');
        putenv('PMSS_TRAFFIC_LIMIT_STATE_DIR='.$stateDir);
        putenv('PMSS_HOME_DIR='.$homeDir);

        try {
            $config = \networkBuildFireqosConfig(
                ['interface' => 'eth0', 'speed' => 1000, 'throttle' => ['max' => 90]],
                ['root'],
                []
            );
            $this->assertTrue(strpos($config, 'class root ceil 90Mbit') !== false);
        } finally {
            $this->pmssRestoreEnv('PMSS_TRAFFIC_LIMIT_STATE_DIR', $prevStateDir, true);
            $this->pmssRestoreEnv('PMSS_HOME_DIR', $prevHomeDir, true);
            $this->pmssRemoveTree($stateDir);
            $this->pmssRemoveTree($homeDir);
        }
    }

    public function testBuildFireqosConfigSkipsCapWhenNotEnabled(): void
    {
        $stateDir = $this->pmssMakeTempDir('pmss-fireqos-state-', 0700);
        $homeDir = $this->pmssMakeTempDir('pmss-fireqos-home-', 0700);
        $templatePath = sys_get_temp_dir().'/fireqos-template-'.bin2hex(random_bytes(4)).'.conf';
        @mkdir($homeDir.'/root', 0755, true);
        @file_put_contents($homeDir.'/root/.throttle', '10');
        @file_put_contents($templatePath, "interface ##INTERFACE\nrate ##SPEED\n##LOCALNETWORK\n##USERMATCHES\n");

        $prevStateDir = getenv('PMSS_TRAFFIC_LIMIT_STATE_DIR');
        $prevHomeDir = getenv('PMSS_HOME_DIR');
        $prevTemplate = getenv('PMSS_FIREQOS_TEMPLATE');
        putenv('PMSS_TRAFFIC_LIMIT_STATE_DIR='.$stateDir);
        putenv('PMSS_HOME_DIR='.$homeDir);
        putenv('PMSS_FIREQOS_TEMPLATE='.$templatePath);

        try {
            $config = \networkBuildFireqosConfig(
                ['interface' => 'eth0', 'speed' => 1000, 'throttle' => ['max' => 80]],
                ['root'],
                []
            );
            $this->assertTrue(strpos($config, 'ceil') === false);
        } finally {
            $this->pmssRestoreEnv('PMSS_TRAFFIC_LIMIT_STATE_DIR', $prevStateDir, true);
            $this->pmssRestoreEnv('PMSS_HOME_DIR', $prevHomeDir, true);
            $this->pmssRestoreEnv('PMSS_FIREQOS_TEMPLATE', $prevTemplate, true);
            @unlink($templatePath);
            $this->pmssRemoveTree($stateDir);
            $this->pmssRemoveTree($homeDir);
        }
    }
}
