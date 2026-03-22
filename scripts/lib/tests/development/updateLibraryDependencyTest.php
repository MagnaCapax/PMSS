<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

class UpdateLibraryDependencyTest extends TestCase
{
    private function loadSource(string $relativePath): string
    {
        return $this->pmssReadRepoFile('scripts/'.$relativePath);
    }

    public function testConfigureOpenvpnUsesDirectRuntimeLibraries(): void
    {
        $source = $this->loadSource('util/configureOpenvpn.php');

        $this->assertStringContainsAllStrings(["require_once __DIR__.'/../lib/logger.php';", "require_once __DIR__.'/../lib/update/runtime/commands.php';"], $source);
        $this->pmssAssertStringNotContainsString("require_once __DIR__.'/../lib/update.php';", $source, 'configureOpenvpn.php should not pull scripts/lib/update.php just for runtime helpers');
    }

    public function testSetupLetsEncryptUsesDirectDistroLibrary(): void
    {
        $source = $this->loadSource('util/setupLetsEncrypt.php');

        $this->assertStringContainsString("require_once __DIR__.'/../lib/update/distro.php';", $source);
        $this->pmssAssertStringNotContainsString("require_once __DIR__.'/../lib/update.php';", $source, 'setupLetsEncrypt.php should not pull scripts/lib/update.php just for distro detection');
    }

    public function testUserConfigUsesDirectSubsystemLibraries(): void
    {
        $source = $this->loadSource('util/userConfig.php');

        $this->assertStringContainsAllStrings(["require_once __DIR__.'/../lib/rtorrentConfig.php';", "require_once __DIR__.'/../lib/update/runtime/commands.php';"], $source);
        $this->pmssAssertStringNotContainsString("require_once __DIR__.'/../lib/update.php';", $source, 'userConfig.php should rely on direct subsystem requires');
    }

    public function testMotdGeneratorUsesDirectDistroLibrary(): void
    {
        $source = $this->loadSource('lib/motd/Generator.php');

        $this->assertStringContainsString("require_once __DIR__.'/../update/distro.php';", $source);
        $this->pmssAssertStringNotContainsString("require_once __DIR__.'/../update.php';", $source, 'Motd generator should not pull scripts/lib/update.php just for distro detection');
    }

    public function testDistroLibraryUsesDirectLegacyLogLibrary(): void
    {
        $source = $this->loadSource('lib/update/distro.php');

        $this->assertStringContainsString("require_once __DIR__.'/../log.php';", $source);
        $this->pmssAssertStringNotContainsString("require_once __DIR__.'/runtime/commands.php';", $source, 'distro.php should not pull runtime/commands.php just to expose logmsg()');
    }

    public function testWireguardLibraryUsesDirectLegacyLogLibrary(): void
    {
        $source = $this->loadSource('lib/wireguard.php');

        $this->assertStringContainsString("require_once __DIR__.'/log.php';", $source);
        $this->pmssAssertStringNotContainsString("require_once __DIR__.'/logger.php';", $source, 'wireguard.php should load log.php directly when it only needs logmsg()');
    }

    public function testNetworkingLibraryAvoidsRuntimeCommandsBootstrap(): void
    {
        $source = $this->loadSource('lib/update/networking.php');

        $this->assertStringContainsString("require_once __DIR__.'/logging.php';", $source);
        $this->pmssAssertStringNotContainsString("require_once __DIR__.'/runtime/commands.php';", $source, 'networking.php should not pull runtime/commands.php when it only selects a logger');
    }

    public function testQbittorrentLibraryAvoidsUpdateRuntimeBootstrap(): void
    {
        $source = $this->loadSource('lib/user/qbittorrent.php');

        $this->assertStringContainsString("require_once __DIR__.'/traffic.php';", $source);
        $this->pmssAssertStringNotContainsString("require_once __DIR__.'/../update/runtime/commands.php';", $source, 'qbittorrent.php should not bootstrap update runtime helpers it does not use');
    }

    public function testUpdateRuntimeCommandsKeepsOnlyRunUserStepOverrideGuard(): void
    {
        $source = $this->loadSource('lib/update/runtime/commands.php');

        $this->assertStringContainsString("if (!function_exists('runUserStep')) {", $source);
        foreach (['runStep', 'aptCmd', 'pmssBuildCommand', 'pmssLogStatus'] as $functionName) {
            $this->pmssAssertStringNotContainsString(
                "if (!function_exists('".$functionName."')) {",
                $source,
                'runtime/commands.php should rely on require_once for '.$functionName
            );
        }
    }

    public function testUpdateLoggingUsesDirectJsonHelpers(): void
    {
        $source = $this->loadSource('lib/update/logging.php');

        $this->assertStringContainsString("if (!function_exists('pmssCorrelationId')) {", $source);
        foreach (['pmssJsonLogPath', 'pmssLogJson'] as $functionName) {
            $this->pmssAssertStringNotContainsString(
                "if (!function_exists('".$functionName."')) {",
                $source,
                'logging.php should rely on require_once for '.$functionName
            );
        }
    }

    public function testSystemdServiceGuardUsesSharedProcessesLibraryViaServiceHelper(): void
    {
        $serviceSource = $this->loadSource('lib/update/services/systemd.php');
        $cronSource = $this->loadSource('cron/systemdServicesGuard.php');

        $this->assertStringContainsString("require_once __DIR__.'/../runtime/processes.php';", $serviceSource);
        $this->pmssAssertStringNotContainsString('function_exists(\'pmssSystemdUnitExists\')', $serviceSource, 'services/systemd.php should rely on runtime/processes.php for pmssSystemdUnitExists()');
        $this->assertStringContainsString("require_once __DIR__.'/../lib/update/services/systemd.php';", $cronSource);
        $this->pmssAssertStringNotContainsString("require_once __DIR__.'/../lib/update/runtime/processes.php';", $cronSource, 'systemdServicesGuard.php should rely on services/systemd.php to bootstrap runtime/processes.php');
    }

    public function testConfigureOpenvpnUsesDirectPmssLogStatus(): void
    {
        $source = $this->loadSource('util/configureOpenvpn.php');

        $this->assertStringContainsString("pmssLogStatus('SKIP', 'OpenVPN already configured; skipping provisioning', 0);", $source);
        $this->pmssAssertStringNotContainsString("function_exists('pmssLogStatus')", $source, 'configureOpenvpn.php should rely on runtime/commands.php for pmssLogStatus()');
    }

    public function testUserConfigUsesDirectPmssLogStatus(): void
    {
        $source = $this->loadSource('util/userConfig.php');

        $this->assertStringContainsString("pmssLogStatus('SKIP', 'Rootless Docker disabled by config for '.\$user['name']);", $source);
        $this->pmssAssertStringNotContainsString("function_exists('pmssLogStatus')", $source, 'userConfig.php should rely on runtime/commands.php for pmssLogStatus()');
    }

    public function testPackageHelpersUseDirectPmssLogJson(): void
    {
        $source = $this->loadSource('lib/update/apps/packages/helpers.php');

        $this->assertStringContainsString('pmssLogJson([', $source);
        $this->pmssAssertStringNotContainsString("function_exists('pmssLogJson')", $source, 'packages/helpers.php should rely on runtime/commands.php for pmssLogJson()');
    }

    public function testUpdateStep2UsesBootstrappedRuntimeHelpersDirectly(): void
    {
        $source = $this->loadSource('util/update-step2.php');

        $this->assertStringContainsString("runStep('Restoring root cron configuration (shutdown)', \$helper);", $source);
        $this->assertStringContainsString("\$jsonPath = pmssJsonLogPath();", $source);
        $this->assertStringContainsString("\$pmssCorrelationId = pmssCorrelationId();", $source);
        foreach (["function_exists('runStep')", "function_exists('pmssJsonLogPath')", "function_exists('pmssCorrelationId')"] as $needle) {
            $this->pmssAssertStringNotContainsString(
                $needle,
                $source,
                'update-step2.php should rely on the shared bootstrap for '.$needle
            );
        }
    }
}
