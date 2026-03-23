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
        $path = 'scripts/util/configureOpenvpn.php';
        $this->pmssAssertRepoFileContainsAllStrings($path, ["require_once __DIR__.'/../lib/logger.php';", "require_once __DIR__.'/../lib/update/runtime/commands.php';"]);
        $this->pmssAssertRepoFileNotContainsString($path, "require_once __DIR__.'/../lib/update.php';", 'configureOpenvpn.php should not pull scripts/lib/update.php just for runtime helpers');
    }

    public function testSetupLetsEncryptUsesDirectDistroLibrary(): void
    {
        $path = 'scripts/util/setupLetsEncrypt.php';
        $this->pmssAssertRepoFileContainsString($path, "require_once __DIR__.'/../lib/update/distro.php';");
        $this->pmssAssertRepoFileNotContainsString($path, "require_once __DIR__.'/../lib/update.php';", 'setupLetsEncrypt.php should not pull scripts/lib/update.php just for distro detection');
    }

    public function testUserConfigUsesDirectSubsystemLibraries(): void
    {
        $path = 'scripts/util/userConfig.php';
        $this->pmssAssertRepoFileContainsAllStrings($path, ["require_once __DIR__.'/../lib/rtorrentConfig.php';", "require_once __DIR__.'/../lib/update/runtime/commands.php';"]);
        $this->pmssAssertRepoFileNotContainsString($path, "require_once __DIR__.'/../lib/update.php';", 'userConfig.php should rely on direct subsystem requires');
    }

    public function testMotdGeneratorUsesDirectDistroLibrary(): void
    {
        $path = 'scripts/lib/motd/Generator.php';
        $this->pmssAssertRepoFileContainsString($path, "require_once __DIR__.'/../update/distro.php';");
        $this->pmssAssertRepoFileNotContainsString($path, "require_once __DIR__.'/../update.php';", 'Motd generator should not pull scripts/lib/update.php just for distro detection');
    }

    public function testDistroLibraryUsesDirectLegacyLogLibrary(): void
    {
        $path = 'scripts/lib/update/distro.php';
        $this->pmssAssertRepoFileContainsString($path, "require_once __DIR__.'/../log.php';");
        $this->pmssAssertRepoFileNotContainsString($path, "require_once __DIR__.'/runtime/commands.php';", 'distro.php should not pull runtime/commands.php just to expose logmsg()');
    }

    public function testWireguardLibraryUsesDirectLegacyLogLibrary(): void
    {
        $path = 'scripts/lib/wireguard.php';
        $this->pmssAssertRepoFileContainsString($path, "require_once __DIR__.'/log.php';");
        $this->pmssAssertRepoFileNotContainsString($path, "require_once __DIR__.'/logger.php';", 'wireguard.php should load log.php directly when it only needs logmsg()');
    }

    public function testNetworkingLibraryAvoidsRuntimeCommandsBootstrap(): void
    {
        $path = 'scripts/lib/update/networking.php';
        $this->pmssAssertRepoFileContainsString($path, "require_once __DIR__.'/logging.php';");
        $this->pmssAssertRepoFileNotContainsString($path, "require_once __DIR__.'/runtime/commands.php';", 'networking.php should not pull runtime/commands.php when it only selects a logger');
    }

    public function testQbittorrentLibraryAvoidsUpdateRuntimeBootstrap(): void
    {
        $path = 'scripts/lib/user/qbittorrent.php';
        $this->pmssAssertRepoFileContainsString($path, "require_once __DIR__.'/traffic.php';");
        $this->pmssAssertRepoFileNotContainsString($path, "require_once __DIR__.'/../update/runtime/commands.php';", 'qbittorrent.php should not bootstrap update runtime helpers it does not use');
    }

    public function testUpdateRuntimeCommandsKeepsOnlyRunUserStepOverrideGuard(): void
    {
        $path = 'scripts/lib/update/runtime/commands.php';
        $this->pmssAssertRepoFileContainsString($path, "if (!function_exists('runUserStep')) {");
        foreach (['runStep', 'aptCmd', 'pmssBuildCommand', 'pmssLogStatus'] as $functionName) {
            $this->pmssAssertRepoFileNotContainsString($path, "if (!function_exists('".$functionName."')) {", 'runtime/commands.php should rely on require_once for '.$functionName);
        }
    }

    public function testUpdateLoggingUsesDirectJsonHelpers(): void
    {
        $path = 'scripts/lib/update/logging.php';
        $this->pmssAssertRepoFileContainsString($path, "if (!function_exists('pmssCorrelationId')) {");
        foreach (['pmssJsonLogPath', 'pmssLogJson'] as $functionName) {
            $this->pmssAssertRepoFileNotContainsString($path, "if (!function_exists('".$functionName."')) {", 'logging.php should rely on require_once for '.$functionName);
        }
    }

    public function testSystemdServiceGuardUsesSharedProcessesLibraryViaServiceHelper(): void
    {
        $servicePath = 'scripts/lib/update/services/systemd.php';
        $cronPath = 'scripts/cron/systemdServicesGuard.php';
        $this->pmssAssertRepoFileContainsString($servicePath, "require_once __DIR__.'/../runtime/processes.php';");
        $this->pmssAssertRepoFileNotContainsString($servicePath, 'function_exists(\'pmssSystemdUnitExists\')', 'services/systemd.php should rely on runtime/processes.php for pmssSystemdUnitExists()');
        $this->pmssAssertRepoFileContainsString($cronPath, "require_once __DIR__.'/../lib/update/services/systemd.php';");
        $this->pmssAssertRepoFileNotContainsString($cronPath, "require_once __DIR__.'/../lib/update/runtime/processes.php';", 'systemdServicesGuard.php should rely on services/systemd.php to bootstrap runtime/processes.php');
    }

    public function testConfigureOpenvpnUsesDirectPmssLogStatus(): void
    {
        $path = 'scripts/util/configureOpenvpn.php';
        $this->pmssAssertRepoFileContainsString($path, "pmssLogStatus('SKIP', 'OpenVPN already configured; skipping provisioning', 0);");
        $this->pmssAssertRepoFileNotContainsString($path, "function_exists('pmssLogStatus')", 'configureOpenvpn.php should rely on runtime/commands.php for pmssLogStatus()');
    }

    public function testUserConfigUsesDirectPmssLogStatus(): void
    {
        $path = 'scripts/util/userConfig.php';
        $this->pmssAssertRepoFileContainsString($path, "pmssLogStatus('SKIP', 'Rootless Docker disabled by config for '.\$user['name']);");
        $this->pmssAssertRepoFileNotContainsString($path, "function_exists('pmssLogStatus')", 'userConfig.php should rely on runtime/commands.php for pmssLogStatus()');
    }

    public function testPackageHelpersUseDirectPmssLogJson(): void
    {
        $path = 'scripts/lib/update/apps/packages/helpers.php';
        $this->pmssAssertRepoFileContainsString($path, 'pmssLogJson([');
        $this->pmssAssertRepoFileNotContainsString($path, "function_exists('pmssLogJson')", 'packages/helpers.php should rely on runtime/commands.php for pmssLogJson()');
    }

    public function testUpdateStep2UsesBootstrappedRuntimeHelpersDirectly(): void
    {
        $path = 'scripts/util/update-step2.php';
        $this->pmssAssertRepoFileContainsAllStrings(
            $path,
            [
                "runStep('Restoring root cron configuration (shutdown)', \$helper);",
                "\$jsonPath = pmssJsonLogPath();",
                "\$pmssCorrelationId = pmssCorrelationId();",
            ]
        );
        foreach (["function_exists('runStep')", "function_exists('pmssJsonLogPath')", "function_exists('pmssCorrelationId')"] as $needle) {
            $this->pmssAssertRepoFileNotContainsString($path, $needle, 'update-step2.php should rely on the shared bootstrap for '.$needle);
        }
    }
}
