<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 4).'/etc/skel/bin/dockerInstallLsio.php';

class LinuxserverInstallPlanSnapshotTest extends TestCase
{
    public function testLsioAppInstallPlansKeepCommandAndDirectoryShape(): void
    {
        $home = '/srv/pmss-test-home';
        $snapshot = array();
        foreach (array_keys(\pmssDockerInstallLsioAppCatalog()) as $app) {
            $spec = \pmssDockerInstallLsioAppSpec($app, $home, true);
            $snapshot[$app] = array(
                'mkdirPaths' => $spec['mkdirPaths'],
                'credentialFile' => $spec['credentialFile'],
                'dbName' => $spec['dbName'] ?? '',
                'dbUser' => $spec['dbUser'] ?? '',
                'command' => \pmssDockerInstallLsioDockerRunCommand($app, (string) $spec['defaultPort'], 'UTC', $spec),
            );
        }

        $this->assertSame(array(
            'jellyfin' => array(
                'mkdirPaths' => array('/srv/pmss-test-home/docker/jellyfin/config', '/srv/pmss-test-home/media'),
                'credentialFile' => '',
                'dbName' => '',
                'dbUser' => '',
                'command' => array('docker', 'run', '-d', '--name', 'jellyfin', '-e', 'PUID=0', '-e', 'PGID=0', '-e', 'TZ=UTC', '--network', 'pmss-media', '-p', '127.0.0.1:8096:8096', '-v', '/srv/pmss-test-home/docker/jellyfin/config:/config', '-v', '/srv/pmss-test-home/media:/data', '--restart', 'unless-stopped', 'lscr.io/linuxserver/jellyfin:latest'),
            ),
            'qbittorrent' => array(
                'mkdirPaths' => array('/srv/pmss-test-home/docker/qbittorrent/config', '/srv/pmss-test-home/downloads'),
                'credentialFile' => '',
                'dbName' => '',
                'dbUser' => '',
                'command' => array('docker', 'run', '-d', '--name', 'qbittorrent', '-e', 'PUID=0', '-e', 'PGID=0', '-e', 'TZ=UTC', '--network', 'pmss-media', '-p', '127.0.0.1:8080:8080', '-e', 'WEBUI_PORT=8080', '-v', '/srv/pmss-test-home/docker/qbittorrent/config:/config', '-v', '/srv/pmss-test-home/downloads:/downloads', '--restart', 'unless-stopped', 'lscr.io/linuxserver/qbittorrent:latest'),
            ),
            'radarr' => array(
                'mkdirPaths' => array('/srv/pmss-test-home/docker/radarr/config', '/srv/pmss-test-home/movies', '/srv/pmss-test-home/downloads'),
                'credentialFile' => '',
                'dbName' => '',
                'dbUser' => '',
                'command' => array('docker', 'run', '-d', '--name', 'radarr', '-e', 'PUID=0', '-e', 'PGID=0', '-e', 'TZ=UTC', '--network', 'pmss-media', '-p', '127.0.0.1:7878:7878', '-v', '/srv/pmss-test-home/docker/radarr/config:/config', '-v', '/srv/pmss-test-home/movies:/movies', '-v', '/srv/pmss-test-home/downloads:/downloads', '--restart', 'unless-stopped', 'lscr.io/linuxserver/radarr:latest'),
            ),
            'sonarr' => array(
                'mkdirPaths' => array('/srv/pmss-test-home/docker/sonarr/config', '/srv/pmss-test-home/tv', '/srv/pmss-test-home/downloads'),
                'credentialFile' => '',
                'dbName' => '',
                'dbUser' => '',
                'command' => array('docker', 'run', '-d', '--name', 'sonarr', '-e', 'PUID=0', '-e', 'PGID=0', '-e', 'TZ=UTC', '--network', 'pmss-media', '-p', '127.0.0.1:8989:8989', '-v', '/srv/pmss-test-home/docker/sonarr/config:/config', '-v', '/srv/pmss-test-home/tv:/tv', '-v', '/srv/pmss-test-home/downloads:/downloads', '--restart', 'unless-stopped', 'lscr.io/linuxserver/sonarr:latest'),
            ),
            'prowlarr' => array(
                'mkdirPaths' => array('/srv/pmss-test-home/docker/prowlarr/config'),
                'credentialFile' => '',
                'dbName' => '',
                'dbUser' => '',
                'command' => array('docker', 'run', '-d', '--name', 'prowlarr', '-e', 'PUID=0', '-e', 'PGID=0', '-e', 'TZ=UTC', '--network', 'pmss-media', '-p', '127.0.0.1:9696:9696', '-v', '/srv/pmss-test-home/docker/prowlarr/config:/config', '--restart', 'unless-stopped', 'lscr.io/linuxserver/prowlarr:latest'),
            ),
            'mariadb' => array(
                'mkdirPaths' => array('/srv/pmss-test-home/docker/mariadb/config'),
                'credentialFile' => '/srv/pmss-test-home/docker/mariadb/pmss-credentials.env',
                'dbName' => 'db_pmss_test_home_app',
                'dbUser' => 'db_pmss_test_home',
                'command' => array('docker', 'run', '-d', '--name', 'mariadb', '-e', 'PUID=0', '-e', 'PGID=0', '-e', 'TZ=UTC', '--network', 'pmss-media', '-p', '127.0.0.1:3306:3306', '-e', 'MYSQL_ROOT_PASSWORD=<generated-at-install>', '-e', 'MYSQL_DATABASE=db_pmss_test_home_app', '-e', 'MYSQL_USER=db_pmss_test_home', '-e', 'MYSQL_PASSWORD=<generated-at-install>', '-v', '/srv/pmss-test-home/docker/mariadb/config:/config', '--restart', 'unless-stopped', 'lscr.io/linuxserver/mariadb:latest'),
            ),
            'phpmyadmin' => array(
                'mkdirPaths' => array('/srv/pmss-test-home/docker/phpmyadmin/config'),
                'credentialFile' => '',
                'dbName' => '',
                'dbUser' => '',
                'command' => array('docker', 'run', '-d', '--name', 'phpmyadmin', '-e', 'PUID=0', '-e', 'PGID=0', '-e', 'TZ=UTC', '--network', 'pmss-media', '-p', '127.0.0.1:8082:80', '-e', 'PMA_HOST=mariadb', '-e', 'PMA_PORT=3306', '-v', '/srv/pmss-test-home/docker/phpmyadmin/config:/config', '--restart', 'unless-stopped', 'lscr.io/linuxserver/phpmyadmin:latest'),
            ),
        ), $snapshot);
    }

    public function testJellyfinCustomPortAlsoPublishesOnlyOnLoopback(): void
    {
        $spec = \pmssDockerInstallLsioAppSpec('jellyfin', '/srv/pmss-test-home', true);
        $command = \pmssDockerInstallLsioDockerRunCommand('jellyfin', '18096', 'UTC', $spec);

        $this->assertSame('127.0.0.1:18096:8096', $command[array_search('-p', $command, true) + 1]);
    }

    public function testEveryCatalogAppPublishesOnLoopbackByDefault(): void
    {
        foreach (array_keys(\pmssDockerInstallLsioAppCatalog()) as $app) {
            $spec = \pmssDockerInstallLsioAppSpec($app, '/srv/pmss-test-home', true);
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
            array('docker', 'run', '-d', '--name', 'mariadb', '-e', 'PUID=0', '-e', 'PGID=0', '-e', 'TZ=UTC', '--network', 'pmss-media', '-p', '127.0.0.1:3306:3306', '--env-file', '/srv/pmss-test-home/docker/mariadb/pmss-credentials.env', '-v', '/srv/pmss-test-home/docker/mariadb/config:/config', '--restart', 'unless-stopped', 'lscr.io/linuxserver/mariadb:latest'),
            \pmssDockerInstallLsioDockerRunCommand('mariadb', (string) $spec['defaultPort'], 'UTC', $spec)
        );
    }
}
