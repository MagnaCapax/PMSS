<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/update/services/systemd.php';

class SystemdServicePolicyCharacterizationTest extends TestCase
{
    public function testSeedboxSystemServicePolicyKeepsOrderedUnitLabels(): void
    {
        $this->assertSame([
            'lighttpd' => 'lighttpd',
            'deluged' => 'Deluge daemon',
            'deluge-web' => 'Deluge Web UI',
            'transmission-daemon' => 'Transmission daemon',
            'redis-server' => 'Redis server',
            'memcached' => 'Memcached',
            'rpcbind' => 'rpcbind',
            'rpcbind.socket' => 'rpcbind socket',
            'nfs-kernel-server' => 'NFS kernel server',
            'nfs-server' => 'NFS server',
            'nfs-idmapd' => 'NFS idmapd',
            'rpc-statd' => 'rpc-statd',
            'smbd' => 'Samba smbd',
            'nmbd' => 'Samba nmbd',
            'samba' => 'Samba (meta)',
            'avahi-daemon' => 'Avahi mDNS',
            'avahi-daemon.socket' => 'Avahi mDNS socket',
            'cups' => 'CUPS printing',
            'cups.socket' => 'CUPS socket',
            'cups.path' => 'CUPS path',
            'cups-browsed' => 'CUPS browsed',
            'docker.service' => 'Docker (system)',
            'docker.socket' => 'Docker socket (system)',
            'containerd' => 'containerd (system)',
            'exim4' => 'Exim4 MTA',
            'qbittorrent-nox' => 'qBittorrent (system)',
        ], \pmssSeedboxSystemServiceSpecs());
    }
}
