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
state_file=${PMSS_TEST_PGREP_STATE:?}
state=$(cat "$state_file" 2>/dev/null || echo stopped)
[ "$state" = "running" ]
BASH,
            'pkill' => <<<'BASH'
#!/bin/bash
printf '%s
' "$*" >> "${PMSS_TEST_COMMAND_LOG:?}"
state_file=${PMSS_TEST_PGREP_STATE:?}
mode=$(cat "${PMSS_TEST_KILL_MODE:?}" 2>/dev/null || echo term)
case "${1:-}" in
  -TERM) [ "$mode" = "term" ] && printf 'stopped
' > "$state_file" ;;
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

    public function testKillProcessSkipsUnsafeProcessName(): void
    {
        @file_put_contents($this->tempDir.'/state', "running\n");

        \killProcess('demo name', 'Stopping demo process', null, 0);

        $this->assertEquals([], $this->pmssProfileCommands());
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

    public function testKillProcessSkipsUnsafeSystemdUnitButStillKillsProcess(): void
    {
        @file_put_contents($this->tempDir.'/state', "running\n");
        @file_put_contents($this->tempDir.'/kill-mode', "term\n");

        \killProcess('demo', 'Stopping demo process', 'demo service', 1);

        $this->assertEquals([
            "pkill -TERM -x 'demo'",
        ], $this->pmssProfileCommands());
    }

}
