<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/update/services/systemd.php';

class SystemdServiceDisableTest extends TestCase
{
    public function testEximSpoolCleanupCommandOnlyAllowsExactManagedDirectories(): void
    {
        foreach (\pmssEximSpoolCleanupDirectories() as $dir) {
            $this->assertSame(
                'find '.escapeshellarg($dir).' -xdev -type f -delete 2>/dev/null || true',
                \pmssEximSpoolCleanupCommand($dir)
            );
        }

        foreach ([
            '/var/spool/exim4',
            '/var/spool/exim4/input/..',
            '/var/spool/exim4/input/child',
            '/var/spool/exim4/input'."\0".'suffix',
            '/tmp/exim4/input',
        ] as $unsafeDir) {
            $this->assertSame(null, \pmssEximSpoolCleanupCommand($unsafeDir), $unsafeDir);
        }
    }

    public function testSeedboxSystemServicesDisableIsLoggedInDryRun(): void
    {
        $this->pmssResetRuntimeProfile();
        $this->pmssWithEnv(['PMSS_DRY_RUN' => '1'], function (): void {
            pmssStopDisableMaskSeedboxSystemServices();
        });

        $commands = $this->pmssProfileCommands();
        $joined = implode("\n", $commands);
        foreach ([
            "systemctl stop 'deluged'",
            "systemctl disable 'deluged'",
            "systemctl mask 'deluged'",
            "systemctl stop 'deluge-web'",
            "systemctl disable 'deluge-web'",
            "systemctl mask 'deluge-web'",
            "systemctl stop 'lighttpd'",
            "systemctl disable 'lighttpd'",
            "systemctl mask 'qbittorrent-nox'",
            "systemctl mask 'exim4'",
            "systemctl mask 'transmission-daemon'",
            "systemctl mask 'redis-server'",
            "systemctl mask 'memcached'",
            "systemctl mask 'rpcbind'",
            "systemctl mask 'nfs-kernel-server'",
            "systemctl mask 'smbd'",
            "systemctl mask 'avahi-daemon'",
            "systemctl mask 'cups'",
            "systemctl mask 'apache2'",
            "systemctl mask 'docker.service'",
            "systemctl mask 'docker.socket'",
            'apt-get -o Dpkg::Options::=--force-confdef -o Dpkg::Options::=--force-confold purge -y exim4 exim4-base exim4-config exim4-daemon-light',
            'apt-get -o Dpkg::Options::=--force-confdef -o Dpkg::Options::=--force-confold autoremove -y',
            "find '/var/spool/exim4/input' -xdev -type f -delete",
            "find '/var/spool/exim4/msglog' -xdev -type f -delete",
            "find '/var/spool/exim4/db' -xdev -type f -delete",
        ] as $fragment) {
            $this->assertStringContainsString($fragment, $joined);
        }

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
