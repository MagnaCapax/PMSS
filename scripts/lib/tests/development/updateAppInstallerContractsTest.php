<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

class UpdateAppInstallerContractsTest extends TestCase
{
    public function testInstallersKeepSourceContracts(): void
    {
        $this->pmssAssertRepoFileContractCases([
            'aiToolsInstall.php' => [
                'required' => [
                    '@google/gemini-cli',
                    '@anthropic-ai/claude-code',
                    '/usr/local/bin/codex',
                    'node-v22.22.1-linux-x64.tar.xz',
                    'codex-x86_64-unknown-linux-musl.tar.gz',
                    'PMSS_FORCE_AI_TOOLS_REFRESH',
                    "pmssAppVersionProbeMatch([escapeshellarg(\$systemNode)",
                    '>= 22',
                    "dirname(\$nodeBinary).'/npm'",
                    'npm not available',
                    "putenv('PATH='.dirname(\$nodeBinary)",
                    '/etc/codex/config.toml',
                    'danger-full-access',
                ],
                'forbidden' => [
                    'function pmssAiTools'.'NodeMajor(' => 'Node major parsing should stay inline in the only call site',
                    'function pmssAiTools'.'InstallNpmCli(' => 'NPM CLI installation should stay inline in the only installer',
                ],
                'matches' => ['/[0-9a-f]{64}/'],
            ],
            'btsync.php' => [
                'required' => [
                    "require_once __DIR__.'/remoteBinary.php';",
                    'pmssInstallPinnedRemoteBinary',
                    'https://pulsedmedia.com/remote/pkg/',
                    'sha256',
                    "runStep('Linking btsync shim'",
                    "pmssInstallPinnedRemoteBinary('Resilio Sync'",
                ],
                'forbidden' => [
                    'http://pulsedmedia.com/remote/pkg/' => 'Found insecure http:// remote/pkg URL',
                    '--help 2>/dev/null' => 'Found rslsync --help probing',
                    'Resilio Sync 2.' => 'Found pinned version string probing',
                    "require_once __DIR__.'/../runtime/commands.php';" => 'BTSync installer should rely on remoteBinary.php for runtime helper bootstrap',
                    "require_once __DIR__.'/../logging.php';" => 'BTSync installer should not duplicate remoteBinary.php logging bootstrap',
                    "@hash_file('sha256', \$rslsyncBinary)" => 'BTSync installer should not duplicate the helper checksum decision for rslsync',
                ],
                'matches' => ['/\\x27[0-9a-f]{64}\\x27/'],
            ],
            'filebot.php' => [
                'required' => [
                    "require_once __DIR__.'/remoteBinary.php';",
                    'pmssInstallPinnedRemoteDebPackage',
                    'https://pulsedmedia.com/remote/pkg/FileBot_4.9.4_amd64.deb',
                    '/usr/bin/filebot',
                    'pmssAppVersionProbeMatch',
                    ' -version 2>/dev/null',
                    '@unlink($filebotPath)',
                ],
                'forbidden' => [
                    "require_once __DIR__.'/../runtime/commands.php';" => 'FileBot installer should rely on remoteBinary.php for runtime helper bootstrap',
                    "require_once __DIR__.'/../logging.php';" => 'FileBot installer should not duplicate remoteBinary.php logging bootstrap',
                    'http://pulsedmedia.com/remote/pkg/' => 'Found insecure FileBot URL',
                    "pmssBuildCommand('dpkg'" => 'FileBot installer should delegate dpkg invocation to remoteBinary helper',
                    "runStep(\"Downloading" => 'FileBot installer should delegate download step to remoteBinary helper',
                ],
                'matches' => ['/\\x27[0-9a-f]{64}\\x27/'],
            ],
            'pyload.php' => [
                'required' => [
                    "require_once __DIR__.'/pythonVenv.php';",
                    'pmssDistroVersionFromEnv()',
                    'Skipping pyLoad setup: unsupported Debian release',
                    'Skipping pyLoad setup: python3 missing from PATH',
                    'pmssPythonVenvInstallCli(',
                    "'pyLoad'",
                    "['Installing pyLoad (pyload-ng)', 'pyload-ng']",
                    '/usr/local/bin/pyload',
                    'pyLoad binary missing after install',
                ],
            ],
            'python.php' => [
                'required' => [
                    "require_once __DIR__.'/pythonVenv.php';",
                    'Skipping FlexGet install: python3 missing from PATH',
                    'pmssPythonVenvInstallCli(',
                    "'FlexGet'",
                    'FlexGet binary missing after install',
                    'Installing gdrivefs in FlexGet venv',
                    'Installing FlexGet dependencies',
                    'Installing FlexGet',
                    'Installing youtube-dl for FlexGet',
                    '/usr/local/bin/flexget',
                ],
            ],
            'iprange.php' => [
                'required' => [
                    "empty(\$GLOBALS['PMSS_PACKAGES_READY'])",
                    'Skipping iprange build: package phase not complete',
                    'Skipping iprange build: missing toolchain packages',
                    'pmssPackageStatus($pkg)',
                    "'build-essential'",
                    "'gcc'",
                    "'make'",
                    "'gawk'",
                    "require_once __DIR__.'/remoteBinary.php';",
                    "pmssRunPinnedRemoteArchiveStep('iprange '.\$iprangeVersion.' source'",
                    'https://github.com/firehol/iprange/releases/download/v',
                    "'Building iprange from source'",
                    'make -j6',
                    'make install',
                ],
            ],
            'firehol.php' => [
                'required' => [
                    "require_once __DIR__.'/remoteBinary.php';",
                    'https://github.com/firehol/firehol/releases/download/v',
                    "pmssRunPinnedRemoteArchiveStep('FireHOL '.\$fireholVersion.' source'",
                    "'Building FireHOL from source'",
                    './configure',
                ],
            ],
            'rclone.php' => [
                'required' => [
                    "pmssEnvFlagEnabled('PMSS_RCLONE_FETCH_LATEST')",
                    'Warning: Unable to determine latest rclone version, falling back to pinned release.',
                    '/usr/bin/rclone version 2>/dev/null',
                    '/usr/bin/rclone -V 2>/dev/null',
                    'pmssCreatePrivateTempDir(',
                    "runStep('Installing rclone '.\$version",
                    "pmssBuildCommand('wget'",
                    "pmssBuildCommand('install'",
                    "pmssBuildCommand('mv'",
                    'pmssRemovePrivateTempDir',
                ],
                'forbidden' => ['passthru(' => 'rclone.php should route shelling through runStep()'],
            ],
            'remoteBinary.php' => [
                'required' => [
                    'function pmssRunPinnedRemoteArchiveStep(',
                    'function pmssRunPinnedRemoteArchiveStep(string $label, string $url, string $expectedSha256, string $archiveName, string $sourceDir, string $description, array $postExtractCommands, string $workDir = \'/root/compile\'): bool',
                    'function pmssPinnedRemoteTempFileUse(',
                    'function pmssPinnedRemoteArtifactTempFileUse(',
                    "substr(\$archiveName, -7) === '.tar.xz' ? '-xJf' : '-xzf'",
                    "'tar '.\$tarMode",
                    'function pmssInstallPinnedRemoteDebPackage',
                    'function pmssDownloadPinnedRemoteTempFile',
                    "dpkgCmd('-i '",
                    'checksum mismatch; refusing install',
                    'pmssDownloadPinnedRemoteTempFile(',
                    "'pmss-remote-bin-'",
                    "'pmss-remote-deb-'",
                    'try {',
                    '} finally {',
                ],
            ],
            'syncthing.php' => [
                'required' => [
                    "require_once __DIR__.'/remoteBinary.php';",
                    'syncthing version 2>/dev/null',
                    'pmssPinnedRemoteAmd64ArtifactsSupported()',
                    "file_exists('/usr/bin/syncthing') || is_link('/usr/bin/syncthing')",
                    "@unlink('/usr/bin/syncthing');",
                    'https://github.com/syncthing/syncthing/releases/download/',
                    'syncthing-linux-amd64-',
                    "pmssRunPinnedRemoteArchiveStep('Syncthing '.\$syncthingVersion",
                    "'Installing Syncthing binary'",
                    'install -m 0755',
                ],
            ],
            'vnstat.php' => [
                'required' => [
                    "require_once '/scripts/lib/networkInfo.php';",
                    "networkInterfaceNameNormalized((string) \$link)",
                    "runStep('Installing vnstat'",
                    "aptCmd('install -y vnstat')",
                    "runStep('Updating vnstat interface database'",
                    "pmssBuildCommand('vnstat', ['-u', '-i', \$link])",
                    "str_replace('RateUnit 1', 'RateUnit 0'",
                    'MaxBandwidth 100',
                    'Warning: unable to read /etc/vnstat.conf',
                    'Warning: unable to write /etc/vnstat.conf',
                    "runStep('Restarting vnstat'",
                    "pmssBuildCommand('/etc/init.d/vnstat', ['restart'])",
                ],
                'forbidden' => [
                    'passthru(' => 'vnstat.php should route shelling through runStep()',
                    'chown -R vnstat:vnstat /var/lib/vnstat' => 'vnstat.php should not keep Debian 8 repair branches for unsupported releases',
                    '$debianMajor' => 'vnstat.php should not parse Debian major versions for removed Debian 8 repair logic',
                ],
            ],
            'watchdog.php' => [
                'required' => [
                    'template.watchdog.conf',
                    'template.watchdog.network-check.sh',
                    '/etc/watchdog.d',
                    '/dev/watchdog0',
                    'systemctl unmask watchdog || true',
                    'systemctl enable --now watchdog',
                ],
            ],
        ], 'scripts/lib/update/apps/');
    }

    public function testPinnedDownloadInstallersReuseRemoteBinaryHelper(): void
    {
        foreach (['aiToolsInstall.php', 'deluge.php', 'rtorrent.php'] as $installer) {
            $contents = $this->pmssAssertRepoFileContainsAndOmitsStrings('scripts/lib/update/apps/'.$installer, [
                "require_once __DIR__.'/remoteBinary.php';",
            ], [
                "pmssBuildCommand('wget'" => $installer.' should delegate pinned downloads to remoteBinary.php',
                "@hash_file('sha256'" => $installer.' should delegate checksum verification to remoteBinary.php',
            ]);

            $this->assertTrue(preg_match('/pmss(?:DownloadPinnedRemoteTempFile|FetchPinnedRemoteFile|RunPinnedRemoteArchiveStep|PinnedRemote(?:TempFile|ArtifactTempFile)Use)\(/', $contents) === 1, $installer.' should call a remoteBinary.php pinned download helper');
        }
    }

    public function testSourceArchiveInstallersReuseRemoteArchiveExtraction(): void
    {
        foreach (['deluge.php', 'rtorrent.php'] as $installer) {
            $contents = $this->pmssAssertRepoFileContainsAndOmitsStrings('scripts/lib/update/apps/'.$installer, [
                'pmssRunPinnedRemoteArchiveStep(',
            ], [
                "pmssBuildCommand('tar'" => $installer.' should delegate source archive extraction to remoteBinary.php',
            ]);

            $this->assertTrue(strpos($contents, "pmssPinnedRemoteArtifactTempFileUse(") === false, $installer.' should not keep a parallel source extraction callback');
        }
    }

    public function testSyncthingPinMatchesCheckedReleaseManifest(): void
    {
        $source = $this->pmssReadRepoFile('scripts/lib/update/apps/syncthing.php');
        $this->assertSame(1, preg_match('/\$syncthingVersion\s*=\s*\'([^\']+)\';/', $source, $versionMatch), 'Unable to read Syncthing version pin');
        $this->assertSame(1, preg_match('/\$syncthingSha256\s*=\s*\'([a-f0-9]{64})\';/', $source, $shaMatch), 'Unable to read Syncthing SHA256 pin');

        $manifestPins = [
            'v2.0.13' => '55ffe8a5deefc373c95a760ab71c3cbe77da493bb9bf426525d33c2fe22ead88',
        ];

        $this->assertTrue(isset($manifestPins[$versionMatch[1]]), 'Update the Syncthing release manifest pin map when bumping Syncthing');
        $this->assertSame($manifestPins[$versionMatch[1]], $shaMatch[1], 'Syncthing SHA256 pin does not match the checked release manifest');
    }

}
