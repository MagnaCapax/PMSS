<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

final class QbittorrentWatchdogPortReconcileTest extends TestCase
{
    public function testWatchdogLoadsAndRunsWebUiPortReconcilerBeforeRestartDecisions(): void
    {
        $this->pmssAssertRepoFileContainsOrderedStrings('scripts/cron/checkQbittorrentInstances.php', [
            "require_once __DIR__.'/../lib/user/watchdog.php';",
            "require_once __DIR__.'/../lib/user/torrentPort.php';",
            "require_once __DIR__.'/../lib/lighttpd/dockerClientProxy.php';",
            // A labelled Docker qBittorrent counts as live before any native config or start.
            "if (pmssLighttpdDockerClientPort(\$thisUser, 'qbittorrent') !== null) return ['qbittorrent-nox' => true];",
            "\$running = pmssTorrentClientLauncherLivenessPids(\$thisUser, 'qbittorrent') !== [];",
            "\$home = rtrim(pmssResolvePathFromEnv('PMSS_HOME_DIR', '/home'), '/').'/'.\$thisUser;",
            "if (pmssQbittorrentPortEnsure(\$thisUser, \$home)) {",
            "if (pmssQbittorrentWebUiAuthEnsure(\$thisUser, \$home)) {",
            'pmssUserWatchdogApplyManagedConfigWhenStopped(',
            'pmssUserWatchdogRestartProcessesIf(',
        ]);
        $this->pmssAssertRepoFileMatches(
            'scripts/cron/checkQbittorrentInstances.php',
            '/pmssQbittorrentPortEnsure\(\$thisUser, \$home\)\) \{\s+\$running = pmssTorrentClientLauncherLivenessPids\(\$thisUser, \'qbittorrent\'\) !== \[\];\s+\}\s+if \(pmssQbittorrentWebUiAuthEnsure\(\$thisUser, \$home\)\) \{\s+\$running = false;\s+\}\s+pmssUserWatchdogApplyManagedConfigWhenStopped\(/'
        );

        $this->pmssAssertRepoFileContainsString(
            'scripts/cron/checkQbittorrentInstances.php',
            "rtrim(pmssResolvePathFromEnv('PMSS_HOME_DIR', '/home'), '/').'/'.\$thisUser"
        );
    }
}
