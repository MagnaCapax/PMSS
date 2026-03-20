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

    public function testDistroLibraryUsesDirectLegacyLogLibrary(): void
    {
        $source = $this->loadSource('lib/update/distro.php');

        $this->assertStringContainsString("require_once __DIR__.'/../log.php';", $source);
        $this->assertTrue(strpos($source, "require_once __DIR__.'/runtime/commands.php';") === false, 'distro.php should not pull runtime/commands.php just to expose logmsg()');
    }

    public function testNetworkingLibraryAvoidsRuntimeCommandsBootstrap(): void
    {
        $source = $this->loadSource('lib/update/networking.php');

        $this->assertStringContainsString("require_once __DIR__.'/logging.php';", $source);
        $this->assertTrue(strpos($source, "require_once __DIR__.'/runtime/commands.php';") === false, 'networking.php should not pull runtime/commands.php when it only selects a logger');
    }

    public function testQbittorrentLibraryAvoidsUpdateRuntimeBootstrap(): void
    {
        $source = $this->loadSource('lib/user/qbittorrent.php');

        $this->assertStringContainsString("require_once __DIR__.'/traffic.php';", $source);
        $this->assertTrue(strpos($source, "require_once __DIR__.'/../update/runtime/commands.php';") === false, 'qbittorrent.php should not bootstrap update runtime helpers it does not use');
    }

    public function testUpdateRuntimeCommandsKeepsOnlyRunUserStepOverrideGuard(): void
    {
        $source = $this->loadSource('lib/update/runtime/commands.php');

        $this->assertStringContainsString("if (!function_exists('runUserStep')) {", $source);
        foreach (['runStep', 'aptCmd', 'pmssBuildCommand', 'pmssLogStatus'] as $functionName) {
            $this->assertTrue(
                strpos($source, "if (!function_exists('".$functionName."')) {") === false,
                'runtime/commands.php should rely on require_once for '.$functionName
            );
        }
    }

    public function testUpdateLoggingUsesDirectJsonHelpers(): void
    {
        $source = $this->loadSource('lib/update/logging.php');

        $this->assertStringContainsString("if (!function_exists('pmssCorrelationId')) {", $source);
        foreach (['pmssJsonLogPath', 'pmssLogJson'] as $functionName) {
            $this->assertTrue(
                strpos($source, "if (!function_exists('".$functionName."')) {") === false,
                'logging.php should rely on require_once for '.$functionName
            );
        }
    }

    public function testConfigureOpenvpnUsesDirectPmssLogStatus(): void
    {
        $source = $this->loadSource('util/configureOpenvpn.php');

        $this->assertStringContainsString("pmssLogStatus('SKIP', 'OpenVPN already configured; skipping provisioning', 0);", $source);
        $this->assertTrue(
            strpos($source, "function_exists('pmssLogStatus')") === false,
            'configureOpenvpn.php should rely on runtime/commands.php for pmssLogStatus()'
        );
    }

    public function testUserConfigUsesDirectPmssLogStatus(): void
    {
        $source = $this->loadSource('util/userConfig.php');

        $this->assertStringContainsString("pmssLogStatus('SKIP', 'Rootless Docker disabled by config for '.\$user['name']);", $source);
        $this->assertTrue(
            strpos($source, "function_exists('pmssLogStatus')") === false,
            'userConfig.php should rely on runtime/commands.php for pmssLogStatus()'
        );
    }

    public function testPackageHelpersUseDirectPmssLogJson(): void
    {
        $source = $this->loadSource('lib/update/apps/packages/helpers.php');

        $this->assertStringContainsString('pmssLogJson([', $source);
        $this->assertTrue(
            strpos($source, "function_exists('pmssLogJson')") === false,
            'packages/helpers.php should rely on runtime/commands.php for pmssLogJson()'
        );
    }

    public function testUpdateStep2UsesBootstrappedRuntimeHelpersDirectly(): void
    {
        $source = $this->loadSource('util/update-step2.php');

        $this->assertStringContainsString("runStep('Restoring root cron configuration (shutdown)', \$helper);", $source);
        $this->assertStringContainsString("\$jsonPath = pmssJsonLogPath();", $source);
        $this->assertStringContainsString("\$pmssCorrelationId = pmssCorrelationId();", $source);
        foreach (["function_exists('runStep')", "function_exists('pmssJsonLogPath')", "function_exists('pmssCorrelationId')"] as $needle) {
            $this->assertTrue(
                strpos($source, $needle) === false,
                'update-step2.php should rely on the shared bootstrap for '.$needle
            );
        }
    }
}
