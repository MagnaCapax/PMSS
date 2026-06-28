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
            "\$running = pmssUserWatchdogProcessRunning(\$thisUser, 'qbittorrent-nox');",
            "\$home = rtrim(pmssResolvePathFromEnv('PMSS_HOME_DIR', '/home'), '/').'/'.\$thisUser;",
            "if (pmssQbittorrentPortEnsure(\$thisUser, \$home)) {",
            'pmssUserWatchdogApplyManagedConfigWhenStopped(',
            'pmssUserWatchdogRestartProcessesIf(',
        ]);
        $this->pmssAssertRepoFileMatches(
            'scripts/cron/checkQbittorrentInstances.php',
            '/pmssQbittorrentPortEnsure\(\$thisUser, \$home\)\) \{\s+\$running = pmssUserWatchdogProcessRunning\(\$thisUser, \'qbittorrent-nox\'\);\s+\}\s+pmssUserWatchdogApplyManagedConfigWhenStopped\(/'
        );

        $this->pmssAssertRepoFileContainsString(
            'scripts/cron/checkQbittorrentInstances.php',
            "rtrim(pmssResolvePathFromEnv('PMSS_HOME_DIR', '/home'), '/').'/'.\$thisUser"
        );
    }
}
