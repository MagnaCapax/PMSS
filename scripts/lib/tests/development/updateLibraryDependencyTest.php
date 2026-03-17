<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

class UpdateLibraryDependencyTest extends TestCase
{
    private function loadSource(string $relativePath): string
    {
        $path = dirname(__DIR__, 4).'/scripts/'.$relativePath;
        $contents = @file_get_contents($path);
        $this->assertTrue(is_string($contents) && $contents !== '', 'Unable to read '.$path);
        return $contents;
    }

    public function testConfigureOpenvpnUsesDirectRuntimeLibraries(): void
    {
        $source = $this->loadSource('util/configureOpenvpn.php');

        $this->assertStringContainsString("require_once __DIR__.'/../lib/logger.php';", $source);
        $this->assertStringContainsString("require_once __DIR__.'/../lib/update/runtime/commands.php';", $source);
        $this->assertTrue(strpos($source, "require_once __DIR__.'/../lib/update.php';") === false, 'configureOpenvpn.php should not pull scripts/lib/update.php just for runtime helpers');
    }

    public function testSetupLetsEncryptUsesDirectDistroLibrary(): void
    {
        $source = $this->loadSource('util/setupLetsEncrypt.php');

        $this->assertStringContainsString("require_once __DIR__.'/../lib/update/distro.php';", $source);
        $this->assertTrue(strpos($source, "require_once __DIR__.'/../lib/update.php';") === false, 'setupLetsEncrypt.php should not pull scripts/lib/update.php just for distro detection');
    }

    public function testUserConfigUsesDirectSubsystemLibraries(): void
    {
        $source = $this->loadSource('util/userConfig.php');

        $this->assertStringContainsString("require_once __DIR__.'/../lib/rtorrentConfig.php';", $source);
        $this->assertStringContainsString("require_once __DIR__.'/../lib/update/runtime/commands.php';", $source);
        $this->assertTrue(strpos($source, "require_once __DIR__.'/../lib/update.php';") === false, 'userConfig.php should rely on direct subsystem requires');
    }

    public function testMotdGeneratorUsesDirectDistroLibrary(): void
    {
        $source = $this->loadSource('lib/motd/Generator.php');

        $this->assertStringContainsString("require_once __DIR__.'/../update/distro.php';", $source);
        $this->assertTrue(strpos($source, "require_once __DIR__.'/../update.php';") === false, 'Motd generator should not pull scripts/lib/update.php just for distro detection');
    }
}
