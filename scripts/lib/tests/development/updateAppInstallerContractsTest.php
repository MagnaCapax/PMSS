<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

class UpdateAppInstallerContractsTest extends TestCase
{
    public function testPyloadKeepsSharedVenvAndGuards(): void
    {
        $contents = $this->pmssReadUpdateAppFile('pyload.php');

        $this->assertStringContainsString("require_once __DIR__.'/pythonVenv.php';", $contents);
        $this->assertStringContainsString('pmssDistroVersionFromEnv()', $contents);
        $this->assertStringContainsString('Skipping pyLoad setup: unsupported Debian release', $contents);
        $this->assertStringContainsString('Skipping pyLoad setup: python3 missing from PATH', $contents);
        $this->assertStringContainsString('pmssPythonVenvInstallCli(', $contents);
        $this->assertStringContainsString("'pyLoad'", $contents);
    }

    public function testPyloadKeepsInstallAndLinkSteps(): void
    {
        $contents = $this->pmssReadUpdateAppFile('pyload.php');

        $this->assertStringContainsString("['Installing pyLoad (pyload-ng)', 'pyload-ng']", $contents);
        $this->assertStringContainsString('/usr/local/bin/pyload', $contents);
        $this->assertStringContainsString('pyLoad binary missing after install', $contents);
    }

    public function testPythonInstallerKeepsSharedVenvAndWarnings(): void
    {
        $contents = $this->pmssReadUpdateAppFile('python.php');

        $this->assertStringContainsString("require_once __DIR__.'/pythonVenv.php';", $contents);
        $this->assertStringContainsString('Skipping FlexGet install: python3 missing from PATH', $contents);
        $this->assertStringContainsString('pmssPythonVenvInstallCli(', $contents);
        $this->assertStringContainsString("'FlexGet'", $contents);
        $this->assertStringContainsString('FlexGet binary missing after install', $contents);
    }

    public function testPythonInstallerKeepsInstallSequence(): void
    {
        $contents = $this->pmssReadUpdateAppFile('python.php');

        foreach (['Installing gdrivefs in FlexGet venv', 'Installing FlexGet dependencies', 'Installing FlexGet', 'Installing youtube-dl for FlexGet'] as $stepLabel) {
            $this->assertStringContainsString($stepLabel, $contents);
        }
        $this->assertStringContainsString('/usr/local/bin/flexget', $contents);
    }

    public function testIprangeKeepsPackageAndToolchainGuards(): void
    {
        $contents = $this->pmssReadUpdateAppFile('iprange.php');

        $this->assertStringContainsString("empty(\$GLOBALS['PMSS_PACKAGES_READY'])", $contents);
        $this->assertStringContainsString('Skipping iprange build: package phase not complete', $contents);
        $this->assertStringContainsString('Skipping iprange build: missing toolchain packages', $contents);
        $this->assertStringContainsString('pmssPackageStatus($pkg)', $contents);
        foreach (['build-essential', 'gcc', 'make', 'gawk'] as $package) {
            $this->assertStringContainsString("'{$package}'", $contents);
        }
    }

    public function testIprangeKeepsCompileStep(): void
    {
        $contents = $this->pmssReadUpdateAppFile('iprange.php');

        $this->assertStringContainsString("require_once __DIR__.'/remoteBinary.php';", $contents);
        $this->assertStringContainsString("pmssRunPinnedRemoteArchiveStep('iprange '.\$iprangeVersion.' source'", $contents);
        $this->assertStringContainsString('https://github.com/firehol/iprange/releases/download/v', $contents);
        $this->assertStringContainsString("'Building iprange from source'", $contents);
        $this->assertStringContainsString('make -j6', $contents);
        $this->assertStringContainsString('make install', $contents);
    }

    public function testSyncthingInstallerKeepsVersionProbeAndPinnedDownload(): void
    {
        $contents = $this->pmssReadUpdateAppFile('syncthing.php');

        $this->assertStringContainsString("require_once __DIR__.'/remoteBinary.php';", $contents);
        $this->assertStringContainsString("syncthing version 2>/dev/null", $contents);
        $this->assertStringContainsString('pmssPinnedRemoteAmd64ArtifactsSupported()', $contents);
        $this->assertStringContainsString('https://github.com/syncthing/syncthing/releases/download/', $contents);
        $this->assertStringContainsString('syncthing-linux-amd64-', $contents);
        $this->assertStringContainsString("pmssRunPinnedRemoteArchiveStep('Syncthing '.\$syncthingVersion", $contents);
        $this->assertStringContainsString("'Installing Syncthing binary'", $contents);
        $this->assertStringContainsString('install -m 0755', $contents);
    }

    public function testFireholInstallerKeepsPinnedSourceBuild(): void
    {
        $contents = $this->pmssReadUpdateAppFile('firehol.php');

        $this->assertStringContainsString("require_once __DIR__.'/remoteBinary.php';", $contents);
        $this->assertStringContainsString('https://github.com/firehol/firehol/releases/download/v', $contents);
        $this->assertStringContainsString("pmssRunPinnedRemoteArchiveStep('FireHOL '.\$fireholVersion.' source'", $contents);
        $this->assertStringContainsString("'Building FireHOL from source'", $contents);
        $this->assertStringContainsString('./configure', $contents);
    }

    public function testRemoteBinaryOwnsPinnedArchiveExtractionScaffold(): void
    {
        $contents = $this->pmssReadUpdateAppFile('remoteBinary.php');

        $this->assertStringContainsString('function pmssRunPinnedRemoteArchiveStep(', $contents);
        $this->assertStringContainsString('pmssFetchPinnedRemoteFile($label, $url, $expectedSha256)', $contents);
        $this->assertStringContainsString("substr(\$archiveName, -7) === '.tar.xz' ? '-xJf' : '-xzf'", $contents);
        $this->assertStringContainsString("'tar '.\$tarMode", $contents);
    }

    public function testPinnedDownloadInstallersReuseRemoteBinaryHelper(): void
    {
        foreach (['aiToolsInstall.php', 'deluge.php', 'rtorrent.php'] as $installer) {
            $contents = $this->pmssReadUpdateAppFile($installer);

            $this->assertStringContainsString("require_once __DIR__.'/remoteBinary.php';", $contents);
            $this->assertTrue(
                strpos($contents, 'pmssDownloadPinnedRemoteTempFile(') !== false || strpos($contents, 'pmssFetchPinnedRemoteFile(') !== false,
                $installer.' should call a remoteBinary.php pinned download helper'
            );
            $this->assertTrue(strpos($contents, "pmssBuildCommand('wget'") === false, $installer.' should delegate pinned downloads to remoteBinary.php');
            $this->assertTrue(strpos($contents, "@hash_file('sha256'") === false, $installer.' should delegate checksum verification to remoteBinary.php');
        }
    }

    public function testRcloneInstallerKeepsLatestFetchAndRelocationGuards(): void
    {
        $contents = $this->pmssReadUpdateAppFile('rclone.php');

        $this->assertStringContainsString("getenv('PMSS_RCLONE_FETCH_LATEST') === '1'", $contents);
        $this->assertStringContainsString('Warning: Unable to determine latest rclone version, falling back to pinned release.', $contents);
        $this->assertStringContainsString('/usr/bin/rclone version 2>/dev/null', $contents);
        $this->assertStringContainsString('/usr/bin/rclone -V 2>/dev/null', $contents);
        $this->assertStringContainsString('mandb;', $contents);
        $this->assertStringContainsString("passthru('mv /usr/sbin/rclone /usr/bin/rclone')", $contents);
    }

    public function testVnstatInstallerKeepsSupportedConfigPathOnly(): void
    {
        $contents = $this->pmssReadUpdateAppFile('vnstat.php');
        $repairCommand = 'chown -R '.'vnstat:vnstat /var/lib/vnstat';
        $removedVersionVariable = '$debian'.'Major';

        $this->assertStringContainsString("require_once '/scripts/lib/networkInfo.php';", $contents);
        $this->assertStringContainsString("passthru('apt-get install vnstat -y')", $contents);
        $this->assertStringContainsString("str_replace('RateUnit 1', 'RateUnit 0'", $contents);
        $this->assertStringContainsString('MaxBandwidth 100', $contents);
        $this->assertStringContainsString('/etc/init.d/vnstat restart', $contents);
        $this->assertTrue(
            strpos($contents, $repairCommand) === false,
            'vnstat.php should not keep Debian 8 repair branches for unsupported releases'
        );
        $this->assertTrue(
            strpos($contents, $removedVersionVariable) === false,
            'vnstat.php should not parse Debian major versions for removed Debian 8 repair logic'
        );
    }

    public function testWatchdogInstallerKeepsTemplateAndDeviceFallbackFlow(): void
    {
        $contents = $this->pmssReadUpdateAppFile('watchdog.php');

        $this->assertStringContainsString('template.watchdog.conf', $contents);
        $this->assertStringContainsString('template.watchdog.network-check.sh', $contents);
        $this->assertStringContainsString('/etc/watchdog.d', $contents);
        $this->assertStringContainsString('/dev/watchdog0', $contents);
        $this->assertStringContainsString('systemctl unmask watchdog || true', $contents);
        $this->assertStringContainsString('systemctl enable --now watchdog', $contents);
    }

}
