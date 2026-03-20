<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

class UpdateAppInstallerContractsTest extends TestCase
{
    private function readInstaller(string $name): string
    {
        $path = dirname(__DIR__, 2).'/update/apps/'.$name;
        $contents = @file_get_contents($path);
        $this->assertTrue(is_string($contents) && $contents !== '', 'Unable to read '.$path);
        return $contents;
    }

    public function testPyloadKeepsSharedVenvAndGuards(): void
    {
        $contents = $this->readInstaller('pyload.php');

        $this->assertStringContainsString("require_once __DIR__.'/pythonVenv.php';", $contents);
        $this->assertStringContainsString("getenv('PMSS_DISTRO_VERSION')", $contents);
        $this->assertStringContainsString('Skipping pyLoad setup: unsupported Debian release', $contents);
        $this->assertStringContainsString('Skipping pyLoad setup: python3 missing from PATH', $contents);
        $this->assertStringContainsString("pmssPythonVenvEnsure(\$venvDir, 'pyLoad', 'logmsg', '[WARN] Skipping pyLoad setup: python3 missing from PATH')", $contents);
    }

    public function testPyloadKeepsInstallAndLinkSteps(): void
    {
        $contents = $this->readInstaller('pyload.php');

        $this->assertStringContainsString("runStep('Installing pyLoad (pyload-ng)'", $contents);
        $this->assertStringContainsString("runStep('Linking pyLoad CLI'", $contents);
        $this->assertStringContainsString('/usr/local/bin/pyload', $contents);
        $this->assertStringContainsString('pyLoad binary missing after install', $contents);
    }

    public function testPythonInstallerKeepsSharedVenvAndWarnings(): void
    {
        $contents = $this->readInstaller('python.php');

        $this->assertStringContainsString("require_once __DIR__.'/pythonVenv.php';", $contents);
        $this->assertStringContainsString('Skipping FlexGet install: python3 missing from PATH', $contents);
        $this->assertStringContainsString("pmssPythonVenvEnsure(\$venvDir, 'FlexGet', 'logmsg', '[WARN] Skipping FlexGet install: python3 missing from PATH')", $contents);
        $this->assertStringContainsString('FlexGet binary missing after install', $contents);
    }

    public function testPythonInstallerKeepsInstallSequence(): void
    {
        $contents = $this->readInstaller('python.php');

        foreach (['Installing gdrivefs in FlexGet venv', 'Installing FlexGet dependencies', 'Installing FlexGet', 'Installing youtube-dl for FlexGet'] as $stepLabel) {
            $this->assertStringContainsString($stepLabel, $contents);
        }
        $this->assertStringContainsString("runStep('Linking FlexGet CLI'", $contents);
        $this->assertStringContainsString('/usr/local/bin/flexget', $contents);
    }

    public function testIprangeKeepsPackageAndToolchainGuards(): void
    {
        $contents = $this->readInstaller('iprange.php');

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
        $contents = $this->readInstaller('iprange.php');

        $this->assertStringContainsString("runStep('Building iprange from source'", $contents);
        $this->assertStringContainsString('wget http://pulsedmedia.com/remote/pkg/iprange-1.0.4.tar.gz -O iprange-1.0.4.tar.gz', $contents);
        $this->assertStringContainsString('make -j6', $contents);
        $this->assertStringContainsString('make install', $contents);
    }

    public function testSyncthingInstallerKeepsVersionProbeAndPinnedDownload(): void
    {
        $contents = $this->readInstaller('syncthing.php');

        $this->assertStringContainsString('syncthing -version 2>/dev/null', $contents);
        $this->assertStringContainsString('v1.18.2 "Fermium Flea"', $contents);
        $this->assertStringContainsString('http://pulsedmedia.com/remote/pkg/syncthing', $contents);
        $this->assertStringContainsString('chmod 755 /usr/bin/syncthing', $contents);
    }

    public function testRcloneInstallerKeepsLatestFetchAndRelocationGuards(): void
    {
        $contents = $this->readInstaller('rclone.php');

        $this->assertStringContainsString("getenv('PMSS_RCLONE_FETCH_LATEST') === '1'", $contents);
        $this->assertStringContainsString('Warning: Unable to determine latest rclone version, falling back to pinned release.', $contents);
        $this->assertStringContainsString('/usr/bin/rclone version 2>/dev/null', $contents);
        $this->assertStringContainsString('/usr/bin/rclone -V 2>/dev/null', $contents);
        $this->assertStringContainsString('mandb;', $contents);
        $this->assertStringContainsString("passthru('mv /usr/sbin/rclone /usr/bin/rclone')", $contents);
    }

    public function testVnstatInstallerKeepsLegacyConfigAndDebian8Repair(): void
    {
        $contents = $this->readInstaller('vnstat.php');

        $this->assertStringContainsString("require_once '/scripts/lib/networkInfo.php';", $contents);
        $this->assertStringContainsString("passthru('apt-get install vnstat -y')", $contents);
        $this->assertStringContainsString("str_replace('RateUnit 1', 'RateUnit 0'", $contents);
        $this->assertStringContainsString('MaxBandwidth 100', $contents);
        $this->assertStringContainsString('/etc/init.d/vnstat restart', $contents);
        $this->assertStringContainsString('chown -R vnstat:vnstat /var/lib/vnstat', $contents);
    }

    public function testWatchdogInstallerKeepsTemplateAndDeviceFallbackFlow(): void
    {
        $contents = $this->readInstaller('watchdog.php');

        $this->assertStringContainsString('template.watchdog.conf', $contents);
        $this->assertStringContainsString('template.watchdog.network-check.sh', $contents);
        $this->assertStringContainsString('/etc/watchdog.d', $contents);
        $this->assertStringContainsString('/dev/watchdog0', $contents);
        $this->assertStringContainsString('systemctl unmask watchdog || true', $contents);
        $this->assertStringContainsString('systemctl enable --now watchdog', $contents);
    }

    public function testPackagesBootstrapKeepsDuplicateQueueEntriesPruned(): void
    {
        $contents = $this->readInstaller('packages.php');

        foreach (['python3', 'python3-pip', 'python3-venv', 'python3-dev', 'python3-cheetah', 'zip', 'ethtool'] as $package) {
            $this->assertEquals(1, preg_match_all("/'".preg_quote($package, '/')."'/", $contents), $package.' should appear once in packages.php');
        }
    }
}
