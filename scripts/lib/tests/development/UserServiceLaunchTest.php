<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/user/serviceLaunch.php';

class UserServiceLaunchTest extends TestCase
{
    protected function setUp(): void
    {
        $this->pmssAssignTempDirProperty('tempDir', 'pmss-user-service-launch-');
    }

    public function testScopedLaunchContainsProcessStormAtUserSliceTasksMax(): void
    {
        $fakeCgroup = $this->tempDir.'/fake-cgroup';
        $sliceDir = $fakeCgroup.'/pids/user.slice/user-1001.slice';
        $hostDir = $fakeCgroup.'/pids/system.slice/cron.service';
        $this->pmssWriteFile($sliceDir.'/pids.max', "4\n");
        $this->pmssWriteFile($sliceDir.'/pids.current', "0\n");
        $this->pmssWriteFile($hostDir.'/pids.max', "100\n");
        $this->pmssWriteFile($hostDir.'/pids.current', "0\n");

        $binDir = $this->pmssWriteExecutableFiles($this->tempDir.'/bin', $this->fakeLaunchBinaries());
        $this->pmssTrackEnvOverrides([
            'PATH' => $binDir.':'.((string) getenv('PATH')),
            'PMSS_FAKE_CGROUP_ROOT' => $fakeCgroup,
            'PMSS_FAKE_SYSTEMD_RUN_LOG' => $this->tempDir.'/systemd-run.log',
            'PMSS_FAKE_SYSTEMCTL_LOG' => $this->tempDir.'/systemctl.log',
        ]);

        $command = \pmssBuildUserServiceShellCommand('pmssdemo', 'storm 12');
        $result = $this->pmssExecShellCommand($command);

        $this->assertSame(73, $result['rc'], $result['output']);
        $this->assertSame("4\n", $this->pmssReadFileOrEmpty($sliceDir.'/pids.current'));
        $this->assertSame("0\n", $this->pmssReadFileOrEmpty($hostDir.'/pids.current'));
        $this->assertStringContainsAllStrings([
            'arg=--scope',
            'arg=--slice=user-1001.slice',
            'arg=su',
        ], $this->pmssReadFileOrEmpty($this->tempDir.'/systemd-run.log'));
        $this->assertStringContainsString('start user-1001.slice', $this->pmssReadFileOrEmpty($this->tempDir.'/systemctl.log'));
        $this->assertStringContainsString(
            'fork denied in /pids/user.slice/user-1001.slice/process-storm.scope current=4 max=4',
            $this->pmssReadFileOrEmpty($fakeCgroup.'/result.log')
        );
        $this->assertStringContainsString(
            'cgroup=/pids/user.slice/user-1001.slice/process-storm.scope',
            $this->pmssReadFileOrEmpty($fakeCgroup.'/pids.log')
        );
    }

    public function testLoginShellLaunchPreservesLegacySuDashShapeInsideScope(): void
    {
        $binDir = $this->pmssWriteExecutableFiles($this->tempDir.'/bin', [
            'id' => "#!/bin/sh\n[ \"\$1\" = '-u' ] && [ \"\$2\" = 'alice' ] && echo 2002 && exit 0\nexit 1\n",
        ]);
        $this->pmssTrackEnvOverrides(['PATH' => $binDir.':'.((string) getenv('PATH'))]);

        $command = \pmssBuildUserServiceShellCommand('alice', 'screen -S rtorrent -fa -d -m rtorrent', true);

        $this->assertStringContainsAllStrings([
            "systemctl 'start' 'user-2002.slice'",
            "systemd-run '--scope' '--collect' '--quiet' '--slice=user-2002.slice' '--' 'su' '-' 'alice' '-c'",
            "'screen -S rtorrent -fa -d -m rtorrent'",
        ], $command);
    }

    /** @return array<string,string> */
    private function fakeLaunchBinaries(): array
    {
        return [
            'id' => <<<'SH'
#!/bin/sh
if [ "$1" = "-u" ] && [ "$2" = "pmssdemo" ]; then
    printf '%s\n' 1001
    exit 0
fi
exit 1
SH
            ,
            'systemctl' => <<<'SH'
#!/bin/sh
printf '%s\n' "$*" >> "${PMSS_FAKE_SYSTEMCTL_LOG:?}"
exit 0
SH
            ,
            'systemd-run' => <<<'SH'
#!/bin/sh
slice=
scope=0
while [ "$#" -gt 0 ]; do
    printf 'arg=%s\n' "$1" >> "${PMSS_FAKE_SYSTEMD_RUN_LOG:?}"
    case "$1" in
        --scope) scope=1 ;;
        --slice=*) slice=${1#--slice=} ;;
        --) shift; break ;;
    esac
    shift
done
[ "$scope" = "1" ] || exit 71
[ "$slice" = "user-1001.slice" ] || exit 72
for arg in "$@"; do
    printf 'arg=%s\n' "$arg" >> "${PMSS_FAKE_SYSTEMD_RUN_LOG:?}"
done
PMSS_FAKE_CGROUP_SLICE="$slice" "$@"
SH
            ,
            'su' => <<<'SH'
#!/bin/sh
if [ "$1" = "-" ]; then
    shift
fi
if [ "$1" = "-s" ]; then
    shift 2
    [ "$1" = "-c" ] || exit 64
    shift
    command=$1
    shift
    user=$1
else
    user=$1
    shift
    [ "$1" = "-c" ] || exit 65
    shift
    command=$1
fi
PMSS_FAKE_USER="$user" /bin/sh -c "$command"
SH
            ,
            'storm' => <<<'SH'
#!/bin/sh
count=${1:-0}
root=${PMSS_FAKE_CGROUP_ROOT:?}
slice=${PMSS_FAKE_CGROUP_SLICE:-}
if [ "$slice" != "" ]; then
    dir="$root/pids/user.slice/$slice"
    cgroup="/pids/user.slice/$slice/process-storm.scope"
else
    dir="$root/pids/system.slice/cron.service"
    cgroup="/pids/system.slice/cron.service"
fi
current=$(cat "$dir/pids.current" 2>/dev/null || printf '%s\n' 0)
max=$(cat "$dir/pids.max" 2>/dev/null || printf '%s\n' max)
i=0
while [ "$i" -lt "$count" ]; do
    if [ "$max" != "max" ] && [ "$current" -ge "$max" ]; then
        printf 'fork denied in %s current=%s max=%s\n' "$cgroup" "$current" "$max" >> "$root/result.log"
        exit 73
    fi
    current=$((current + 1))
    printf '%s\n' "$current" > "$dir/pids.current"
    printf 'pid=%s.%s cgroup=%s\n' "$$" "$i" "$cgroup" >> "$root/pids.log"
    i=$((i + 1))
done
exit 0
SH
            ,
        ];
    }
}
