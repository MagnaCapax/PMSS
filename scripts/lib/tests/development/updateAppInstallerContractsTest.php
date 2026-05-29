<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

class UpdateAppInstallerContractsTest extends TestCase
{
    private function assertUpdateAppContainsAllStrings(string $installer, array $needles): void
    {
        $this->pmssAssertRepoFileContainsAllStrings('scripts/lib/update/apps/'.$installer, $needles);
    }

    private function assertUpdateAppContainsCases(array $cases): void
    {
        foreach ($cases as $installer => $needles) {
            $this->assertUpdateAppContainsAllStrings($installer, $needles);
        }
    }

    public function testPythonVenvInstallersKeepSharedContracts(): void
    {
        $this->assertUpdateAppContainsCases([
            'pyload.php' => [
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
            'python.php' => [
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
        ]);
    }

    public function testSourceBuildInstallersKeepPinnedContracts(): void
    {
        $this->assertUpdateAppContainsCases([
            'iprange.php' => [
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
            'firehol.php' => [
                "require_once __DIR__.'/remoteBinary.php';",
                'https://github.com/firehol/firehol/releases/download/v',
                "pmssRunPinnedRemoteArchiveStep('FireHOL '.\$fireholVersion.' source'",
                "'Building FireHOL from source'",
                './configure',
            ],
        ]);
    }

    public function testSyncthingInstallerKeepsVersionProbeAndPinnedDownload(): void
    {
        $this->assertUpdateAppContainsAllStrings('syncthing.php', [
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
        ]);
    }

    public function testRemoteBinaryOwnsPinnedArchiveExtractionScaffold(): void
    {
        $this->assertUpdateAppContainsAllStrings('remoteBinary.php', [
            'function pmssRunPinnedRemoteArchiveStep(',
            'pmssFetchPinnedRemoteFile($label, $url, $expectedSha256)',
            "substr(\$archiveName, -7) === '.tar.xz' ? '-xJf' : '-xzf'",
            "'tar '.\$tarMode",
        ]);
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

            $this->assertTrue(
                strpos($contents, 'pmssDownloadPinnedRemoteTempFile(') !== false || strpos($contents, 'pmssFetchPinnedRemoteFile(') !== false,
                $installer.' should call a remoteBinary.php pinned download helper'
            );
        }
    }

    public function testRcloneInstallerKeepsLatestFetchAndRelocationGuards(): void
    {
        $this->assertUpdateAppContainsAllStrings('rclone.php', [
            "pmssEnvFlagEnabled('PMSS_RCLONE_FETCH_LATEST')",
            'Warning: Unable to determine latest rclone version, falling back to pinned release.',
            '/usr/bin/rclone version 2>/dev/null',
            '/usr/bin/rclone -V 2>/dev/null',
            'mandb;',
            "passthru('mv /usr/sbin/rclone /usr/bin/rclone')",
        ]);
    }

    public function testVnstatInstallerKeepsSupportedConfigPathOnly(): void
    {
        $repairCommand = 'chown -R '.'vnstat:vnstat /var/lib/vnstat';
        $removedVersionVariable = '$debian'.'Major';

        $this->pmssAssertRepoFileContainsAndOmitsStrings('scripts/lib/update/apps/vnstat.php', [
            "require_once '/scripts/lib/networkInfo.php';",
            "networkInterfaceNameNormalized((string) \$link)",
            "runStep('Installing vnstat'",
            "pmssBuildCommand('apt-get', ['install', '-y', 'vnstat'])",
            "runStep('Updating vnstat interface database'",
            "pmssBuildCommand('vnstat', ['-u', '-i', \$link])",
            "str_replace('RateUnit 1', 'RateUnit 0'",
            'MaxBandwidth 100',
            'Warning: unable to read /etc/vnstat.conf',
            'Warning: unable to write /etc/vnstat.conf',
            "runStep('Restarting vnstat'",
            "pmssBuildCommand('/etc/init.d/vnstat', ['restart'])",
        ], [
            'passthru(' => 'vnstat.php should route shelling through runStep()',
            $repairCommand => 'vnstat.php should not keep Debian 8 repair branches for unsupported releases',
            $removedVersionVariable => 'vnstat.php should not parse Debian major versions for removed Debian 8 repair logic',
        ]);
    }

    public function testWatchdogInstallerKeepsTemplateAndDeviceFallbackFlow(): void
    {
        $this->assertUpdateAppContainsAllStrings('watchdog.php', [
            'template.watchdog.conf',
            'template.watchdog.network-check.sh',
            '/etc/watchdog.d',
            '/dev/watchdog0',
            'systemctl unmask watchdog || true',
            'systemctl enable --now watchdog',
        ]);
    }
}
