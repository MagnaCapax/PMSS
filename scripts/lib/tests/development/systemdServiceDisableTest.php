<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/update/runtime/commands.php';
require_once dirname(__DIR__, 2).'/update/runtime/profile.php';
require_once dirname(__DIR__, 2).'/update/services/systemd.php';

class SystemdServiceDisableTest extends TestCase
{
    private function reset(): void
    {
        unset($GLOBALS['PMSS_PROFILE'], $GLOBALS['PMSS_LAST_COMMAND_OUTPUT']);
    }

    public function testSeedboxSystemServicesDisableIsLoggedInDryRun(): void
    {
        $this->reset();
        putenv('PMSS_DRY_RUN=1');
        pmssStopDisableMaskSeedboxSystemServices();
        putenv('PMSS_DRY_RUN');

        $commands = array_map(static function (array $entry): string {
            return (string) ($entry['command'] ?? '');
        }, $GLOBALS['PMSS_PROFILE'] ?? []);

        $joined = implode("\n", $commands);
        $this->assertTrue(strpos($joined, "systemctl stop 'deluged'") !== false);
        $this->assertTrue(strpos($joined, "systemctl disable 'deluged'") !== false);
        $this->assertTrue(strpos($joined, "systemctl mask 'deluged'") !== false);
        $this->assertTrue(strpos($joined, "systemctl stop 'deluge-web'") !== false);
        $this->assertTrue(strpos($joined, "systemctl disable 'deluge-web'") !== false);
        $this->assertTrue(strpos($joined, "systemctl mask 'deluge-web'") !== false);
        $this->assertTrue(strpos($joined, "systemctl stop 'lighttpd'") !== false);
        $this->assertTrue(strpos($joined, "systemctl disable 'lighttpd'") !== false);
        $this->assertTrue(strpos($joined, "systemctl mask 'qbittorrent-nox'") !== false);
        $this->assertTrue(strpos($joined, "systemctl mask 'exim4'") !== false);
        $this->assertTrue(strpos($joined, "systemctl mask 'transmission-daemon'") !== false);
        $this->assertTrue(strpos($joined, "systemctl mask 'redis-server'") !== false);
        $this->assertTrue(strpos($joined, "systemctl mask 'memcached'") !== false);
        $this->assertTrue(strpos($joined, "systemctl mask 'rpcbind'") !== false);
        $this->assertTrue(strpos($joined, "systemctl mask 'nfs-kernel-server'") !== false);
        $this->assertTrue(strpos($joined, "systemctl mask 'smbd'") !== false);
        $this->assertTrue(strpos($joined, "systemctl mask 'avahi-daemon'") !== false);
        $this->assertTrue(strpos($joined, "systemctl mask 'cups'") !== false);
        $this->assertTrue(strpos($joined, "systemctl mask 'docker.service'") !== false);
        $this->assertTrue(strpos($joined, "systemctl mask 'docker.socket'") !== false);
    }
}
