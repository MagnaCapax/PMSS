<?php
/**
 * Hermetic coverage for the LinuxServer.io installer wrappers.
 */

namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

class LinuxserverInstallScriptTest extends TestCase
{
    private $tempDir;
    private $homeDir;
    private $fakeBinDir;
    private $dockerLog;
    private $phpBin;
    private $bashBin;
    private $installerPath;
    private $legacyInstallerPath;
    private $utilPath;

    protected function setUp(): void
    {
        $this->pmssAssignTempDirProperty('tempDir', 'pmss-linuxserver-install', 0700);
        $this->homeDir = $this->tempDir.'/home';
        $this->fakeBinDir = $this->tempDir.'/bin';
        $this->dockerLog = $this->tempDir.'/docker.log';
        $this->phpBin = trim((string) shell_exec('command -v php 2>/dev/null'));
        $this->bashBin = trim((string) shell_exec('command -v bash 2>/dev/null'));
        $this->installerPath = __DIR__.'/../../../../etc/skel/bin/docker-install-lsio';
        $this->legacyInstallerPath = __DIR__.'/../../../../etc/skel/bin/linuxserverInstall.sh';
        $this->utilPath = __DIR__.'/../../../../scripts/util/dockerInstallLsio.php';

        @mkdir($this->homeDir, 0700, true);
        @mkdir($this->fakeBinDir, 0700, true);

        $dockerStub = <<<'BASH'
#!/usr/bin/env bash
set -eu
if [[ -n "${PMSS_TEST_DOCKER_LOG:-}" ]]; then
  printf '%s\n' "$*" >>"$PMSS_TEST_DOCKER_LOG"
fi
case "${1:-} ${2:-}" in
  'network inspect')
    [[ "${PMSS_TEST_DOCKER_NETWORK_EXISTS:-0}" == '1' ]] && exit 0
    exit 1
    ;;
  'container inspect')
    [[ "${PMSS_TEST_DOCKER_CONTAINER_EXISTS:-0}" == '1' ]] && exit 0
    exit 1
    ;;
  'info ')
    exit 0
    ;;
esac
exit 0
BASH;
        $this->pmssWriteExecutableFile($this->fakeBinDir.'/docker', $dockerStub, 0700);
    }
    /**
     * @param array<string,string> $env
     * @return array{rc:int,output:string,dockerLog:string}
     */
    private function runHelper(array $args, array $env = [], string $entry = 'current'): array
    {
        $pathValue = array_key_exists('PATH', $env)
            ? $env['PATH']
            : $this->fakeBinDir.':'.(getenv('PATH') !== false ? getenv('PATH') : '/usr/bin:/bin');
        unset($env['PATH']);

        $envPairs = [
            'HOME='.$this->homeDir,
            'PATH='.$pathValue,
            'PMSS_TEST_DOCKER_LOG='.$this->dockerLog,
            'PMSS_DOCKER_INSTALL_LSIO_SCRIPT='.$this->utilPath,
        ];
        foreach ($env as $key => $value) {
            $envPairs[] = $key.'='.$value;
        }

        $entryScript = $entry === 'legacy' ? $this->legacyInstallerPath : $this->installerPath;
        $runner = $entry === 'legacy' ? $this->bashBin : $this->phpBin;
        $command = 'env';
        foreach ($envPairs as $pair) {
            $command .= ' '.escapeshellarg($pair);
        }
        $command .= ' '.escapeshellarg($runner).' '.escapeshellarg($entryScript);
        foreach ($args as $arg) {
            $command .= ' '.escapeshellarg($arg);
        }
        $command .= ' 2>&1';

        $output = [];
        $rc = 0;
        exec($command, $output, $rc);

        return [
            'rc' => $rc,
            'output' => implode("\n", $output),
            'dockerLog' => $this->pmssReadFileOrEmpty($this->dockerLog),
        ];
    }

    public function testDryRunJellyfinDoesNotCreateConfigOrMediaDirs(): void
    {
        $result = $this->runHelper(['jellyfin', '--dry-run']);

        $this->assertEquals(0, $result['rc']);
        $this->assertTrue(!is_dir($this->homeDir.'/docker/jellyfin/config'), 'dry-run must not create config dirs');
        $this->assertTrue(!is_dir($this->homeDir.'/media'), 'dry-run must not create data dirs');
        $this->assertStringContainsString('--network pmss-media', $result['output']);
        $this->assertStringContainsString($this->homeDir.'/media:/data', $result['output']);
    }

    public function testDryRunQbittorrentUsesDownloadsAndWebUiPort(): void
    {
        $result = $this->runHelper(['qbittorrent', '--dry-run']);

        $this->assertEquals(0, $result['rc']);
        $this->assertTrue(!is_dir($this->homeDir.'/downloads'), 'dry-run must not create downloads dir');
        $this->assertStringContainsString('WEBUI_PORT=8080', $result['output']);
        $this->assertStringContainsString($this->homeDir.'/downloads:/downloads', $result['output']);
    }

    public function testDryRunRadarrUsesMoviesAndSharedDownloads(): void
    {
        $result = $this->runHelper(['radarr', '--dry-run']);

        $this->assertEquals(0, $result['rc']);
        $this->assertTrue(!is_dir($this->homeDir.'/movies'), 'dry-run must not create movie dirs');
        $this->assertStringContainsString($this->homeDir.'/movies:/movies', $result['output']);
        $this->assertStringContainsString($this->homeDir.'/downloads:/downloads', $result['output']);
    }

    public function testCustomPortOverrideAppliesToSonarr(): void
    {
        $result = $this->runHelper(['sonarr', '18989', '--dry-run']);

        $this->assertEquals(0, $result['rc']);
        $this->assertStringContainsString('-p 18989:8989', $result['output']);
    }

    public function testDryRunMariadbUsesLocalOnlyBindAndGeneratedCredentials(): void
    {
        $result = $this->runHelper(['mariadb', '--dry-run']);

        $this->assertEquals(0, $result['rc']);
        $this->assertTrue(!is_dir($this->homeDir.'/docker/mariadb/config'), 'dry-run must not create config dirs');
        $this->assertStringContainsString('-p 127.0.0.1:3306:3306', $result['output']);
        $this->assertStringContainsString('generated-at-install', $result['output']);
        $this->assertStringContainsString('MYSQL_USER=db_home', $result['output']);
        $this->assertTrue(!is_file($this->homeDir.'/docker/mariadb/pmss-credentials.env'), 'dry-run must not persist credentials');
    }

    public function testRunMariadbWritesCredentialFileAndUsesEnvFile(): void
    {
        $result = $this->runHelper(['mariadb']);
        $credentialFile = $this->homeDir.'/docker/mariadb/pmss-credentials.env';

        $this->assertEquals(0, $result['rc']);
        $this->assertTrue(is_file($credentialFile));
        $contents = (string) file_get_contents($credentialFile);
        $this->assertStringContainsString('MYSQL_ROOT_PASSWORD=', $contents);
        $this->assertStringContainsString('MYSQL_USER=db_home', $contents);
        $this->assertStringContainsString('--env-file '.$credentialFile, $result['dockerLog']);
        $this->assertStringContainsString('-p 127.0.0.1:3306:3306', $result['dockerLog']);
    }

    public function testDryRunPhpMyAdminUsesLocalOnlyBindAndMariadbHost(): void
    {
        $result = $this->runHelper(['phpmyadmin', '--dry-run']);

        $this->assertEquals(0, $result['rc']);
        $this->assertTrue(!is_dir($this->homeDir.'/docker/phpmyadmin/config'), 'dry-run must not create config dirs');
        $this->assertStringContainsString('-p 127.0.0.1:8082:80', $result['output']);
        $this->assertStringContainsString('PMA_HOST=mariadb', $result['output']);
        $this->assertStringContainsString('PMA_PORT=3306', $result['output']);
    }

    public function testDryRunRejectsNonNumericHostPortOverride(): void
    {
        $result = $this->runHelper(['jellyfin', 'abc', '--dry-run']);

        $this->assertTrue($result['rc'] !== 0, 'non-numeric host port should fail');
        $this->assertStringContainsString('Invalid host port; expected an integer between 1 and 65535.', $result['output']);
        $this->assertSame('', $result['dockerLog']);
    }

    public function testDryRunRejectsOutOfRangeHostPortOverride(): void
    {
        $result = $this->runHelper(['jellyfin', '70000', '--dry-run']);

        $this->assertTrue($result['rc'] !== 0, 'out-of-range host port should fail');
        $this->assertStringContainsString('Invalid host port; expected an integer between 1 and 65535.', $result['output']);
        $this->assertSame('', $result['dockerLog']);
    }

    public function testRunWithoutDockerDoesNotCreateDirectories(): void
    {
        $emptyPath = $this->tempDir.'/empty-bin';
        @mkdir($emptyPath, 0700, true);

        $result = $this->runHelper(['jellyfin'], ['PATH' => $emptyPath]);

        $this->assertTrue($result['rc'] !== 0, 'docker-less run should fail');
        $this->assertStringContainsString('docker command not found in PATH', $result['output']);
        $this->assertTrue(!is_dir($this->homeDir.'/docker/jellyfin/config'), 'failed runs must not create config dirs before docker checks');
        $this->assertTrue(!is_dir($this->homeDir.'/media'), 'failed runs must not create media dirs before docker checks');
    }

    public function testRunCreatesNetworkAndContainerWhenMissing(): void
    {
        $result = $this->runHelper(['prowlarr']);

        $this->assertEquals(0, $result['rc']);
        $this->assertStringContainsString('network inspect pmss-media', $result['dockerLog']);
        $this->assertStringContainsString('network create pmss-media', $result['dockerLog']);
        $this->assertStringContainsString('container inspect prowlarr', $result['dockerLog']);
        $this->assertStringContainsString('run -d --name prowlarr', $result['dockerLog']);
    }

    public function testRunRefusesExistingContainer(): void
    {
        $result = $this->runHelper(['jellyfin'], [
            'PMSS_TEST_DOCKER_NETWORK_EXISTS' => '1',
            'PMSS_TEST_DOCKER_CONTAINER_EXISTS' => '1',
        ]);

        $this->assertTrue($result['rc'] !== 0, 'expected non-zero exit when container exists');
        $this->assertStringContainsString('already exists', $result['output']);
        $this->assertTrue(strpos($result['dockerLog'], 'run -d --name jellyfin') === false, 'must not run docker when container exists');
    }

    public function testLegacyWrapperDelegatesToPhpInstaller(): void
    {
        $result = $this->runHelper(['prowlarr', '--dry-run'], [], 'legacy');

        $this->assertEquals(0, $result['rc']);
        $this->assertStringContainsString('docker run -d --name prowlarr', $result['output']);
        $this->assertStringContainsString('--network pmss-media', $result['output']);
    }

    public function testUnknownAppShowsUsage(): void
    {
        $result = $this->runHelper(['bazarr', '--dry-run']);

        $this->assertTrue($result['rc'] !== 0, 'unknown app should fail');
        $this->assertStringContainsString('Supported apps: jellyfin qbittorrent radarr sonarr prowlarr mariadb phpmyadmin', $result['output']);
    }

    public function testSkeletonCopiesInstallerScript(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 2).'/update/users/filesystem.php');

        $this->assertStringContainsString("'bin/docker-install-lsio'", $source);
        $this->assertStringContainsString("'bin/linuxserverInstall.sh'", $source);
    }
}
