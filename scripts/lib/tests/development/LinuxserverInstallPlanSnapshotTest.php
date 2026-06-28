<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/user/dockerInstallLsio.php';

class LinuxserverInstallPlanSnapshotTest extends TestCase
{
    public function testLsioAppInstallPlansKeepCommandAndDirectoryShape(): void
    {
        $home = '/home/example';
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
                'mkdirPaths' => array('/home/example/docker/jellyfin/config', '/home/example/media'),
                'credentialFile' => '',
                'dbName' => '',
                'dbUser' => '',
                'command' => array('docker', 'run', '-d', '--name', 'jellyfin', '-e', 'PUID=0', '-e', 'PGID=0', '-e', 'TZ=UTC', '--network', 'pmss-media', '-p', '8096:8096', '-v', '/home/example/docker/jellyfin/config:/config', '-v', '/home/example/media:/data', '--restart', 'unless-stopped', 'lscr.io/linuxserver/jellyfin:latest'),
            ),
            'qbittorrent' => array(
                'mkdirPaths' => array('/home/example/docker/qbittorrent/config', '/home/example/downloads'),
                'credentialFile' => '',
                'dbName' => '',
                'dbUser' => '',
                'command' => array('docker', 'run', '-d', '--name', 'qbittorrent', '-e', 'PUID=0', '-e', 'PGID=0', '-e', 'TZ=UTC', '--network', 'pmss-media', '-p', '8080:8080', '-e', 'WEBUI_PORT=8080', '-v', '/home/example/docker/qbittorrent/config:/config', '-v', '/home/example/downloads:/downloads', '--restart', 'unless-stopped', 'lscr.io/linuxserver/qbittorrent:latest'),
            ),
            'radarr' => array(
                'mkdirPaths' => array('/home/example/docker/radarr/config', '/home/example/movies', '/home/example/downloads'),
                'credentialFile' => '',
                'dbName' => '',
                'dbUser' => '',
                'command' => array('docker', 'run', '-d', '--name', 'radarr', '-e', 'PUID=0', '-e', 'PGID=0', '-e', 'TZ=UTC', '--network', 'pmss-media', '-p', '7878:7878', '-v', '/home/example/docker/radarr/config:/config', '-v', '/home/example/movies:/movies', '-v', '/home/example/downloads:/downloads', '--restart', 'unless-stopped', 'lscr.io/linuxserver/radarr:latest'),
            ),
            'sonarr' => array(
                'mkdirPaths' => array('/home/example/docker/sonarr/config', '/home/example/tv', '/home/example/downloads'),
                'credentialFile' => '',
                'dbName' => '',
                'dbUser' => '',
                'command' => array('docker', 'run', '-d', '--name', 'sonarr', '-e', 'PUID=0', '-e', 'PGID=0', '-e', 'TZ=UTC', '--network', 'pmss-media', '-p', '8989:8989', '-v', '/home/example/docker/sonarr/config:/config', '-v', '/home/example/tv:/tv', '-v', '/home/example/downloads:/downloads', '--restart', 'unless-stopped', 'lscr.io/linuxserver/sonarr:latest'),
            ),
            'prowlarr' => array(
                'mkdirPaths' => array('/home/example/docker/prowlarr/config'),
                'credentialFile' => '',
                'dbName' => '',
                'dbUser' => '',
                'command' => array('docker', 'run', '-d', '--name', 'prowlarr', '-e', 'PUID=0', '-e', 'PGID=0', '-e', 'TZ=UTC', '--network', 'pmss-media', '-p', '9696:9696', '-v', '/home/example/docker/prowlarr/config:/config', '--restart', 'unless-stopped', 'lscr.io/linuxserver/prowlarr:latest'),
            ),
            'mariadb' => array(
                'mkdirPaths' => array('/home/example/docker/mariadb/config'),
                'credentialFile' => '/home/example/docker/mariadb/pmss-credentials.env',
                'dbName' => 'db_example_app',
                'dbUser' => 'db_example',
                'command' => array('docker', 'run', '-d', '--name', 'mariadb', '-e', 'PUID=0', '-e', 'PGID=0', '-e', 'TZ=UTC', '--network', 'pmss-media', '-p', '127.0.0.1:3306:3306', '-e', 'MYSQL_ROOT_PASSWORD=<generated-at-install>', '-e', 'MYSQL_DATABASE=db_example_app', '-e', 'MYSQL_USER=db_example', '-e', 'MYSQL_PASSWORD=<generated-at-install>', '-v', '/home/example/docker/mariadb/config:/config', '--restart', 'unless-stopped', 'lscr.io/linuxserver/mariadb:latest'),
            ),
            'phpmyadmin' => array(
                'mkdirPaths' => array('/home/example/docker/phpmyadmin/config'),
                'credentialFile' => '',
                'dbName' => '',
                'dbUser' => '',
                'command' => array('docker', 'run', '-d', '--name', 'phpmyadmin', '-e', 'PUID=0', '-e', 'PGID=0', '-e', 'TZ=UTC', '--network', 'pmss-media', '-p', '127.0.0.1:8082:80', '-e', 'PMA_HOST=mariadb', '-e', 'PMA_PORT=3306', '-v', '/home/example/docker/phpmyadmin/config:/config', '--restart', 'unless-stopped', 'lscr.io/linuxserver/phpmyadmin:latest'),
            ),
        ), $snapshot);
    }

    public function testLsioMariadbInstallPlanUsesEnvFileOutsideDryRun(): void
    {
        $spec = \pmssDockerInstallLsioAppSpec('mariadb', '/home/example', false);

        $this->assertSame('/home/example/docker/mariadb/pmss-credentials.env', $spec['credentialFile']);
        $this->assertSame(array('--env-file', '/home/example/docker/mariadb/pmss-credentials.env'), $spec['extraArgs']);
        $this->assertSame(
            array('docker', 'run', '-d', '--name', 'mariadb', '-e', 'PUID=0', '-e', 'PGID=0', '-e', 'TZ=UTC', '--network', 'pmss-media', '-p', '127.0.0.1:3306:3306', '--env-file', '/home/example/docker/mariadb/pmss-credentials.env', '-v', '/home/example/docker/mariadb/config:/config', '--restart', 'unless-stopped', 'lscr.io/linuxserver/mariadb:latest'),
            \pmssDockerInstallLsioDockerRunCommand('mariadb', (string) $spec['defaultPort'], 'UTC', $spec)
        );
    }
}
