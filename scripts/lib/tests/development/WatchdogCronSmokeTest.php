<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

/** Smoke tests for cron watchdog entrypoints that must not fatal at runtime. */
class WatchdogCronSmokeTest extends TestCase
{
    private $homeRoot = '';
    private $binDir = '';
    private $commandLog = '';
    private $listUsersScript = '';
    private $socketServers = array();

    protected function tearDown(): void
    {
        foreach ($this->socketServers as $server) {
            if (is_resource($server)) {
                fclose($server);
            }
        }
        $this->socketServers = array();
    }

    public function testCoreWatchdogCronsExitCleanlyForFixtureUser(): void
    {
        $this->stageWatchdogFixture();

        foreach (array(
            'scripts/cron/checkLighttpdInstances.php',
            'scripts/cron/checkDelugeInstances.php',
            'scripts/cron/checkQbittorrentInstances.php',
        ) as $entrypoint) {
            $result = $this->pmssRunRepoPhpScriptCommand($entrypoint, array(), $this->watchdogEnvironment());

            $this->assertSame(0, $result['rc'], $entrypoint.' failed: '.$result['output']);
            $this->assertStringNotContainsString('Fatal error', $result['output'], $entrypoint.' emitted a PHP fatal');
            $this->assertStringNotContainsString('Uncaught', $result['output'], $entrypoint.' emitted an uncaught throwable');
        }

        $commandLog = (string) @file_get_contents($this->commandLog);
        $this->assertSame('', $commandLog, 'watchdog smoke test should not start or kill services: '.$commandLog);
    }

    private function stageWatchdogFixture(): void
    {
        $this->homeRoot = $this->pmssMakeTempDir('watchdog-cron-home-');
        $this->binDir = $this->pmssMakeTempDir('watchdog-cron-bin-');
        $this->commandLog = $this->pmssMakeTempPath('watchdog-cron-command-', '.log');
        $this->listUsersScript = $this->pmssMakeTempDir('watchdog-cron-list-users-').'/listUsers.php';

        $home = $this->homeRoot.'/alice';
        $this->pmssWriteFile($home.'/.lighttpd.conf', '"max-procs" => 0'."\n");
        $this->pmssEnsureDir($home.'/.lighttpd');
        $this->pmssEnsureDir($home.'/www');
        $this->pmssWriteFile($home.'/.delugeEnable', '');
        $this->pmssWriteFile($home.'/.qbittorrentEnable', '');
        $this->pmssWriteExecutablePhpFile($this->listUsersScript, "echo \"alice\\n\";");
        $this->pmssWriteExecutableFiles($this->binDir, [
            'pgrep' => "#!/bin/sh\nprintf '99999999\\n'\n",
            'su' => "#!/bin/sh\nprintf 'su %s\\n' \"$*\" >> ".escapeshellarg($this->commandLog)."\n",
            'killall' => "#!/bin/sh\nprintf 'killall %s\\n' \"$*\" >> ".escapeshellarg($this->commandLog)."\n",
        ]);

        $socketPath = $home.'/.lighttpd/php.socket-0';
        $server = @stream_socket_server('unix://'.$socketPath, $errno, $errstr);
        if (!is_resource($server)) {
            throw new SkipTest('unix socket fixture unavailable: '.$errstr.' ('.$errno.')');
        }
        $this->socketServers[] = $server;
    }

    /** @return array<string,string> */
    private function watchdogEnvironment(): array
    {
        return array(
            'PATH' => $this->binDir.':'.getenv('PATH'),
            'PMSS_HOME_DIR' => $this->homeRoot,
            'PMSS_LIGHTTPD_WATCHDOG_WEB_ROOT' => $this->pmssMakeTempDir('watchdog-cron-web-'),
            'PMSS_TEST_LIST_USERS_COMMAND' => $this->listUsersScript,
            'PMSS_TEST_MODE' => '1',
        );
    }
}
