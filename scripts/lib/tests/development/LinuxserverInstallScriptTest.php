<?php
/**
 * Hermetic coverage for the LinuxServer.io installer wrappers.
 */

namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 4).'/etc/skel/bin/dockerInstallLsio.php';

class LinuxserverInstallScriptTest extends TestCase
{
    private $homeDir;
    private $fakeBinDir;
    private $dockerLog;
    private $phpBin;
    private $bashBin;
    private $installerPath;
    private $legacyInstallerPath;
    private $helperPath;

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
        $this->helperPath = __DIR__.'/../../../../etc/skel/bin/dockerInstallLsio.php';

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
  'run -d')
    # The initial qBittorrent run must never precede its auth config publication.
    if [[ "${4:-}" == 'qbittorrent' ]]; then
      [[ -s "$HOME/docker/qbittorrent/config/qBittorrent/qBittorrent.conf" ]] || exit 1
    fi
    ;;
  'inspect --format')
    printf 'sha256:%064d\n' 0
    exit 0
    ;;
  'exec -i')
    app="$3"
    config=$(cat)
    code=200
    body='{}'
    case "$config" in
      *'/Startup/User'*|*'/Startup/Complete'*) [[ "$config" == *'request = "POST"'* ]] && code=204 ;;
      *'/Users/AuthenticateByName'*) body='{"AccessToken":"testtoken"}' ;;
      *'/Users"'*)
        if [[ "$config" == *'X-Emby-Token:'* ]]; then
          user=$(sed -n 's/^USER=//p' "$HOME/docker/$app/pmss-credentials.env")
          body="[{\"Name\":\"$user\"}]"
        else code="${PMSS_TEST_DOCKER_UNAUTH_CODE:-401}"; fi
        ;;
      *'/config/host/1'*) code="${PMSS_TEST_DOCKER_PUT_CODE:-202}" ;;
      *'/system/status'*) code="${PMSS_TEST_DOCKER_UNAUTH_CODE:-401}" ;;
    esac
    printf '%s\n%s' "$body" "$code"
    exit 0
    ;;
  'exec radarr'|'exec sonarr'|'exec prowlarr')
    printf '<Config><ApiKey>0123456789abcdef0123456789abcdef</ApiKey></Config>'
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
            'PMSS_DOCKER_INSTALL_LSIO_SCRIPT='.$this->helperPath,
            'PMSS_DOCKER_INSTALL_LSIO_WRAPPER='.$this->installerPath,
        ];
        foreach ($env as $key => $value) {
            $envPairs[] = $key.'='.$value;
        }

        $entryScript = $entry === 'legacy' ? $this->legacyInstallerPath : $this->installerPath;
        $runner = $entry === 'legacy' ? $this->bashBin : $this->phpBin;
        $command = 'env '.\pmssCommandArgvShellQuote(array_merge($envPairs, [$runner, $entryScript], $args)).' 2>&1';

        $output = [];
        $rc = 0;
        exec($command, $output, $rc);

        return [
            'rc' => $rc,
            'output' => implode("\n", $output),
            'dockerLog' => $this->pmssReadFileOrEmpty($this->dockerLog),
        ];
    }

    /** Mimic the root-side reservations without touching native port files. */
    private function reserveTorrentPorts(string $app): void
    {
        $this->pmssWriteFile($this->homeDir.'/.media-stack-port-'.$app, "23001\n");
        $this->pmssWriteFile($this->homeDir.'/.media-stack-port-'.$app.'-peer', "23002\n");
    }

    public function testDryRunJellyfinDoesNotCreateConfigOrMediaDirs(): void
    {
        $result = $this->runHelper(['jellyfin', '--dry-run']);

        $this->assertEquals(0, $result['rc']);
        $this->assertTrue(!is_dir($this->homeDir.'/docker/jellyfin/config'), 'dry-run must not create config dirs');
        $this->assertTrue(!is_dir($this->homeDir.'/media'), 'dry-run must not create data dirs');
        $this->assertStringContainsAllStrings(['--network pmss-media', $this->homeDir.'/media:/data'], $result['output']);
    }

    public function testSupportedAppCatalogFeedsUsageText(): void
    {
        $this->assertSame(
            ['jellyfin', 'qbittorrent', 'transmission', 'radarr', 'sonarr', 'prowlarr', 'mariadb', 'phpmyadmin'],
            array_keys(\pmssDockerInstallLsioAppCatalog())
        );
        $this->assertStringContainsString(
            'Supported apps: jellyfin qbittorrent transmission radarr sonarr prowlarr mariadb phpmyadmin',
            \pmssDockerInstallLsioUsage('docker-install-lsio')
        );
    }

    public function testDryRunQbittorrentUsesDownloadsAndWebUiPort(): void
    {
        $this->reserveTorrentPorts('qbittorrent');
        $result = $this->runHelper(['qbittorrent', '--dry-run']);

        $this->assertEquals(0, $result['rc']);
        $this->assertTrue(!is_dir($this->homeDir.'/downloads'), 'dry-run must not create downloads dir');
        $this->assertStringContainsAllStrings([
            'WEBUI_PORT=8080', $this->homeDir.'/downloads:/downloads',
            '--label pmss.client=qbittorrent', '-p 127.0.0.1:23001:8080',
            '-p 0.0.0.0:23002:23002/tcp', '-p 0.0.0.0:23002:23002/udp', 'TORRENTING_PORT=23002',
            'lscr.io/linuxserver/qbittorrent:5.2.4_v2.0.15-ls481@sha256:1ea873647c43d566f251a7122aee330b184509c57ee3257ed19f62dac0f8f24b',
            'WEBUI_USER=<generated-at-install>', 'PBKDF2',
        ], $result['output']);
        $this->assertFalse(is_dir($this->homeDir.'/docker'));
        $this->assertSame('', $result['dockerLog']);
        $this->assertStringNotContainsString('--env-file', $result['output']);
    }

    public function testTorrentClientDryRunsUseLoopbackAndSharedDownloads(): void
    {
        $this->reserveTorrentPorts('transmission');
        $result = $this->runHelper(['transmission', '--dry-run']);
        $this->assertSame(0, $result['rc']);
        $this->assertStringContainsAllStrings([
            '--label pmss.client=transmission',
            '-p 127.0.0.1:23001:9091',
            '-p 0.0.0.0:23002:23002/tcp', '-p 0.0.0.0:23002:23002/udp', 'PEERPORT=23002',
            'lscr.io/linuxserver/transmission:4.1.3-r0-ls365@sha256:55aaeedf9fc354d34136387e15ee0ba4679096f20398dd2875867e5cd5cf8be5',
            $this->homeDir.'/downloads:/downloads',
            'USER=<generated-at-install>',
            'PASS=<generated-at-install>',
        ], $result['output']);
        $this->assertStringNotContainsString('WHITELIST=', $result['output']);
        $this->assertTrue(!is_dir($this->homeDir.'/docker/transmission/config'));
    }

    public function testDelugeDefaultPasswordPresetIsUnavailable(): void
    {
        $result = $this->runHelper(['deluge', '--dry-run']);
        $this->assertTrue($result['rc'] !== 0);
        $this->assertTrue(!is_dir($this->homeDir.'/docker/deluge/config'));
    }

    public function testTorrentClientRunRequestsManagedProxyRefresh(): void
    {
        $this->reserveTorrentPorts('transmission');
        $this->pmssEnsureDir($this->homeDir.'/.lighttpd');
        $result = $this->runHelper(['transmission']);

        $this->assertSame(0, $result['rc']);
        $this->assertTrue(is_file($this->homeDir.'/.lighttpd/.dockerClientProxyRefresh'));
        $this->assertStringContainsAllStrings([
            '--label pmss.client=transmission',
            '-p 127.0.0.1:23001:9091',
            '-p 0.0.0.0:23002:23002/tcp', '-p 0.0.0.0:23002:23002/udp', 'PEERPORT=23002',
            $this->homeDir.'/watch:/watch',
            '--env-file '.$this->homeDir.'/docker/transmission/pmss-credentials.env',
        ], $result['dockerLog']);
        $this->assertStringNotContainsString('PASS=', $result['dockerLog']);
        $this->assertStringNotContainsString('USER=', $result['dockerLog']);
        $this->assertStringNotContainsString('WHITELIST=', $result['dockerLog']);
        $credentialFile = $this->homeDir.'/docker/transmission/pmss-credentials.env';
        $this->assertTrue(is_file($credentialFile));
        $this->assertSame(0600, fileperms($credentialFile) & 0777);
        $this->assertSame(posix_geteuid(), fileowner($credentialFile));
        $contents = (string) file_get_contents($credentialFile);
        $this->assertTrue((bool) preg_match('/\AUSER=[a-f0-9]{36}\nPASS=[a-f0-9]{36}\n\z/', $contents));
        $this->assertStringNotContainsString($contents, $result['dockerLog']);
        $this->assertStringContainsString($credentialFile, $result['output']);
        foreach (parse_ini_file($credentialFile) as $secret) {
            $this->assertStringNotContainsString($secret, $result['output']);
            $this->assertStringNotContainsString($secret, $result['dockerLog']);
        }
    }

    public function testTorrentClientsRefuseMissingOrInvalidReservationsWithoutChanges(): void
    {
        foreach (['qbittorrent', 'transmission'] as $app) {
            $marker = $this->homeDir.'/.media-stack-port-'.$app;
            foreach ([null, '', "0\n", "65536\n", "12\n13\n", " 23001\n", "23001\n\n", str_repeat('1', 33)] as $invalid) {
                $this->reserveTorrentPorts($app);
                unlink($marker);
                if ($invalid !== null) file_put_contents($marker, $invalid);
                $before = scandir($this->homeDir);
                foreach ([[$app], [$app, '--dry-run']] as $args) {
                    $result = $this->runHelper($args);
                    $this->assertSame(1, $result['rc']);
                    $this->assertStringContainsString('PMSS publishes them in its per-account setup', $result['output']);
                    $this->assertSame('', $result['dockerLog']);
                    $this->assertSame($before, scandir($this->homeDir));
                }
            }
            $this->reserveTorrentPorts($app);
            unlink($marker.'-peer');
            $this->assertSame(1, $this->runHelper([$app])['rc']);
            file_put_contents($marker.'-peer', "70000\n");
            $this->assertSame(1, $this->runHelper([$app])['rc']);
            unlink($marker.'-peer');
            $this->pmssCreateSymlinkOrSkip($marker, $marker.'-peer');
            $this->assertSame(1, $this->runHelper([$app])['rc']);
            unlink($marker.'-peer');
            mkdir($marker.'-peer');
            $this->assertSame(1, $this->runHelper([$app])['rc']);
            $this->assertFalse(is_dir($this->homeDir.'/docker'));
            $this->assertSame('', $this->pmssReadFileOrEmpty($this->dockerLog));
        }
    }

    public function testTorrentClientsRejectHostPortOverrides(): void
    {
        foreach (['qbittorrent', 'transmission'] as $app) {
            $this->reserveTorrentPorts($app);
            foreach (['', '23003', '8080', 'invalid'] as $port) {
                $result = $this->runHelper([$app, $port, '--dry-run']);
                $this->assertSame(1, $result['rc']);
                $this->assertStringContainsString('HOST_PORT is not accepted', $result['output']);
                $this->assertSame('', $result['dockerLog']);
                $this->assertFalse(is_dir($this->homeDir.'/docker'));
            }
        }
    }

    public function testReservedPortReaderMatchesAccountAgentContract(): void
    {
        $marker = $this->homeDir.'/port';
        foreach (["1" => 1, "65535\n" => 65535, "23001\r\n" => 23001, '00001' => 1,
            '+23001' => null, "23001\r" => 23001, "23001\0" => null, '999999' => null] as $line => $expected) {
            file_put_contents($marker, (string) $line);
            clearstatcache();
            $this->assertSame($expected, \pmssDockerInstallLsioReservedPort($marker));
        }
    }

    public function testQbittorrentSeedsPrivateHashedLoginBeforeDockerStart(): void
    {
        $this->reserveTorrentPorts('qbittorrent');
        $this->pmssEnsureDir($this->homeDir.'/.lighttpd');
        $result = $this->runHelper(['qbittorrent']);
        $this->assertSame(0, $result['rc']);
        $config = $this->homeDir.'/docker/qbittorrent/config/qBittorrent/qBittorrent.conf';
        $credentialFile = $this->homeDir.'/docker/qbittorrent/pmss-credentials.env';
        foreach ([$config, $credentialFile] as $path) {
            $this->assertSame(0600, fileperms($path) & 0777);
            $this->assertSame(posix_geteuid(), fileowner($path));
        }
        $credentials = parse_ini_file($credentialFile);
        $this->assertTrue(preg_match('/^[a-f0-9]{12}$/D', $credentials['WEBUI_USER']) === 1);
        $this->assertTrue(preg_match('/^[a-f0-9]{36}$/D', $credentials['WEBUI_PASS']) === 1);
        $content = file_get_contents($config);
        $this->assertTrue(preg_match('/WebUI\\\\Password_PBKDF2="@ByteArray\(([^:]+):([^)]*)\)"/', $content, $match) === 1);
        $salt = base64_decode($match[1], true);
        $hash = base64_decode($match[2], true);
        $this->assertSame(16, strlen($salt));
        $this->assertSame(64, strlen($hash));
        $this->assertSame($hash, hash_pbkdf2('sha512', $credentials['WEBUI_PASS'], $salt, 100000, 64, true));
        $this->assertStringContainsAllStrings([
            '[AutoRun]', 'enabled=false', 'program=', '[LegalNotice]', 'Accepted=true', '[Preferences]',
            'Connection\UPnP=false', 'Connection\PortRangeMin=23002', 'Downloads\SavePath=/downloads/',
            'Downloads\ScanDirsV2=@Variant(\0\0\0\x1c\0\0\0\0)', 'Downloads\TempPath=/downloads/incomplete/',
            'WebUI\Address=*', 'WebUI\ServerDomains=*', 'WebUI\Username='.$credentials['WEBUI_USER'],
            'WebUI\CSRFProtection=true', 'WebUI\ClickjackingProtection=true', 'WebUI\HostHeaderValidation=false',
        ], $content);
        foreach ($credentials as $secret) {
            $this->assertStringNotContainsString($secret, $result['output']);
            $this->assertStringNotContainsString($secret, $result['dockerLog']);
        }
        $this->assertStringNotContainsString($credentials['WEBUI_PASS'], $content);
        $this->assertStringNotContainsString('--env-file', $result['dockerLog']);
        $this->assertStringContainsString($credentialFile, $result['output']);
        $this->assertTrue(is_file($this->homeDir.'/.lighttpd/.dockerClientProxyRefresh'));
    }

    public function testQbittorrentReinstallPreservesExistingConfigAndCredentials(): void
    {
        $this->reserveTorrentPorts('qbittorrent');
        $this->pmssEnsureDir($this->homeDir.'/.lighttpd');
        $config = $this->homeDir.'/docker/qbittorrent/config/qBittorrent/qBittorrent.conf';
        $credentials = $this->homeDir.'/docker/qbittorrent/pmss-credentials.env';
        $this->pmssWriteFile($config, "[Preferences]\ncustom=value\n");
        $this->pmssWriteFile($credentials, "customer credentials\n");
        $result = $this->runHelper(['qbittorrent']);
        $this->assertSame(0, $result['rc']);
        $this->assertSame("[Preferences]\ncustom=value\n", file_get_contents($config));
        $this->assertSame("customer credentials\n", file_get_contents($credentials));
        $this->assertStringContainsString('credential file left untouched', $result['output']);
        unlink($credentials);
        $this->assertSame(0, $this->runHelper(['qbittorrent'])['rc']);
        $this->assertFalse(file_exists($credentials));
        $this->assertSame("[Preferences]\ncustom=value\n", file_get_contents($config));
    }

    public function testDryRunRadarrUsesMoviesAndSharedDownloads(): void
    {
        $result = $this->runHelper(['radarr', '--dry-run']);

        $this->assertEquals(0, $result['rc']);
        $this->assertTrue(!is_dir($this->homeDir.'/movies'), 'dry-run must not create movie dirs');
        $this->assertStringContainsAllStrings([$this->homeDir.'/movies:/movies', $this->homeDir.'/downloads:/downloads'], $result['output']);
    }

    public function testFirstRunDryPlansPublishOnlyAfterVerification(): void
    {
        foreach (['jellyfin', 'radarr', 'sonarr', 'prowlarr'] as $app) {
            $result = $this->runHelper([$app, '--dry-run']);
            $lines = explode("\n", $result['output']);
            $this->assertSame(0, $result['rc']);
            $this->assertStringNotContainsString(' -p ', $lines[1]);
            $this->assertStringContainsString('--restart no', $lines[1]);
            $this->assertOrderedStrings(['Phase 1:', 'Phase 2:', 'Phase 3:', 'Phase 4:', '-p 127.0.0.1:'], $result['output']);
            $this->assertSame('', $result['dockerLog']);
        }
    }

    public function testFirstRunSeedsInsideContainerThenReusesImageAndVolume(): void
    {
        foreach (['jellyfin', 'radarr', 'sonarr', 'prowlarr'] as $app) {
            @unlink($this->dockerLog);
            $result = $this->runHelper([$app]);
            $this->assertSame(0, $result['rc'], $result['output']);
            $this->assertOrderedStrings(['run -d --name '.$app, 'exec -i '.$app.' curl', 'stop '.$app, 'rm '.$app, '-p 127.0.0.1:'], $result['dockerLog']);
            $runs = array_values(array_filter(explode("\n", $result['dockerLog']), static function ($line): bool {
                return strpos($line, 'run -d') === 0;
            }));
            $this->assertSame(2, count($runs));
            $this->assertStringNotContainsString(' -p ', $runs[0]);
            $this->assertStringContainsString($this->homeDir.'/docker/'.$app.'/config:/config', $runs[1]);
            $this->assertStringContainsString('sha256:'.str_repeat('0', 64), $runs[1]);
            $file = $this->homeDir.'/docker/'.$app.'/pmss-credentials.env';
            $this->assertSame(0600, fileperms($file) & 0777);
            $credentials = parse_ini_file($file);
            foreach ($credentials as $secret) {
                $this->assertStringNotContainsString($secret, $result['output'].$result['dockerLog']);
            }
        }
    }

    public function testFirstRunPutFailureKeepsUnpublishedContainerAndCredentials(): void
    {
        $result = $this->runHelper(['radarr'], ['PMSS_TEST_DOCKER_PUT_CODE' => '500']);
        $this->assertSame(1, $result['rc']);
        $this->assertStringContainsString('no port published', $result['output']);
        $this->assertStringNotContainsString(' -p ', $result['dockerLog']);
        $this->assertStringNotContainsString('rm radarr', $result['dockerLog']);
        $this->assertTrue(is_file($this->homeDir.'/docker/radarr/pmss-credentials.env'));
    }

    public function testFirstRunRequiresExplicitUnauthenticatedRefusal(): void
    {
        $originalPath = getenv('PATH');
        foreach (['200', '302', '404', '500', '000'] as $code) {
            $app = 'radarr';
            $spec = \pmssDockerInstallLsioAppSpec($app, $this->homeDir, false);
            putenv('PATH='.$this->fakeBinDir.':'.getenv('PATH'));
            putenv('PMSS_TEST_DOCKER_UNAUTH_CODE='.$code);
            try {
                $this->assertFalse(\pmssDockerLsioSeedLogin($app, $spec, \pmssDockerLsioSeedCatalog()[$app], ['USER' => 'test', 'PASS' => 'secret']));
            } finally {
                putenv('PMSS_TEST_DOCKER_UNAUTH_CODE');
                putenv('PATH='.$originalPath);
            }
        }
    }

    public function testFirstRunPreservesPriorConfigAndCredentialBytes(): void
    {
        $config = $this->homeDir.'/docker/sonarr/config';
        $this->pmssEnsureDir($config);
        file_put_contents($config.'/config.xml', 'prior-config');
        $file = dirname($config).'/pmss-credentials.env';
        file_put_contents($file, 'prior-credentials');
        $result = $this->runHelper(['sonarr']);
        $this->assertSame(1, $result['rc']);
        $this->assertStringNotContainsString('run -d', $result['dockerLog']);
        $this->assertSame('prior-config', file_get_contents($config.'/config.xml'));
        $this->assertSame('prior-credentials', file_get_contents($file));
    }

    public function testFirstRunCredentialPublicationNeverReplacesAnotherInstall(): void
    {
        $file = $this->homeDir.'/credentials.env';
        $this->assertTrue(\pmssDockerInstallLsioWriteCredentialFile($file, '', '', 'transmission', true));
        $original = file_get_contents($file);
        $this->assertSame(0600, fileperms($file) & 0777);
        $this->assertFalse(\pmssDockerInstallLsioWriteCredentialFile($file, '', '', 'transmission', true));
        $this->assertSame($original, file_get_contents($file));
        $this->assertSame([], glob($this->homeDir.'/.pmss-lsio-*'));
    }

    public function testCustomPortOverrideAppliesToSonarr(): void
    {
        $result = $this->runHelper(['sonarr', '18989', '--dry-run']);

        $this->assertEquals(0, $result['rc']);
        $this->assertStringContainsString('-p 127.0.0.1:18989:8989', $result['output']);
    }

    public function testDryRunMariadbUsesLocalOnlyBindAndGeneratedCredentials(): void
    {
        $result = $this->runHelper(['mariadb', '--dry-run']);

        $this->assertEquals(0, $result['rc']);
        $this->assertTrue(!is_dir($this->homeDir.'/docker/mariadb/config'), 'dry-run must not create config dirs');
        $this->assertStringContainsAllStrings(['-p 127.0.0.1:3306:3306', 'generated-at-install', 'MYSQL_USER=db_home'], $result['output']);
        $this->assertTrue(!is_file($this->homeDir.'/docker/mariadb/pmss-credentials.env'), 'dry-run must not persist credentials');
    }

    public function testRunMariadbWritesCredentialFileAndUsesEnvFile(): void
    {
        $result = $this->runHelper(['mariadb']);
        $credentialFile = $this->homeDir.'/docker/mariadb/pmss-credentials.env';

        $this->assertEquals(0, $result['rc']);
        $this->assertTrue(is_file($credentialFile));
        $this->assertSame(0600, fileperms($credentialFile) & 0777);
        $contents = (string) file_get_contents($credentialFile);
        $this->assertStringContainsAllStrings(['MYSQL_ROOT_PASSWORD=', 'MYSQL_USER=db_home'], $contents);
        $this->assertStringContainsAllStrings(['--env-file '.$credentialFile, '-p 127.0.0.1:3306:3306'], $result['dockerLog']);
    }

    public function testCredentialFileWriterRejectsLinkedTarget(): void
    {
        $directory = $this->homeDir.'/docker/mariadb';
        $this->pmssEnsureDir($directory);
        $outside = $this->pmssWriteFile($this->tempDir.'/outside.env', 'kept');
        $link = $directory.'/pmss-credentials.env';
        $this->pmssCreateSymlinkOrSkip($outside, $link);

        $this->assertFalse(\pmssDockerInstallLsioWriteCredentialFile($link, 'db', 'user'));
        $this->assertSame('kept', file_get_contents($outside));
    }

    public function testDryRunPhpMyAdminUsesLocalOnlyBindAndMariadbHost(): void
    {
        $result = $this->runHelper(['phpmyadmin', '--dry-run']);

        $this->assertEquals(0, $result['rc']);
        $this->assertTrue(!is_dir($this->homeDir.'/docker/phpmyadmin/config'), 'dry-run must not create config dirs');
        $this->assertStringContainsAllStrings(['-p 127.0.0.1:8082:80', 'PMA_HOST=mariadb', 'PMA_PORT=3306'], $result['output']);
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

    public function testCustomerTreeDryRunNeedsNoOperatorScripts(): void
    {
        $customerBin = $this->homeDir.'/bin';
        $this->pmssEnsureDir($customerBin);
        copy($this->installerPath, $customerBin.'/docker-install-lsio');
        copy($this->helperPath, $customerBin.'/dockerInstallLsio.php');
        copy(dirname($this->helperPath).'/dockerInstallLsioTorrent.php', $customerBin.'/dockerInstallLsioTorrent.php');
        foreach (['dockerLsioSeed.php', 'dockerLsioSeedHttp.php'] as $helper) {
            copy(dirname($this->helperPath).'/'.$helper, $customerBin.'/'.$helper);
        }
        $this->installerPath = $customerBin.'/docker-install-lsio';
        $this->legacyInstallerPath = $customerBin.'/linuxserverInstall.sh';
        copy(__DIR__.'/../../../../etc/skel/bin/linuxserverInstall.sh', $this->legacyInstallerPath);

        foreach (['current', 'legacy'] as $entry) {
            $result = $this->runHelper(['radarr', '--dry-run'], [
                'PMSS_DOCKER_INSTALL_LSIO_SCRIPT' => $customerBin.'/dockerInstallLsio.php',
                'PMSS_DOCKER_INSTALL_LSIO_WRAPPER' => $customerBin.'/docker-install-lsio',
            ], $entry);
            $this->assertSame(0, $result['rc']);
            $this->assertStringContainsString('-p 127.0.0.1:7878:7878', $result['output']);
        }
        $this->assertTrue(strpos(file_get_contents($customerBin.'/dockerInstallLsio.php'), '/scripts/') === false);
    }

    public function testUnknownAppShowsUsage(): void
    {
        $result = $this->runHelper(['bazarr', '--dry-run']);

        $this->assertTrue($result['rc'] !== 0, 'unknown app should fail');
        $this->assertStringContainsString('Supported apps: jellyfin qbittorrent transmission radarr sonarr prowlarr mariadb phpmyadmin', $result['output']);
    }

    public function testSkeletonCopiesInstallerScript(): void
    {
        $source = $this->pmssReadRepoFile('scripts/lib/update/users/filesystem.php');

        $this->assertStringContainsString("'bin/docker-install-lsio'", $source);
        $this->assertStringContainsString("'bin/dockerInstallLsio.php'", $source);
        $this->assertStringContainsString("'bin/dockerInstallLsioTorrent.php'", $source);
        $this->assertStringContainsString("'bin/linuxserverInstall.sh'", $source);
    }
}
