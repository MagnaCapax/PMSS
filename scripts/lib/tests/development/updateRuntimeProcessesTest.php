<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/update/runtime/commands.php';
require_once dirname(__DIR__, 2).'/update/runtime/profile.php';
require_once dirname(__DIR__, 2).'/update/runtime/processes.php';

class UpdateRuntimeProcessesTest extends TestCase
{
    /** @var string */
    private $tempDir;

    /** @var string|false */
    private $previousPath;

    /** @var string|false */
    private $previousHome;

    protected function setUp(): void
    {
        $this->pmssAssignTempDirProperty('tempDir', 'pmss-update-runtime-processes');
        @mkdir($this->tempDir.'/bin', 0755, true);
        @file_put_contents($this->tempDir.'/state', "stopped\n");
        @file_put_contents($this->tempDir.'/commands.log', '');
        @file_put_contents($this->tempDir.'/kill-mode', "term\n");
        @file_put_contents($this->tempDir.'/.bash_profile', 'export PATH="'.$this->tempDir.'/bin:$PATH"'."\n");

        $this->writeExecutable('pgrep', <<<'BASH'
#!/bin/bash
state_file=${PMSS_TEST_PGREP_STATE:?}
state=$(cat "$state_file" 2>/dev/null || echo stopped)
[ "$state" = "running" ]
BASH
);
        $this->writeExecutable('pkill', <<<'BASH'
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
);

        $this->previousPath = getenv('PATH');
        $this->previousHome = getenv('HOME');
        putenv('PATH='.$this->tempDir.'/bin'.($this->previousPath !== false ? ':'.$this->previousPath : ''));
        putenv('HOME='.$this->tempDir);
        putenv('PMSS_TEST_PGREP_STATE='.$this->tempDir.'/state');
        putenv('PMSS_TEST_COMMAND_LOG='.$this->tempDir.'/commands.log');
        putenv('PMSS_TEST_KILL_MODE='.$this->tempDir.'/kill-mode');
        $this->pmssResetRuntimeProfile();
    }

    protected function tearDown(): void
    {
        $this->pmssResetRuntimeProfile();
        putenv('PMSS_TEST_PGREP_STATE');
        putenv('PMSS_TEST_COMMAND_LOG');
        putenv('PMSS_TEST_KILL_MODE');
        if ($this->previousPath === false) {
            putenv('PATH');
        } else {
            putenv('PATH='.$this->previousPath);
        }
        if ($this->previousHome === false) {
            putenv('HOME');
        } else {
            putenv('HOME='.$this->previousHome);
        }
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

    private function writeExecutable(string $name, string $content): void
    {
        $path = $this->tempDir.'/bin/'.$name;
        @file_put_contents($path, $content);
        @chmod($path, 0755);
    }
}
