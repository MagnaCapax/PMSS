<?php
namespace PMSS\Tests;

require_once dirname(__DIR__, 2).'/network/fireqos.php';

class NetworkFireqosTest extends TestCase
{
    private function createTempDir(string $prefix): string
    {
        $path = sys_get_temp_dir().'/'.$prefix.'-'.bin2hex(random_bytes(4));
        @mkdir($path, 0700, true);
        return $path;
    }

    private function removeTempDir(string $path): void
    {
        if ($path === '' || !is_dir($path)) {
            return;
        }
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $node) {
            if ($node->isDir()) {
                @rmdir($node->getPathname());
            } else {
                @unlink($node->getPathname());
            }
        }
        @rmdir($path);
    }

    private function restoreEnv(string $key, $value): void
    {
        if ($value === false || $value === null || $value === '') {
            putenv($key);
            return;
        }
        putenv($key.'='.$value);
    }

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
        $stateDir = $this->createTempDir('pmss-fireqos-state');
        $homeDir = $this->createTempDir('pmss-fireqos-home');
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
            $this->restoreEnv('PMSS_TRAFFIC_LIMIT_STATE_DIR', $prevStateDir);
            $this->restoreEnv('PMSS_HOME_DIR', $prevHomeDir);
            $this->removeTempDir($stateDir);
            $this->removeTempDir($homeDir);
        }
    }

    public function testBuildFireqosConfigFallsBackToDefaultCapWhenThrottleMissing(): void
    {
        $stateDir = $this->createTempDir('pmss-fireqos-state');
        $homeDir = $this->createTempDir('pmss-fireqos-home');
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
            $this->restoreEnv('PMSS_TRAFFIC_LIMIT_STATE_DIR', $prevStateDir);
            $this->restoreEnv('PMSS_HOME_DIR', $prevHomeDir);
            $this->removeTempDir($stateDir);
            $this->removeTempDir($homeDir);
        }
    }

    public function testBuildFireqosConfigSkipsCapWhenNotEnabled(): void
    {
        $stateDir = $this->createTempDir('pmss-fireqos-state');
        $homeDir = $this->createTempDir('pmss-fireqos-home');
        @mkdir($homeDir.'/root', 0755, true);
        @file_put_contents($homeDir.'/root/.throttle', '10');

        $prevStateDir = getenv('PMSS_TRAFFIC_LIMIT_STATE_DIR');
        $prevHomeDir = getenv('PMSS_HOME_DIR');
        putenv('PMSS_TRAFFIC_LIMIT_STATE_DIR='.$stateDir);
        putenv('PMSS_HOME_DIR='.$homeDir);

        try {
            $config = \networkBuildFireqosConfig(
                ['interface' => 'eth0', 'speed' => 1000, 'throttle' => ['max' => 80]],
                ['root'],
                []
            );
            $this->assertTrue(strpos($config, 'ceil') === false);
        } finally {
            $this->restoreEnv('PMSS_TRAFFIC_LIMIT_STATE_DIR', $prevStateDir);
            $this->restoreEnv('PMSS_HOME_DIR', $prevHomeDir);
            $this->removeTempDir($stateDir);
            $this->removeTempDir($homeDir);
        }
    }
}
