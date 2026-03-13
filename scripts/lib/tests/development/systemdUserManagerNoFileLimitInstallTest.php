<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/update/systemPrep/systemdSlicesDropinInstall.php';

class SystemdUserManagerNoFileLimitInstallTest extends TestCase
{
    private function tempDir(string $prefix): string
    {
        $dir = sys_get_temp_dir().'/pmss-systemd-user-at-'.bin2hex(random_bytes(4)).'-'.$prefix;
        @mkdir($dir, 0700, true);
        return $dir;
    }

    private function withDropinDir(string $dir, callable $callback): void
    {
        $previous = getenv('PMSS_SYSTEMD_USER_AT_SERVICE_DIR');
        putenv('PMSS_SYSTEMD_USER_AT_SERVICE_DIR='.$dir);
        try {
            $callback();
        } finally {
            if ($previous === false) {
                putenv('PMSS_SYSTEMD_USER_AT_SERVICE_DIR');
            } else {
                putenv('PMSS_SYSTEMD_USER_AT_SERVICE_DIR='.$previous);
            }
        }
    }

    public function testInstallsConfiguredSoftHardLimits(): void
    {
        $dir = $this->tempDir('install');
        $this->withDropinDir($dir, function (): void {
            \pmssSystemdUserManagerNoFileLimitInstall([
                'limitNoFileSoft' => 8192,
                'limitNoFileHard' => 16384,
            ], function (): void {
            });
        });

        $target = $dir.'/20-pmss-limits.conf';
        $this->assertTrue(is_file($target), 'Expected user@.service drop-in to be created');
        $body = (string)file_get_contents($target);
        $this->assertStringContainsString('LimitNOFILE=8192:16384', $body);
    }

    public function testClampsHardLimitUpToSoftLimit(): void
    {
        $dir = $this->tempDir('clamp');
        $this->withDropinDir($dir, function (): void {
            \pmssSystemdUserManagerNoFileLimitInstall([
                'limitNoFileSoft' => 4096,
                'limitNoFileHard' => 1024,
            ], function (): void {
            });
        });

        $target = $dir.'/20-pmss-limits.conf';
        $this->assertTrue(is_file($target), 'Expected user@.service drop-in to be created');
        $body = (string)file_get_contents($target);
        $this->assertStringContainsString('LimitNOFILE=4096:4096', $body);
    }

    public function testSkipsWhenPolicyDoesNotProvideNoFileValues(): void
    {
        $dir = $this->tempDir('skip');
        $this->withDropinDir($dir, function (): void {
            \pmssSystemdUserManagerNoFileLimitInstall([
                'cpuWeight' => 100,
            ], function (): void {
            });
        });

        $target = $dir.'/20-pmss-limits.conf';
        $this->assertTrue(!is_file($target), 'Unexpected user@.service drop-in without LimitNOFILE policy');
    }

    public function testInstallsLogNamespaceDropin(): void
    {
        $dir = $this->tempDir('log-namespace');
        $this->withDropinDir($dir, function (): void {
            \pmssSystemdUserManagerLogNamespaceInstall(function (): void {
            });
        });

        $target = $dir.'/30-pmss-log-namespace.conf';
        $this->assertTrue(is_file($target), 'Expected user@.service log namespace drop-in to be created');
        $body = (string)file_get_contents($target);
        $this->assertStringContainsString('LogNamespace=user-%i', $body);
    }
}
