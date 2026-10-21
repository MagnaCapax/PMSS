<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 4).'/etc/skel/bin/dockerInstallLsio.php';

class LinuxserverInstallPlanSnapshotTest extends TestCase
{
    /** Pure command fixtures use non-default reservation values. */
    private function appSpec(string $app, string $home, bool $dryRun): array
    {
        $spec = \pmssDockerInstallLsioAppSpec($app, $home, $dryRun);
        if ($spec['reservedPorts']) {
            $spec['defaultPort'] = '23001';
            $spec['peerPort'] = 23002;
        }
        return $spec;
    }

    public function testLsioAppInstallPlansKeepCommandAndDirectoryShape(): void
    {
        $home = '/srv/pmss-test-home';
        $snapshot = array();
        foreach (array_keys(\pmssDockerInstallLsioAppCatalog()) as $app) {
            $spec = $this->appSpec($app, $home, true);
            $snapshot[$app] = array(
                'mkdirPaths' => $spec['mkdirPaths'],
                'credentialFile' => $spec['credentialFile'],
                'dbName' => $spec['dbName'] ?? '',
                'dbUser' => $spec['dbUser'] ?? '',
                'command' => \pmssDockerInstallLsioDockerRunCommand($app, (string) $spec['defaultPort'], 'UTC', $spec, static function (): bool { return false; }),
            );
        }

        $this->assertSame(array(
            'jellyfin' => array(
                'mkdirPaths' => array('/srv/pmss-test-home/docker/jellyfin/config', '/srv/pmss-test-home/media'),
                'credentialFile' => '',
                'dbName' => '',
                'dbUser' => '',
                'command' => array('docker', 'run', '-d', '--name', 'jellyfin', '--label', 'pmss.client=jellyfin', '-e', 'PUID=0', '-e', 'PGID=0', '-e', 'TZ=UTC', '--network', 'pmss-media', '-p', '127.0.0.1:8096:8096', '-v', '/srv/pmss-test-home/docker/jellyfin/config:/config', '-v', '/srv/pmss-test-home/media:/data', '--restart', 'unless-stopped', 'lscr.io/linuxserver/jellyfin:latest'),
            ),
            'qbittorrent' => array(
                'mkdirPaths' => array('/srv/pmss-test-home/docker/qbittorrent/config', '/srv/pmss-test-home/downloads'),
                'credentialFile' => '/srv/pmss-test-home/docker/qbittorrent/pmss-credentials.env',
                'dbName' => '',
                'dbUser' => '',
                'command' => array('docker', 'run', '-d', '--name', 'qbittorrent', '--label', 'pmss.client=qbittorrent', '-e', 'PUID=0', '-e', 'PGID=0', '-e', 'TZ=UTC', '--network', 'pmss-media', '-p', '127.0.0.1:23001:8080', '-p', '0.0.0.0:23002:23002/tcp', '-p', '0.0.0.0:23002:23002/udp', '-e', 'TORRENTING_PORT=23002', '-e', 'WEBUI_PORT=8080', '-v', '/srv/pmss-test-home/docker/qbittorrent/config:/config', '-v', '/srv/pmss-test-home/downloads:/downloads', '--restart', 'unless-stopped', 'lscr.io/linuxserver/qbittorrent:5.2.4_v2.0.15-ls481@sha256:1ea873647c43d566f251a7122aee330b184509c57ee3257ed19f62dac0f8f24b'),
            ),
            'transmission' => array(
                'mkdirPaths' => array('/srv/pmss-test-home/docker/transmission/config', '/srv/pmss-test-home/downloads', '/srv/pmss-test-home/watch'),
                'credentialFile' => '/srv/pmss-test-home/docker/transmission/pmss-credentials.env',
                'dbName' => '',
                'dbUser' => '',
                'command' => array('docker', 'run', '-d', '--name', 'transmission', '--label', 'pmss.client=transmission', '-e', 'PUID=0', '-e', 'PGID=0', '-e', 'TZ=UTC', '--network', 'pmss-media', '-p', '127.0.0.1:23001:9091', '-p', '0.0.0.0:23002:23002/tcp', '-p', '0.0.0.0:23002:23002/udp', '-e', 'PEERPORT=23002', '-e', 'USER=<generated-at-install>', '-e', 'PASS=<generated-at-install>', '-v', '/srv/pmss-test-home/docker/transmission/config:/config', '-v', '/srv/pmss-test-home/downloads:/downloads', '-v', '/srv/pmss-test-home/watch:/watch', '--restart', 'unless-stopped', 'lscr.io/linuxserver/transmission:4.1.3-r0-ls365@sha256:55aaeedf9fc354d34136387e15ee0ba4679096f20398dd2875867e5cd5cf8be5'),
            ),
            'radarr' => array(
                'mkdirPaths' => array('/srv/pmss-test-home/docker/radarr/config', '/srv/pmss-test-home/movies', '/srv/pmss-test-home/downloads'),
                'credentialFile' => '',
                'dbName' => '',
                'dbUser' => '',
                'command' => array('docker', 'run', '-d', '--name', 'radarr', '--label', 'pmss.client=radarr', '-e', 'PUID=0', '-e', 'PGID=0', '-e', 'TZ=UTC', '--network', 'pmss-media', '-p', '127.0.0.1:7878:7878', '-v', '/srv/pmss-test-home/docker/radarr/config:/config', '-v', '/srv/pmss-test-home/movies:/movies', '-v', '/srv/pmss-test-home/downloads:/downloads', '--restart', 'unless-stopped', 'lscr.io/linuxserver/radarr:latest'),
            ),
            'sonarr' => array(
                'mkdirPaths' => array('/srv/pmss-test-home/docker/sonarr/config', '/srv/pmss-test-home/tv', '/srv/pmss-test-home/downloads'),
                'credentialFile' => '',
                'dbName' => '',
                'dbUser' => '',
                'command' => array('docker', 'run', '-d', '--name', 'sonarr', '--label', 'pmss.client=sonarr', '-e', 'PUID=0', '-e', 'PGID=0', '-e', 'TZ=UTC', '--network', 'pmss-media', '-p', '127.0.0.1:8989:8989', '-v', '/srv/pmss-test-home/docker/sonarr/config:/config', '-v', '/srv/pmss-test-home/tv:/tv', '-v', '/srv/pmss-test-home/downloads:/downloads', '--restart', 'unless-stopped', 'lscr.io/linuxserver/sonarr:latest'),
            ),
            'prowlarr' => array(
                'mkdirPaths' => array('/srv/pmss-test-home/docker/prowlarr/config'),
                'credentialFile' => '',
                'dbName' => '',
                'dbUser' => '',
                'command' => array('docker', 'run', '-d', '--name', 'prowlarr', '--label', 'pmss.client=prowlarr', '-e', 'PUID=0', '-e', 'PGID=0', '-e', 'TZ=UTC', '--network', 'pmss-media', '-p', '127.0.0.1:9696:9696', '-v', '/srv/pmss-test-home/docker/prowlarr/config:/config', '--restart', 'unless-stopped', 'lscr.io/linuxserver/prowlarr:latest'),
            ),
            'mariadb' => array(
                'mkdirPaths' => array('/srv/pmss-test-home/docker/mariadb/config'),
                'credentialFile' => '/srv/pmss-test-home/docker/mariadb/pmss-credentials.env',
                'dbName' => 'db_pmss_test_home_app',
                'dbUser' => 'db_pmss_test_home',
                'command' => array('docker', 'run', '-d', '--name', 'mariadb', '--label', 'pmss.client=mariadb', '-e', 'PUID=0', '-e', 'PGID=0', '-e', 'TZ=UTC', '--network', 'pmss-media', '-p', '127.0.0.1:3306:3306', '-e', 'MYSQL_ROOT_PASSWORD=<generated-at-install>', '-e', 'MYSQL_DATABASE=db_pmss_test_home_app', '-e', 'MYSQL_USER=db_pmss_test_home', '-e', 'MYSQL_PASSWORD=<generated-at-install>', '-v', '/srv/pmss-test-home/docker/mariadb/config:/config', '--restart', 'unless-stopped', 'lscr.io/linuxserver/mariadb:latest'),
            ),
            'phpmyadmin' => array(
                'mkdirPaths' => array('/srv/pmss-test-home/docker/phpmyadmin/config'),
                'credentialFile' => '',
                'dbName' => '',
                'dbUser' => '',
                'command' => array('docker', 'run', '-d', '--name', 'phpmyadmin', '--label', 'pmss.client=phpmyadmin', '-e', 'PUID=0', '-e', 'PGID=0', '-e', 'TZ=UTC', '--network', 'pmss-media', '-p', '127.0.0.1:8082:80', '-e', 'PMA_HOST=mariadb', '-e', 'PMA_PORT=3306', '-v', '/srv/pmss-test-home/docker/phpmyadmin/config:/config', '--restart', 'unless-stopped', 'lscr.io/linuxserver/phpmyadmin:latest'),
            ),
        ), $snapshot);
    }

    public function testJellyfinCustomPortAlsoPublishesOnlyOnLoopback(): void
    {
        $spec = \pmssDockerInstallLsioAppSpec('jellyfin', '/srv/pmss-test-home', true);
        $command = \pmssDockerInstallLsioDockerRunCommand('jellyfin', '18096', 'UTC', $spec);

        $this->assertSame('127.0.0.1:18096:8096', $command[array_search('-p', $command, true) + 1]);
    }

    public function testJellyfinGetsRenderDeviceWhenProbeFindsOne(): void
    {
        $spec = \pmssDockerInstallLsioAppSpec('jellyfin', '/srv/pmss-test-home', true);
        $command = \pmssDockerInstallLsioDockerRunCommand('jellyfin', '8096', 'UTC', $spec, static function (): bool { return true; });

        $this->assertSame('/dev/dri:/dev/dri', $command[array_search('--device', $command, true) + 1]);
        $this->assertStringContainsString('--device /dev/dri:/dev/dri', \pmssDockerInstallLsioDisplayCommand($command));
    }

    public function testJellyfinOmitsRenderDeviceWhenProbeFindsNone(): void
    {
        $spec = \pmssDockerInstallLsioAppSpec('jellyfin', '/srv/pmss-test-home', true);
        $command = \pmssDockerInstallLsioDockerRunCommand('jellyfin', '8096', 'UTC', $spec, static function (): bool { return false; });

        $this->assertSame(false, in_array('--device', $command, true));
    }

    public function testOtherAppsNeverProbeOrReceiveRenderDevice(): void
    {
        foreach (array_keys(\pmssDockerInstallLsioAppCatalog()) as $app) {
            if ($app === 'jellyfin') {
                continue;
            }
            $spec = $this->appSpec($app, '/srv/pmss-test-home', true);
            $command = \pmssDockerInstallLsioDockerRunCommand($app, (string) $spec['defaultPort'], 'UTC', $spec, static function (): bool { return true; });
            $this->assertSame(false, in_array('--device', $command, true));
        }
    }

    public function testEveryCatalogAppPublishesOnLoopbackByDefault(): void
    {
        foreach (array_keys(\pmssDockerInstallLsioAppCatalog()) as $app) {
            $spec = $this->appSpec($app, '/srv/pmss-test-home', true);
            $command = \pmssDockerInstallLsioDockerRunCommand($app, (string) $spec['defaultPort'], 'UTC', $spec);
            $publish = $command[array_search('-p', $command, true) + 1];
            $this->assertSame('127.0.0.1:'.$spec['defaultPort'].':'.$spec['containerPort'], $publish);
        }
    }

    public function testExplicitCatalogBindHostIsPreserved(): void
    {
        $spec = \pmssDockerInstallLsioAppSpec('radarr', '/srv/pmss-test-home', true);
        $spec['bindHost'] = '192.0.2.1';
        $command = \pmssDockerInstallLsioDockerRunCommand('radarr', '17878', 'UTC', $spec);
        $this->assertSame('192.0.2.1:17878:7878', $command[array_search('-p', $command, true) + 1]);
    }

    public function testLsioMariadbInstallPlanUsesEnvFileOutsideDryRun(): void
    {
        $spec = \pmssDockerInstallLsioAppSpec('mariadb', '/srv/pmss-test-home', false);

        $this->assertSame('/srv/pmss-test-home/docker/mariadb/pmss-credentials.env', $spec['credentialFile']);
        $this->assertSame(array('--env-file', '/srv/pmss-test-home/docker/mariadb/pmss-credentials.env'), $spec['extraArgs']);
        $this->assertSame(
            array('docker', 'run', '-d', '--name', 'mariadb', '--label', 'pmss.client=mariadb', '-e', 'PUID=0', '-e', 'PGID=0', '-e', 'TZ=UTC', '--network', 'pmss-media', '-p', '127.0.0.1:3306:3306', '--env-file', '/srv/pmss-test-home/docker/mariadb/pmss-credentials.env', '-v', '/srv/pmss-test-home/docker/mariadb/config:/config', '--restart', 'unless-stopped', 'lscr.io/linuxserver/mariadb:latest'),
            \pmssDockerInstallLsioDockerRunCommand('mariadb', (string) $spec['defaultPort'], 'UTC', $spec)
        );
    }

    public function testTransmissionInstallPlanUsesEnvFileOutsideDryRun(): void
    {
        $spec = $this->appSpec('transmission', '/srv/pmss-test-home', false);
        $command = \pmssDockerInstallLsioDockerRunCommand('transmission', '23001', 'UTC', $spec);

        $this->assertSame(['--env-file', '/srv/pmss-test-home/docker/transmission/pmss-credentials.env'], $spec['extraArgs']);
        $this->assertStringNotContainsString('PASS=', implode(' ', $command));
        $this->assertStringNotContainsString('USER=', implode(' ', $command));
    }
}
