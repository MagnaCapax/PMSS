<?php
/**
 * Hermetic coverage for the LinuxServer.io one-click helper.
 */

namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

class LinuxserverInstallScriptTest extends TestCase
{
    private $tempDir;
    private $homeDir;
    private $fakeBinDir;
    private $dockerLog;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir().'/pmss-linuxserver-install-'.bin2hex(random_bytes(4));
        $this->homeDir = $this->tempDir.'/home';
        $this->fakeBinDir = $this->tempDir.'/bin';
        $this->dockerLog = $this->tempDir.'/docker.log';

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
        file_put_contents($this->fakeBinDir.'/docker', $dockerStub);
        @chmod($this->fakeBinDir.'/docker', 0755);
    }

    protected function tearDown(): void
    {
        $this->cleanup($this->tempDir);
    }

    private function runHelper(array $args, array $env = []): array
    {
        $envPairs = [
            'HOME='.$this->homeDir,
            'PATH='.$this->fakeBinDir.':'.(getenv('PATH') !== false ? getenv('PATH') : '/usr/bin:/bin'),
            'PMSS_TEST_DOCKER_LOG='.$this->dockerLog,
        ];
        foreach ($env as $key => $value) {
            $envPairs[] = $key.'='.$value;
        }

        $command = 'env';
        foreach ($envPairs as $pair) {
            $command .= ' '.escapeshellarg($pair);
        }
        $command .= ' bash '.escapeshellarg(__DIR__.'/../../../../etc/skel/bin/linuxserverInstall.sh');
        foreach ($args as $arg) {
            $command .= ' '.escapeshellarg($arg);
        }
        $command .= ' 2>&1';

        $output = [];
        $rc = 0;
        exec($command, $output, $rc);

        $dockerLog = is_file($this->dockerLog) ? (string) file_get_contents($this->dockerLog) : '';

        return [
            'rc' => $rc,
            'output' => implode("\n", $output),
            'dockerLog' => $dockerLog,
        ];
    }

    public function testDryRunJellyfinCreatesConfigAndMediaDirs(): void
    {
        $result = $this->runHelper(['jellyfin', '--dry-run']);

        $this->assertEquals(0, $result['rc']);
        $this->assertTrue(is_dir($this->homeDir.'/docker/jellyfin/config'));
        $this->assertTrue(is_dir($this->homeDir.'/media'));
        $this->assertStringContainsString('--network pmss-media', $result['output']);
        $this->assertStringContainsString($this->homeDir.'/media:/data', $result['output']);
    }

    public function testDryRunQbittorrentUsesDownloadsAndWebUiPort(): void
    {
        $result = $this->runHelper(['qbittorrent', '--dry-run']);

        $this->assertEquals(0, $result['rc']);
        $this->assertTrue(is_dir($this->homeDir.'/downloads'));
        $this->assertStringContainsString('WEBUI_PORT=8080', $result['output']);
        $this->assertStringContainsString($this->homeDir.'/downloads:/downloads', $result['output']);
    }

    public function testDryRunRadarrUsesMoviesAndSharedDownloads(): void
    {
        $result = $this->runHelper(['radarr', '--dry-run']);

        $this->assertEquals(0, $result['rc']);
        $this->assertTrue(is_dir($this->homeDir.'/movies'));
        $this->assertStringContainsString($this->homeDir.'/movies:/movies', $result['output']);
        $this->assertStringContainsString($this->homeDir.'/downloads:/downloads', $result['output']);
    }

    public function testCustomPortOverrideAppliesToSonarr(): void
    {
        $result = $this->runHelper(['sonarr', '18989', '--dry-run']);

        $this->assertEquals(0, $result['rc']);
        $this->assertStringContainsString('-p 18989:8989', $result['output']);
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

    public function testUnknownAppShowsUsage(): void
    {
        $result = $this->runHelper(['bazarr', '--dry-run']);

        $this->assertTrue($result['rc'] !== 0, 'unknown app should fail');
        $this->assertStringContainsString('Supported apps: jellyfin qbittorrent radarr sonarr prowlarr', $result['output']);
    }

    public function testSkeletonCopiesInstallerScript(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 2).'/update/users/filesystem.php');

        $this->assertStringContainsString("'bin/linuxserverInstall.sh'", $source);
    }
}
