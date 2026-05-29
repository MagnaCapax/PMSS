<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

class UpdateLibraryDependencyTest extends TestCase
{
    private function assertRepoFileDependencyCases(array $cases): void
    {
        foreach ($cases as $case) {
            $this->pmssAssertRepoFileContainsAllStrings($case[0], $case[1]);
            $this->pmssAssertRepoFileNotContainsStrings($case[0], $case[2], $case[3]);
        }
    }

    public function testDirectLibraryConsumersKeepLeanIncludes(): void
    {
        $this->assertRepoFileDependencyCases([
            ['scripts/util/configureOpenvpn.php', ["require_once __DIR__.'/../lib/update/runtime/commands.php';"], ["require_once __DIR__.'/../lib/update.php';", "require_once __DIR__.'/../lib/logger.php';"], 'configureOpenvpn.php should rely on runtime/commands.php as its logging/runtime bootstrap'],
            ['scripts/util/setupLetsEncrypt.php', ["require_once __DIR__.'/../lib/update/distro.php';"], ["require_once __DIR__.'/../lib/update.php';"], 'setupLetsEncrypt.php should not pull scripts/lib/update.php just for distro detection'],
            ['scripts/util/userConfig.php', ["require_once __DIR__.'/../lib/rtorrentConfig.php';", "require_once __DIR__.'/../lib/update/runtime/commands.php';"], ["require_once __DIR__.'/../lib/update.php';"], 'userConfig.php should rely on direct subsystem requires'],
            ['scripts/util/userDocker.php', ["require_once __DIR__.'/../lib/user/userConfigStore.php';", 'pmssUserDockerEnabled($user, $userConfigStore)', 'pmssUserDockerMinRamMiB()'], ["function_exists('pmssUserDockerEnabled')", "function_exists('pmssUserDockerMinRamMiB')"], 'userDocker.php should rely on userConfigStore.php for Docker policy helpers'],
            ['scripts/cron/checkRootlessDocker.php', ["require_once '/scripts/lib/user/userConfigStore.php';", 'pmssUserDockerEnabled($user, $userConfigStore)'], ["function_exists('pmssUserDockerEnabled')"], 'checkRootlessDocker.php should rely on userConfigStore.php for Docker policy checks'],
            ['scripts/lib/motd/Generator.php', ["require_once __DIR__.'/../update/distro.php';", "require_once __DIR__.'/../version.php';"], ["require_once __DIR__.'/../update.php';"], 'Motd generator should not pull scripts/lib/update.php just for distro/version metadata'],
            ['scripts/lib/update/distro.php', ["require_once __DIR__.'/../log.php';"], ["require_once __DIR__.'/runtime/commands.php';"], 'distro.php should not pull runtime/commands.php just to expose logmsg()'],
            ['scripts/lib/wireguard.php', ["require_once __DIR__.'/log.php';"], ["require_once __DIR__.'/logger.php';"], 'wireguard.php should load log.php directly when it only needs logmsg()'],
            ['scripts/lib/update/networking.php', ["require_once __DIR__.'/logging.php';"], ["require_once __DIR__.'/runtime/commands.php';"], 'networking.php should not pull runtime/commands.php when it only selects a logger'],
            ['scripts/lib/user/qbittorrent.php', ["require_once __DIR__.'/traffic.php';"], ["require_once __DIR__.'/../update/runtime/commands.php';"], 'qbittorrent.php should not bootstrap update runtime helpers it does not use'],
            ['scripts/util/configureOpenvpn.php', ["pmssLogStatus('SKIP', 'OpenVPN already configured; skipping provisioning', 0);"], ["function_exists('pmssLogStatus')"], 'configureOpenvpn.php should rely on runtime/commands.php for pmssLogStatus()'],
            ['scripts/util/userConfig.php', ["pmssLogStatus('SKIP', 'Rootless Docker disabled by config for '.\$user['name']);", "pmssUserDockerEnabled(\$user['name'], \$store)"], ["function_exists('pmssLogStatus')", "function_exists('pmssUserDockerEnabled')"], 'userConfig.php should rely on runtime/commands.php and userConfigStore.php for Docker policy helpers'],
            ['scripts/lib/update/environment.php', ["require_once __DIR__.'/dpkgSelections.php';"], ["apps/packages/helpers.php"], 'environment.php should use dpkg selection sanitizers instead of package queues'],
            ['scripts/lib/update/apps/iprange.php', ["require_once __DIR__.'/../packageState.php';"], ["packages/helpers.php"], 'iprange.php should use read-only package state helpers'],
            ['scripts/lib/user/UserValidator.php', ["require_once __DIR__.'/identity.php';"], ["require_once dirname(__DIR__).'/userLifecycle.php';"], 'UserValidator should only load username identity helpers'],
            ['scripts/lib/traffic/storage.php', ["require_once __DIR__.'/../user/identity.php';"], ["require_once __DIR__.'/../userLifecycle.php';"], 'traffic storage should only load username identity helpers'],
            ['scripts/lib/lighttpd/configRender.php', ["require_once __DIR__.'/../user/identity.php';"], ["require_once __DIR__.'/../userLifecycle.php';"], 'lighttpd rendering should only load username identity helpers'],
            ['scripts/lib/network/fireqos.php', ["require_once __DIR__.'/../user/identity.php';"], ["require_once __DIR__.'/../userLifecycle.php';"], 'FireQOS rendering should only load username identity helpers'],
        ]);
    }

    public function testRuntimeBootstrapsKeepOnlyIntentionalFallbackGuards(): void
    {
        $runtimeSource = $this->pmssReadRepoFile('scripts/lib/runtime.php');
        preg_match_all("/if \\(!function_exists\\('([^']+)'\\)\\) \\{/", $runtimeSource, $runtimeGuards);
        $this->assertSame(['pmssFormatBytes', 'pmssRequireCli'], $runtimeGuards[1], 'runtime.php should keep only compatibility guards that support customer/helper stubs');

        $this->assertRepoFileDependencyCases([
            ['scripts/lib/log.php', ["if (!function_exists('logmsg')) {", 'function pmssJsonEncodeSafe(array $payload, int $flags = 0): ?string', 'function pmssJsonLineAppend(string $path, array $payload): bool', 'function pmssLogWriteMessage(string $primary, string $fallback, string $message, bool $writeToStderr = false): void'], ["if (!function_exists('pmssJsonEncodeSafe')) {", "if (!function_exists('pmssJsonLineAppend')) {", "if (!function_exists('pmssJsonEmitPayload')) {", "if (!function_exists('pmssLogAppendTimestampedLine')) {", "if (!function_exists('pmssLogWriteMessage')) {"], 'log.php should keep only the intentional update.php compatibility guard'],
            ['scripts/lib/update/runtime/commands.php', ["if (!function_exists('runUserStep')) {"], ["if (!function_exists('runStep')) {", "if (!function_exists('aptCmd')) {", "if (!function_exists('pmssBuildCommand')) {", "if (!function_exists('pmssLogStatus')) {"], 'runtime/commands.php should rely on require_once for '],
            ['scripts/lib/update/logging.php', ["if (!function_exists('pmssCorrelationId')) {"], ["if (!function_exists('pmssJsonLogPath')) {", "if (!function_exists('pmssLogJson')) {"], 'logging.php should rely on require_once for '],
            ['scripts/lib/runtime.php', ["require_once __DIR__.'/update/logging.php';"], ["if (!function_exists('logMessage')) {"], 'runtime.php should rely on require_once for logMessage bootstrap'],
            ['scripts/util/update-step2.php', ["runStep('Restoring root cron configuration (shutdown)', \$helper);", "\$jsonPath = pmssJsonLogPath();", "\$pmssCorrelationId = pmssCorrelationId();"], ["function_exists('runStep')", "function_exists('pmssJsonLogPath')", "function_exists('pmssCorrelationId')"], 'update-step2.php should rely on the shared bootstrap for '],
            ['scripts/lib/user/userConfigStore.php', ['function pmssUserDockerMinRamMiB(): int', 'function pmssUserConfigResolvePayload(string $username, ?UserConfigStore &$store = null): ?array', 'function pmssUserDockerEnabled(string $username, ?UserConfigStore $store = null): bool', 'function pmssUserLighttpdEnabled(string $username, ?UserConfigStore $store = null): bool'], ["if (!function_exists('pmssUserDockerMinRamMiB')) {", "if (!function_exists('pmssUserConfigNormaliseToggleValue')) {", "if (!function_exists('pmssUserConfigResolvePayload')) {", "if (!function_exists('pmssUserDockerEnabled')) {", "if (!function_exists('pmssUserLighttpdEnabled')) {"], 'userConfigStore.php should define its policy helpers directly once it is required'],
        ]);
    }

    public function testSystemdServiceGuardUsesSharedProcessesLibraryViaServiceHelper(): void
    {
        $this->assertRepoFileDependencyCases([
            ['scripts/lib/update/services/systemd.php', ["require_once __DIR__.'/../runtime/processes.php';"], ['function_exists(\'pmssSystemdUnitExists\')'], 'services/systemd.php should rely on runtime/processes.php for pmssSystemdUnitExists()'],
            ['scripts/cron/systemdServicesGuard.php', ["require_once __DIR__.'/../lib/update/services/systemd.php';"], ["require_once __DIR__.'/../lib/update/runtime/processes.php';"], 'systemdServicesGuard.php should rely on services/systemd.php to bootstrap runtime/processes.php'],
        ]);
    }

    public function testUserLifecycleBootstrapsUserLoggerForLeanCallers(): void
    {
        $this->assertRepoFileDependencyCases([
            ['scripts/lib/user/log.php', ['function pmssUserLogAllowed(): bool', 'function pmssUserLogFile(string $user): string', 'function pmssUserLog(string $user, string $message): void'], ["if (!function_exists('pmssUserLogAllowed')) {", "if (!function_exists('pmssUserLogFile')) {", "if (!function_exists('pmssUserLog')) {"], 'user/log.php should define its logger functions directly once it is required'],
            ['scripts/lib/userLifecycle.php', ["require_once __DIR__.'/user/identity.php';", "require_once __DIR__.'/user/selection.php';", "require_once __DIR__.'/user/watchdog.php';"], ['function pmssUserWatchdogEnsureServices(', 'function pmssManagedUsersSelectFromCommand(', 'function pmssPasswdEntryLookup('], 'userLifecycle.php should compose focused user modules instead of owning their implementations'],
            ['scripts/lib/user/watchdog.php', ["require_once __DIR__.'/log.php';", "require_once __DIR__.'/selection.php';", 'function pmssUserWatchdogEnsureServices('], ["require_once dirname(__DIR__).'/userLifecycle.php';", "if (!function_exists('pmssUserLogAllowed')) {"], 'user watchdog helpers should be a focused module without reloading the lifecycle facade'],
            ['scripts/cron/checkRtorrent.php', ["require_once __DIR__.'/../lib/user/log.php';", "pmssUserLog(\$user, 'checkRtorrent: '.\$message);"], ["function_exists('pmssUserLog')"], 'checkRtorrent.php should log through the required user logger directly'],
            ['scripts/cron/checkDelugeInstances.php', ["require_once __DIR__.'/../lib/user/watchdog.php';", "pmssUserWatchdogRunService(", "'deluge stopped due to suspension'"], ['pmssUserLogPath', "\$canUserLog = function_exists('pmssUserLog');", "require_once __DIR__.'/../lib/userLifecycle.php';"], 'Deluge watchdog should use the focused watchdog module'],
            ['scripts/cron/checkLighttpdInstances.php', ["require_once __DIR__.'/../lib/user/watchdog.php';", "require_once __DIR__.'/../lib/lighttpd/watchdogErrorPage.php';", "pmssUserWatchdogHandleSuspended(", "pmssUserWatchdogServiceSpec('lighttpd'", "'lighttpd start requested'"], ['pmssUserLogPath', "\$canUserLog = function_exists('pmssUserLog');", "require_once __DIR__.'/../lib/userLifecycle.php';"], 'Lighttpd watchdog should use the focused watchdog module'],
            ['scripts/cron/checkQbittorrentInstances.php', ["require_once __DIR__.'/../lib/user/watchdog.php';", "pmssUserWatchdogRunService(", "'qbittorrent-nox start requested'"], ['pmssUserLogPath', "\$canUserLog = function_exists('pmssUserLog');", "require_once __DIR__.'/../lib/userLifecycle.php';"], 'qBittorrent watchdog should use the focused watchdog module'],
            ['scripts/cron/checkRcloneInstances.php', ["require_once __DIR__.'/../lib/user/watchdog.php';", "pmssUserWatchdogRunService(", "'rclone start requested'"], ['pmssUserLogPath', "\$canUserLog = function_exists('pmssUserLog');", "require_once __DIR__.'/../lib/userLifecycle.php';"], 'Rclone watchdog should use the focused watchdog module'],
            ['scripts/cron/trafficLog.php', ["require_once '/scripts/lib/user/log.php';"], ['pmssUserLogPath'], 'trafficLog.php should require the user logger directly instead of probing it'],
            ['scripts/cron/userTrackerCleaner.php', ["require_once __DIR__.'/../lib/userLifecycle.php';", 'pmssUserLog($thisUser, $summary);'], ['pmssUserLogPath', "function_exists('pmssUserLog')"], 'userTrackerCleaner.php should use userLifecycle.php as its user logger bootstrap'],
            ['scripts/util/createNginxConfig.php', ["require_once __DIR__.'/../lib/user/log.php';"], ['pmssUserLogPath'], 'createNginxConfig.php should require the user logger directly instead of probing it'],
            ['scripts/util/userPermissions.php', ["require_once __DIR__.'/../lib/userLifecycle.php';", "require_once __DIR__.'/../lib/shell.php';", "pmssUserLog(\$thisUser, \$binSpec[1]);"], ['pmssUserLogPath', "function_exists('pmssUserLog')"], 'userPermissions.php should use required shared libraries directly'],
            ['scripts/cron/updateQuotas.php', ["require_once '/scripts/lib/userLifecycle.php';", "if (\$mirrorUserLog) {"], ['pmssUserLogPath', "function_exists('pmssUserLog')"], 'updateQuotas.php should log through the required user logger directly'],
            ['scripts/util/checkUserHtpasswd.php', ["require_once __DIR__.'/../lib/userLifecycle.php';", "pmssUserLog(\$thisUser, 'htpasswd sync: appended legacy credential to per-user .htpasswd');"], ['pmssUserLogPath', "function_exists('pmssUserLog')"], 'checkUserHtpasswd.php should log through the required user logger directly'],
        ]);
    }
}
