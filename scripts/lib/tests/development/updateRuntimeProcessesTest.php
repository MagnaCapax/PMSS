<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/update/runtime/processes.php';

class UpdateRuntimeProcessesTest extends TestCase
{
    protected function setUp(): void
    {
        $this->pmssAssignTempDirProperty('tempDir', 'pmss-update-runtime-processes');
        @file_put_contents($this->tempDir.'/state', "stopped\n");
        @file_put_contents($this->tempDir.'/commands.log', '');
        @file_put_contents($this->tempDir.'/kill-mode', "term\n");
        @file_put_contents($this->tempDir.'/.bash_profile', 'export PATH="'.$this->tempDir.'/bin:$PATH"'."\n");

        $this->pmssWriteExecutableFiles($this->tempDir.'/bin', [
            'pgrep' => <<<'BASH'
#!/bin/bash
printf '%s\n' "$*" >> "${PMSS_TEST_PROBE_LOG:?}"
if [ -n "${PMSS_TEST_PROCESS_TABLE:-}" ]; then
  uid=
  [ "${3:-}" = -u ] && uid=$4
  awk -v name="$2" -v uid="$uid" '$2 == name && (uid == "" || $1 == uid) { print $3; found=1 } END { exit !found }' "$PMSS_TEST_PROCESS_TABLE"
  exit $?
fi
state_file=${PMSS_TEST_PGREP_STATE:?}
state=$(cat "$state_file" 2>/dev/null || echo stopped)
[ "$state" = "error" ] && exit 2
[ "$state" = "running" ]
BASH,
            'pkill' => <<<'BASH'
#!/bin/bash
printf '%s
' "$*" >> "${PMSS_TEST_COMMAND_LOG:?}"
if [ -n "${PMSS_TEST_PROCESS_TABLE:-}" ]; then
  uid=
  [ "${4:-}" = -u ] && uid=$5
  awk -v name="$3" -v uid="$uid" '$2 != name || (uid != "" && $1 != uid)' "$PMSS_TEST_PROCESS_TABLE" > "$PMSS_TEST_PROCESS_TABLE.next"
  mv "$PMSS_TEST_PROCESS_TABLE.next" "$PMSS_TEST_PROCESS_TABLE"
  exit 0
fi
state_file=${PMSS_TEST_PGREP_STATE:?}
mode=$(cat "${PMSS_TEST_KILL_MODE:?}" 2>/dev/null || echo term)
case "${1:-}" in
  -TERM)
    [ "$mode" = "term" ] && printf '%s\n' stopped > "$state_file"
    [ "$mode" = "error" ] && printf '%s\n' error > "$state_file"
    ;;
  -KILL) printf 'stopped
' > "$state_file" ;;
esac
BASH
        ]);

        $path = getenv('PATH');
        $this->pmssTrackEnvOverrides([
            'PATH' => $this->tempDir.'/bin'.($path !== false ? ':'.$path : ''),
            'HOME' => $this->tempDir,
            'PMSS_TEST_PGREP_STATE' => $this->tempDir.'/state',
            'PMSS_TEST_COMMAND_LOG' => $this->tempDir.'/commands.log',
            'PMSS_TEST_PROBE_LOG' => $this->tempDir.'/probes.log',
            'PMSS_TEST_PROCESS_TABLE' => '',
            'PMSS_TEST_KILL_MODE' => $this->tempDir.'/kill-mode',
        ]);
        $this->pmssResetRuntimeProfile();
    }

    protected function tearDown(): void
    {
        $this->pmssResetRuntimeProfile();
    }

    public function testKillProcessSkipsWhenProcessMissing(): void
    {
        \killProcess('demo', 'Stopping demo process', null, 0);

        $this->assertEquals([], $this->pmssProfileCommands());
    }

    public function testKillProcessSkipsWhenInitialProbeFails(): void
    {
        @file_put_contents($this->tempDir.'/state', "error\n");

        \killProcess('demo', 'Stopping demo process', null, 0);

        $this->assertEquals([], $this->pmssProfileCommands());
        $this->assertSame('', file_get_contents($this->tempDir.'/commands.log'));
    }

    public function testKillProcessSkipsUnsafeProcessName(): void
    {
        @file_put_contents($this->tempDir.'/state', "running\n");

        foreach (['demo name', "\0demo", "demo\0", "de\0mo"] as $name) {
            \killProcess($name, 'Stopping demo process', null, 0);
        }

        $this->assertEquals([], $this->pmssProfileCommands());
        $this->assertSame("running\n", file_get_contents($this->tempDir.'/state'));
    }

    public function testKillProcessRetainsWhitespaceNormalization(): void
    {
        @file_put_contents($this->tempDir.'/state', "running\n");

        \killProcess(" demo \n", 'Stopping demo process', null, 0);

        $this->assertEquals(["pkill -TERM -x 'demo'"], $this->pmssProfileCommands());
    }

    public function testKillProcessSkipsWhenProcessToolingIsUnavailable(): void
    {
        $this->pmssTrackEnvOverrides(['PATH' => $this->tempDir.'/bin']);

        foreach (['pgrep', 'pkill'] as $missingTool) {
            $toolPath = $this->tempDir.'/bin/'.$missingTool;
            $backupPath = $toolPath.'.disabled';
            $this->assertTrue(rename($toolPath, $backupPath), 'Failed to disable '.$missingTool.' fixture');
            @file_put_contents($this->tempDir.'/state', "running\n");
            $this->pmssResetRuntimeProfile();

            try {
                \killProcess('demo', 'Stopping demo process', null, 0);
            } finally {
                $this->assertTrue(rename($backupPath, $toolPath), 'Failed to restore '.$missingTool.' fixture');
            }

            $this->assertEquals([], $this->pmssProfileCommands(), $missingTool.' absence should skip kill commands');
        }
    }

    public function testSystemdUnitActionNameAllowsKnownUnitActions(): void
    {
        foreach (['enable', 'disable', 'start', 'stop', 'restart', 'reload', 'mask', 'unmask', 'try-restart'] as $action) {
            $this->assertTrue(\pmssSystemdUnitActionNameIsSafe($action), $action.' should be accepted');
        }
    }

    public function testSystemdUnitActionNameRejectsShellAndNonUnitActions(): void
    {
        foreach (['', 'enable --now', "restart\nstop", 'restart; rm -rf /', 'reboot', '-H'] as $action) {
            $this->assertFalse(\pmssSystemdUnitActionNameIsSafe($action), var_export($action, true).' should be rejected');
        }
    }

    public function testSystemdUnitActionSkipsUnsafeActionBeforeCommandBuild(): void
    {
        \pmssSystemdUnitActionIfPresent('demo.service', 'Restarting demo service', 'restart; rm -rf /');

        $this->assertEquals([], $this->pmssProfileCommands());
    }

    public function testKillProcessStopsAfterSigtermWhenProcessExits(): void
    {
        @file_put_contents($this->tempDir.'/state', "running\n");
        @file_put_contents($this->tempDir.'/kill-mode', "term\n");

        \killProcess('demo', 'Stopping demo process', null, 1);

        $this->assertEquals([
            "pkill -TERM -x 'demo'",
        ], $this->pmssProfileCommands());
    }

    public function testKillProcessEscalatesToSigkillWhenProcessSurvivesSigterm(): void
    {
        @file_put_contents($this->tempDir.'/state', "running\n");
        @file_put_contents($this->tempDir.'/kill-mode', "kill\n");

        \killProcess('demo', 'Stopping demo process', null, 0);

        $this->assertEquals([
            "pkill -TERM -x 'demo'",
            "pkill -KILL -x 'demo'",
        ], $this->pmssProfileCommands());
    }

    public function testKillProcessDoesNotEscalateWhenPostTermProbeFails(): void
    {
        @file_put_contents($this->tempDir.'/state', "running\n");
        @file_put_contents($this->tempDir.'/kill-mode', "error\n");

        \killProcess('demo', 'Stopping demo process', null, 0);

        $this->assertEquals(["pkill -TERM -x 'demo'"], $this->pmssProfileCommands());
        $this->assertSame("error\n", file_get_contents($this->tempDir.'/state'));
    }

    public function testKillProcessSkipsUnsafeSystemdUnitButStillKillsProcess(): void
    {
        @file_put_contents($this->tempDir.'/state', "running\n");
        @file_put_contents($this->tempDir.'/kill-mode', "term\n");

        \killProcess('demo', 'Stopping demo process', 'demo service', 1);

        $this->assertEquals([
            "pkill -TERM -x 'demo'",
        ], $this->pmssProfileCommands());
    }

    public function testKillProcessScopesEveryProbeAndSignalIncludingEscalation(): void
    {
        foreach ([0, 33] as $uid) {
            foreach (['term', 'kill', 'error'] as $mode) {
                file_put_contents($this->tempDir.'/state', "running\n");
                file_put_contents($this->tempDir.'/kill-mode', $mode."\n");
                file_put_contents($this->tempDir.'/probes.log', '');
                $this->pmssResetRuntimeProfile();
                \killProcess('demo', 'Stopping system process', null, 0, $uid);

                $expected = ["pkill -TERM -x 'demo' -u '$uid'"];
                if ($mode === 'kill') {
                    $expected[] = "pkill -KILL -x 'demo' -u '$uid'";
                }
                $this->assertSame($expected, $this->pmssProfileCommands());
                $probes = array_unique(explode("\n", trim(file_get_contents($this->tempDir.'/probes.log'))));
                $this->assertSame(['-x demo -u '.$uid], array_values($probes));
            }
        }
    }

    public function testKillProcessRejectsNegativeUidWithoutProbingOrSignalling(): void
    {
        \killProcess('demo', 'Stopping system process', null, 0, -1);

        $this->assertSame([], $this->pmssProfileCommands());
        $this->assertFalse(file_exists($this->tempDir.'/probes.log'));
        $this->assertSame('', file_get_contents($this->tempDir.'/commands.log'));
    }

    public function testSystemWebCleanupPreservesTenantProcessIdsAndUnrelatedBinaries(): void
    {
        // The process-table shims model simultaneous system and tenant daemons.
        $table = $this->tempDir.'/processes';
        $survivors = "1001 lighttpd 501\n1001 php-cgi 502\n1002 lighttpd 503\n1002 php-cgi 504\n0 other 505\n33 other 506\n";
        file_put_contents($table, "0 lighttpd 401\n0 php-cgi 402\n33 lighttpd 403\n33 php-cgi 404\n".$survivors);
        $this->pmssTrackEnvOverrides(['PMSS_TEST_PROCESS_TABLE' => $table]);

        foreach ([0, 33] as $uid) {
            foreach (['lighttpd', 'php-cgi'] as $name) {
                \killProcess($name, 'Stopping system web process', null, 0, $uid);
            }
        }
        $this->assertSame($survivors, file_get_contents($table));
        $this->assertSame(4, count($this->pmssProfileCommands()));

        // Only tenant matches remain: repeating cleanup must not signal anything.
        $this->pmssResetRuntimeProfile();
        foreach (['lighttpd', 'php-cgi'] as $name) {
            \killProcess($name, 'Stopping absent system web process', null, 0, 33);
        }
        $this->assertSame([], $this->pmssProfileCommands());
        $this->assertSame($survivors, file_get_contents($table));
    }

}
