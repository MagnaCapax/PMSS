<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

class UpdateLibraryDependencyTest extends TestCase
{
    private function assertRepoFileDependencyContract(string $path, array $required, array $forbidden = [], string $forbiddenMessage = ''): void
    {
        $contents = $this->pmssReadRepoFile($path);
        $this->assertStringContainsAllStrings($required, $contents);
        foreach ($forbidden as $needle) {
            $this->pmssAssertStringNotContainsString($needle, $contents, $forbiddenMessage);
        }
    }

    public function testDirectLibraryConsumersKeepLeanIncludes(): void
    {
        foreach ([
            ['scripts/util/configureOpenvpn.php', ["require_once __DIR__.'/../lib/logger.php';", "require_once __DIR__.'/../lib/update/runtime/commands.php';"], ["require_once __DIR__.'/../lib/update.php';"], 'configureOpenvpn.php should not pull scripts/lib/update.php just for runtime helpers'],
            ['scripts/util/setupLetsEncrypt.php', ["require_once __DIR__.'/../lib/update/distro.php';"], ["require_once __DIR__.'/../lib/update.php';"], 'setupLetsEncrypt.php should not pull scripts/lib/update.php just for distro detection'],
            ['scripts/util/userConfig.php', ["require_once __DIR__.'/../lib/rtorrentConfig.php';", "require_once __DIR__.'/../lib/update/runtime/commands.php';"], ["require_once __DIR__.'/../lib/update.php';"], 'userConfig.php should rely on direct subsystem requires'],
            ['scripts/lib/motd/Generator.php', ["require_once __DIR__.'/../update/distro.php';"], ["require_once __DIR__.'/../update.php';"], 'Motd generator should not pull scripts/lib/update.php just for distro detection'],
            ['scripts/lib/update/distro.php', ["require_once __DIR__.'/../log.php';"], ["require_once __DIR__.'/runtime/commands.php';"], 'distro.php should not pull runtime/commands.php just to expose logmsg()'],
            ['scripts/lib/wireguard.php', ["require_once __DIR__.'/log.php';"], ["require_once __DIR__.'/logger.php';"], 'wireguard.php should load log.php directly when it only needs logmsg()'],
            ['scripts/lib/update/networking.php', ["require_once __DIR__.'/logging.php';"], ["require_once __DIR__.'/runtime/commands.php';"], 'networking.php should not pull runtime/commands.php when it only selects a logger'],
            ['scripts/lib/user/qbittorrent.php', ["require_once __DIR__.'/traffic.php';"], ["require_once __DIR__.'/../update/runtime/commands.php';"], 'qbittorrent.php should not bootstrap update runtime helpers it does not use'],
            ['scripts/util/configureOpenvpn.php', ["pmssLogStatus('SKIP', 'OpenVPN already configured; skipping provisioning', 0);"], ["function_exists('pmssLogStatus')"], 'configureOpenvpn.php should rely on runtime/commands.php for pmssLogStatus()'],
            ['scripts/util/userConfig.php', ["pmssLogStatus('SKIP', 'Rootless Docker disabled by config for '.\$user['name']);"], ["function_exists('pmssLogStatus')"], 'userConfig.php should rely on runtime/commands.php for pmssLogStatus()'],
            ['scripts/lib/update/apps/packages/helpers.php', ['pmssLogJson(['], ["function_exists('pmssLogJson')"], 'packages/helpers.php should rely on runtime/commands.php for pmssLogJson()'],
        ] as $case) {
            $this->assertRepoFileDependencyContract($case[0], $case[1], $case[2], $case[3]);
        }
    }

    public function testRuntimeBootstrapsKeepOnlyIntentionalFallbackGuards(): void
    {
        foreach ([
            ['scripts/lib/update/runtime/commands.php', ["if (!function_exists('runUserStep')) {"], ["if (!function_exists('runStep')) {", "if (!function_exists('aptCmd')) {", "if (!function_exists('pmssBuildCommand')) {", "if (!function_exists('pmssLogStatus')) {"], 'runtime/commands.php should rely on require_once for '],
            ['scripts/lib/update/logging.php', ["if (!function_exists('pmssCorrelationId')) {"], ["if (!function_exists('pmssJsonLogPath')) {", "if (!function_exists('pmssLogJson')) {"], 'logging.php should rely on require_once for '],
            ['scripts/lib/runtime.php', ["require_once __DIR__.'/update/logging.php';"], ["if (!function_exists('logMessage')) {"], 'runtime.php should rely on require_once for logMessage bootstrap'],
            ['scripts/util/update-step2.php', ["runStep('Restoring root cron configuration (shutdown)', \$helper);", "\$jsonPath = pmssJsonLogPath();", "\$pmssCorrelationId = pmssCorrelationId();"], ["function_exists('runStep')", "function_exists('pmssJsonLogPath')", "function_exists('pmssCorrelationId')"], 'update-step2.php should rely on the shared bootstrap for '],
        ] as $case) {
            $this->assertRepoFileDependencyContract($case[0], $case[1], $case[2], $case[3]);
        }
    }

    public function testSystemdServiceGuardUsesSharedProcessesLibraryViaServiceHelper(): void
    {
        foreach ([
            ['scripts/lib/update/services/systemd.php', ["require_once __DIR__.'/../runtime/processes.php';"], ['function_exists(\'pmssSystemdUnitExists\')'], 'services/systemd.php should rely on runtime/processes.php for pmssSystemdUnitExists()'],
            ['scripts/cron/systemdServicesGuard.php', ["require_once __DIR__.'/../lib/update/services/systemd.php';"], ["require_once __DIR__.'/../lib/update/runtime/processes.php';"], 'systemdServicesGuard.php should rely on services/systemd.php to bootstrap runtime/processes.php'],
        ] as $case) {
            $this->assertRepoFileDependencyContract($case[0], $case[1], $case[2], $case[3]);
        }
    }

    public function testUserLifecycleBootstrapsUserLoggerForLeanCallers(): void
    {
        foreach ([
            ['scripts/lib/user/log.php', ['function pmssUserLogAllowed(): bool', 'function pmssUserLogFile(string $user): string', 'function pmssUserLog(string $user, string $message): void'], ["if (!function_exists('pmssUserLogAllowed')) {", "if (!function_exists('pmssUserLogFile')) {", "if (!function_exists('pmssUserLog')) {"], 'user/log.php should define its logger functions directly once it is required'],
            ['scripts/lib/userLifecycle.php', ["require_once __DIR__.'/user/log.php';", 'function pmssUserWatchdogEnsureServices('], ["if (!function_exists('pmssUserLogAllowed')) {"], 'userLifecycle.php should rely on user/log.php for pmssUserLogAllowed()'],
            ['scripts/cron/checkRtorrent.php', ["require_once __DIR__.'/../lib/user/log.php';", "pmssUserLog(\$user, 'checkRtorrent: '.\$message);"], ["function_exists('pmssUserLog')"], 'checkRtorrent.php should log through the required user logger directly'],
            ['scripts/cron/checkDelugeInstances.php', ["require_once __DIR__.'/../lib/userLifecycle.php';", "pmssUserWatchdogRunService(", "'deluge stopped due to suspension'"], ['pmssUserLogPath', "\$canUserLog = function_exists('pmssUserLog');"], 'Deluge watchdog should use userLifecycle.php as its user logger bootstrap'],
            ['scripts/cron/checkLighttpdInstances.php', ["require_once __DIR__.'/../lib/userLifecycle.php';", "require_once __DIR__.'/../lib/lighttpd/watchdogErrorPage.php';", "pmssUserWatchdogHandleSuspended(", "pmssUserWatchdogEnsureServices(\$thisUser, [[", "'lighttpd start requested'"], ['pmssUserLogPath', "\$canUserLog = function_exists('pmssUserLog');"], 'Lighttpd watchdog should use userLifecycle.php as its user logger bootstrap'],
            ['scripts/cron/checkQbittorrentInstances.php', ["require_once __DIR__.'/../lib/userLifecycle.php';", "pmssUserWatchdogRunService(", "'qbittorrent-nox start requested'"], ['pmssUserLogPath', "\$canUserLog = function_exists('pmssUserLog');"], 'qBittorrent watchdog should use userLifecycle.php as its user logger bootstrap'],
            ['scripts/cron/checkRcloneInstances.php', ["require_once __DIR__.'/../lib/userLifecycle.php';", "pmssUserWatchdogRunService(", "'rclone start requested'"], ['pmssUserLogPath', "\$canUserLog = function_exists('pmssUserLog');"], 'Rclone watchdog should use userLifecycle.php as its user logger bootstrap'],
            ['scripts/cron/updateQuotas.php', ["require_once '/scripts/lib/userLifecycle.php';", "if (\$mirrorUserLog) {"], ['pmssUserLogPath', "function_exists('pmssUserLog')"], 'updateQuotas.php should log through the required user logger directly'],
            ['scripts/util/checkUserHtpasswd.php', ["require_once __DIR__.'/../lib/userLifecycle.php';", "pmssUserLog(\$thisUser, 'htpasswd sync: appended legacy credential to per-user .htpasswd');"], ['pmssUserLogPath', "function_exists('pmssUserLog')"], 'checkUserHtpasswd.php should log through the required user logger directly'],
        ] as $case) {
            $this->assertRepoFileDependencyContract($case[0], $case[1], $case[2], $case[3]);
        }
    }
}
