<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

class UpdateAppInstallerContractsTest extends TestCase
{
    public function testInstallersKeepSourceContracts(): void
    {
        $this->pmssAssertRepoFileContainsAllStrings('scripts/lib/update/apps/aiToolsInstall.php', [
            "require_once __DIR__.'/aiToolsCodex.php';",
            'PMSS_FORCE_AI_TOOLS_REFRESH',
            "pmssRequireRelativeFiles(__DIR__.'/aiTools', ['node.php', 'npm.php', 'codexInstall.php'])",
            'pmssAiToolsNodeRuntimeResolve(', 'pmssAiToolsNpmInstall(', 'pmssAiToolsCodexInstall(',
        ]);
        $this->pmssAssertRepoFileContract('scripts/lib/update/apps/aiTools/node.php', [
            'required' => [
                "\$nodeVersion = '22.23.4';",
                "\$nodeArchive = basename(\$nodeDir).'.tar.xz';",
                "pmssAppVersionProbeMatch([escapeshellarg(\$systemNode)",
                '>= 22',
            ],
            'forbidden' => ['function pmssAiTools'.'NodeMajor(' => 'Node major parsing should stay inline'],
            'matches' => ['/[0-9a-f]{64}/'],
        ]);
        $this->pmssAssertRepoFileContainsAndOmitsStrings('scripts/lib/update/apps/aiTools/npm.php', [
            '@google/gemini-cli',
            '@anthropic-ai/claude-code',
            "['Claude Code', '@anthropic-ai/claude-code', '2.1.112', 'claude']",
            "\$installTarget = \$toolSpec[1].(\$toolSpec[2] === '' ? '' : '@'.\$toolSpec[2]);",
            "lib/node_modules/'.\$toolSpec[1].'/package.json",
            "\$launcherSource = dirname(__DIR__).'/templates/pmss-ai-launch.php';",
            "\$launcher = '/opt/pmss/ai-tools/pmss-ai-launch.php';",
            "dirname(\$nodeBinary).'/npm'",
            'npm not available',
            "putenv('PATH='.dirname(\$nodeBinary)",
            'pmssAiToolsFilesPublish(',
        ], ['function pmssAiTools'.'InstallNpmCli(' => 'Avoid per-package installation wrappers']);
        $this->pmssAssertRepoFileContract('scripts/lib/update/apps/aiTools/codexInstall.php', [
            'required' => [
                '/usr/local/bin/codex',
                "\$codexRoot = '/opt/pmss/ai-tools/codex';",
                "\$launcherSource = dirname(__DIR__).'/templates/pmss-ai-launch.php';",
                "\$launcher = '/opt/pmss/ai-tools/pmss-ai-launch.php';",
                'pmssAiToolsCodexInstallNeeded(',
                'pmssAiToolsFilesPublish(',
                'PMSS_AI_CODEX_CHAT_TAGS',
                'codex-x86_64-unknown-linux-musl.tar.gz',
                '/etc/codex/config.toml',
                'danger-full-access',
            ],
            'matches' => ['/[0-9a-f]{64}/'],
        ]);
        $this->pmssAssertRepoFileContainsAllStrings('scripts/lib/update/apps/aiTools/publication.php', ['pmssAiToolsCodexAtomicWrite(', 'keeping existing command']);
        $this->pmssAssertRepoFileContract('scripts/lib/update/apps/btsync.php', [
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
        ]);
        $this->pmssAssertRepoFileContract('scripts/lib/update/apps/filebot.php', [
            'required' => [
                "require_once __DIR__.'/remoteBinary.php';",
                'pmssInstallPinnedRemoteDebPackage',
                'https://pulsedmedia.com/remote/pkg/FileBot_4.9.4_amd64.deb',
                '/usr/bin/filebot',
                ' -version 2>/dev/null',
                "pmssPinnedRemoteBinaryPrepareInstall(\$filebotPath,",
            ],
            'forbidden' => [
                "require_once __DIR__.'/../runtime/commands.php';" => 'FileBot installer should rely on remoteBinary.php for runtime helper bootstrap',
                "require_once __DIR__.'/../logging.php';" => 'FileBot installer should not duplicate remoteBinary.php logging bootstrap',
                'http://pulsedmedia.com/remote/pkg/' => 'Found insecure FileBot URL',
                "pmssBuildCommand('dpkg'" => 'FileBot installer should delegate dpkg invocation to remoteBinary helper',
                "runStep(\"Downloading" => 'FileBot installer should delegate download step to remoteBinary helper',
                '@unlink($filebotPath)' => 'FileBot installer must guard stale binary removal',
            ],
            'matches' => ['/\\x27[0-9a-f]{64}\\x27/'],
        ]);
        $this->pmssAssertRepoFileContainsAllStrings('scripts/lib/update/apps/pyload.php', [
            "require_once __DIR__.'/pythonVenv.php';",
            'pmssDistroVersionFromEnv()',
            'Skipping pyLoad setup: unsupported Debian release',
            'Skipping pyLoad setup: python3 missing from PATH',
            'pmssPythonVenvInstallCli(',
            "'pyLoad'",
            "['Installing pyLoad (pyload-ng)', 'pyload-ng']",
            '/usr/local/bin/pyload',
            'pyLoad binary missing after install',
        ]);
        $this->pmssAssertRepoFileContainsAndOmitsStrings('scripts/lib/update/apps/python.php', [
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
        ], [
            'certifi==' => 'FlexGet venv must not pin certifi (2017 pin downgraded the CA bundle every update)',
            'chardet==' => 'FlexGet venv must not pin chardet (py2-era pin)',
        ]);
        $this->pmssAssertRepoFileContainsAllStrings('scripts/lib/update/apps/iprange.php', [
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
        ]);
        $this->pmssAssertRepoFileContainsAllStrings('scripts/lib/update/apps/firehol.php', [
            "require_once __DIR__.'/remoteBinary.php';",
            'https://github.com/firehol/firehol/releases/download/v',
            "pmssRunPinnedRemoteArchiveStep('FireHOL '.\$fireholVersion.' source'",
            "'Building FireHOL from source'",
            './configure',
        ]);
        $this->pmssAssertRepoFileContainsAndOmitsStrings('scripts/lib/update/apps/rclone.php', [
            '/usr/bin/rclone version 2>/dev/null',
            '/usr/bin/rclone -V 2>/dev/null',
            'pmssCreatePrivateTempDir(',
            "runStep('Installing rclone '.\$version",
            'pmssPinnedRemoteArtifactTempFileUse(',
            "pmssPinnedRemoteAmd64OrSkip('rclone', 'install')",
            "pmssBuildCommand('install'",
            "pmssBuildCommand('mv'",
            'pmssRemovePrivateTempDir',
        ], ['passthru(' => 'rclone.php should route shelling through runStep()']);
        $this->pmssAssertRepoFileContainsAllStrings('scripts/lib/update/apps/remoteBinary.php', [
            'function pmssInstallPinnedRemoteDebPackage',
            "dpkgCmd('-i '",
            "'pmss-remote-deb-'",
            "require_once __DIR__.'/remoteBinary/versionProbe.php';",
            "require_once __DIR__.'/remoteBinary/artifact.php';",
            "require_once __DIR__.'/remoteBinary/archive.php';",
        ]);
        $this->pmssAssertRepoFileContainsAllStrings('scripts/lib/update/apps/remoteBinary/archive.php', [
            'function pmssPinnedRemotePortableInstall(',
            'function pmssRunPinnedRemoteArchiveStep(',
            'function pmssRunPinnedRemoteArchiveStep(string $label, string $url, string $expectedSha256, string $archiveName, string $sourceDir, string $description, array $postExtractCommands, string $workDir = \'/root/compile\'): bool',
            "substr(\$archiveName, -7) === '.tar.xz' ? '-xJf' : '-xzf'",
            "'tar '.\$tarMode",
        ]);
        $this->pmssAssertRepoFileContainsAllStrings('scripts/lib/update/apps/remoteBinary/artifact.php', [
            'function pmssPinnedRemoteArtifactTempFileUse(',
            'checksum mismatch; refusing install',
            "'pmss-remote-bin-'",
            'try {',
            '} finally {',
        ]);
        $this->pmssAssertRepoFileContainsAndOmitsStrings('scripts/lib/update/apps/syncthing.php', [
            "require_once __DIR__.'/remoteBinary.php';",
            'syncthing version 2>/dev/null',
            "pmssPinnedRemoteAmd64OrSkip('Syncthing')",
            "pmssPinnedRemoteBinaryPrepareInstall('/usr/bin/syncthing',",
            'https://github.com/syncthing/syncthing/releases/download/',
            'syncthing-linux-amd64-',
            "pmssRunPinnedRemoteArchiveStep('Syncthing '.\$syncthingVersion",
            "'Installing Syncthing binary'",
            'install -m 0755',
        ], ["pmssPathExistsOrLink('/usr/bin/syncthing')" => 'The stale-binary helper handles absent paths']);
        $this->pmssAssertRepoFileContainsAndOmitsStrings('scripts/lib/update/apps/vnstat.php', [
            "require_once '/scripts/lib/networkInfo.php';",
            "require_once __DIR__.'/vnstatConfig.php';",
            "pmssNetworkInterfaceNameNormalize((string) \$link)",
            "runStep('Installing vnstat'",
            "aptCmd('install -y vnstat')",
            "runStep('Updating vnstat interface database'",
            "pmssBuildCommand('vnstat', ['-u', '-i', \$link])",
            "pmssVnstatConfigRefresh('/etc/vnstat.conf'",
            "runStep('Restarting vnstat'",
            "pmssBuildCommand('/etc/init.d/vnstat', ['restart'])",
        ], [
            'passthru(' => 'vnstat.php should route shelling through runStep()',
            'chown -R vnstat:vnstat /var/lib/vnstat' => 'vnstat.php should not keep Debian 8 repair branches for unsupported releases',
            '$debianMajor' => 'vnstat.php should not parse Debian major versions for removed Debian 8 repair logic',
        ]);
        $this->pmssAssertRepoFileContainsAllStrings('scripts/lib/update/apps/vnstatConfig.php', [
            "str_replace('RateUnit 1', 'RateUnit 0'",
            'MaxBandwidth 50000',
            'pmssReadRegularFileContents($path)',
            'pmssReplaceUserFilePreservingMetadata',
            'skipping vnStat restart',
        ]);
        $this->pmssAssertRepoFileContainsAllStrings('scripts/lib/update/apps/watchdog.php', [
            'template.watchdog.conf',
            'template.watchdog.network-check.sh',
            '/etc/watchdog.d',
            '/dev/watchdog0',
            'systemctl disable --now watchdog.service',
            'systemctl enable --now watchdog.service',
        ]);
    }

    public function testPinnedDownloadInstallersReuseRemoteBinaryHelper(): void
    {
        foreach (['aiTools/node.php' => 'aiToolsInstall.php', 'aiTools/codexInstall.php' => 'aiToolsInstall.php',
            'deluge.php' => 'deluge.php', 'rtorrent.php' => 'rtorrent.php'] as $installer => $loader) {
            $this->pmssAssertRepoFileContainsAllStrings('scripts/lib/update/apps/'.$loader, [
                "require_once __DIR__.'/remoteBinary.php';",
            ]);
            $contents = $this->pmssAssertRepoFileContainsAndOmitsStrings('scripts/lib/update/apps/'.$installer, [], [
                "pmssBuildCommand('wget'" => $installer.' should delegate pinned downloads to remoteBinary.php',
                "@hash_file('sha256'" => $installer.' should delegate checksum verification to remoteBinary.php',
            ]);

            $this->assertTrue(preg_match('/pmss(?:RunPinnedRemoteArchiveStep|PinnedRemote(?:TempFile|ArtifactTempFile)Use)\(/', $contents) === 1, $installer.' should call a remoteBinary.php pinned download helper');
        }
    }

    public function testSourceArchiveInstallersReuseRemoteArchiveExtraction(): void
    {
        foreach (['deluge.php', 'rtorrent.php'] as $installer) {
            $this->pmssAssertRepoFileContainsAndOmitsStrings('scripts/lib/update/apps/'.$installer, [
                'pmssRunPinnedRemoteArchiveStep(',
            ], [
                "pmssBuildCommand('tar'" => $installer.' should delegate source archive extraction to remoteBinary.php',
                'pmssPinnedRemoteArtifactTempFileUse(' => $installer.' should not keep a parallel source extraction callback',
            ]);
        }
    }

    public function testRtorrentXmlrpcBuildUsesRootCompileWorkspace(): void
    {
        $this->pmssAssertRepoFileContainsAndOmitsStrings('scripts/lib/update/apps/rtorrent.php', [
            '$xmlrpcBuildRoot = \'/root/compile\';',
            '$xmlrpcBuildDir = $xmlrpcBuildRoot.\'/xmlrpc-c\';',
            '"Checking out and building xmlrpc-c rev {$xmlrpcVersion}"',
            "'set -e'",
            "'mkdir -p '.escapeshellarg(\$xmlrpcBuildRoot)",
            "'rm -rf '.escapeshellarg(\$xmlrpcBuildDir)",
            "pmssBuildCommand('svn', ['checkout', 'https://svn.code.sf.net/p/xmlrpc-c/code/advanced', \$xmlrpcBuildDir, '-r', \$xmlrpcVersion])",
            "'cd '.escapeshellarg(\$xmlrpcBuildDir)",
        ], ['cd /tmp/xmlrpc-c',
            "pmssBuildCommand('svn', ['checkout', 'https://svn.code.sf.net/p/xmlrpc-c/code/advanced', 'xmlrpc-c'"]);
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
