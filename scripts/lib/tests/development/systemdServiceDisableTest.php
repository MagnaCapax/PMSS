<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/update/runtime/commands.php';
require_once dirname(__DIR__, 2).'/update/runtime/profile.php';
require_once dirname(__DIR__, 2).'/update/services/systemd.php';

class SystemdServiceDisableTest extends TestCase
{
    public function testSeedboxSystemServicesDisableIsLoggedInDryRun(): void
    {
        $this->pmssResetRuntimeProfile();
        putenv('PMSS_DRY_RUN=1');
        pmssStopDisableMaskSeedboxSystemServices();
        putenv('PMSS_DRY_RUN');

        $commands = $this->pmssProfileCommands();

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
        $this->assertTrue(strpos($joined, "systemctl mask 'apache2'") !== false);
        $this->assertTrue(strpos($joined, "systemctl mask 'docker.service'") !== false);
        $this->assertTrue(strpos($joined, "systemctl mask 'docker.socket'") !== false);
        $this->assertTrue(strpos($joined, 'apt-get -o Dpkg::Options::=--force-confdef -o Dpkg::Options::=--force-confold purge -y exim4 exim4-base exim4-config exim4-daemon-light') !== false);
        $this->assertTrue(strpos($joined, 'apt-get -o Dpkg::Options::=--force-confdef -o Dpkg::Options::=--force-confold autoremove -y') !== false);
        $this->assertTrue(strpos($joined, "find '/var/spool/exim4/input' -xdev -type f -delete") !== false);
        $this->assertTrue(strpos($joined, "find '/var/spool/exim4/msglog' -xdev -type f -delete") !== false);
        $this->assertTrue(strpos($joined, "find '/var/spool/exim4/db' -xdev -type f -delete") !== false);

        $purgeIndex = array_search(
            \aptCmd('purge -y exim4 exim4-base exim4-config exim4-daemon-light'),
            $commands,
            true
        );
        $autoremoveIndex = array_search(
            \aptCmd('autoremove -y'),
            $commands,
            true
        );
        $inputIndex = array_search("find '/var/spool/exim4/input' -xdev -type f -delete 2>/dev/null || true", $commands, true);
        $msglogIndex = array_search("find '/var/spool/exim4/msglog' -xdev -type f -delete 2>/dev/null || true", $commands, true);
        $dbIndex = array_search("find '/var/spool/exim4/db' -xdev -type f -delete 2>/dev/null || true", $commands, true);

        $this->assertTrue(
            $purgeIndex !== false
            && $autoremoveIndex !== false
            && $inputIndex !== false
            && $msglogIndex !== false
            && $dbIndex !== false
            && $purgeIndex < $autoremoveIndex
            && $autoremoveIndex < $inputIndex
            && $inputIndex < $msglogIndex
            && $msglogIndex < $dbIndex,
            'Expected exim purge, autoremove, and spool cleanup commands to remain ordered'
        );
    }
}
