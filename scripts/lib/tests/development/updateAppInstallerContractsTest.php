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
        $this->assertStringContainsString("pmssPythonVenvEnsure(\$venvDir, 'pyLoad', 'logmsg')", $contents);
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
        $this->assertStringContainsString("pmssPythonVenvEnsure(\$venvDir, 'FlexGet', 'logmsg')", $contents);
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
}
