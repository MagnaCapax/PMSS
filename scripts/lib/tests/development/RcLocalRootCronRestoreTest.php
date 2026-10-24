<?php
/**
 * Hermetic coverage for the rc.local block that restores /etc/cron.d/pmss
 * at boot and runs its @reboot entries when cron started without it.
 */

namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

class RcLocalRootCronRestoreTest extends TestCase
{
    private $dir;

    protected function setUp(): void
    {
        $this->pmssAssignTempDirProperty('dir', 'pmss-rc-local-cron', 0700);
        @mkdir($this->dir.'/bin', 0700, true);
        @mkdir($this->dir.'/cron.d', 0700, true);

        $this->pmssWriteExecutableFile($this->dir.'/bin/systemctl', <<<'BASH'
#!/bin/bash
case "$1" in
    is-active) [ "$STUB_CRON_ACTIVE" = 1 ]; exit $? ;;
    is-system-running) echo "$STUB_SYSTEM_STATE" ;;
esac
exit 0
BASH
        , 0700);

        file_put_contents($this->dir.'/root.cron', implode("\n", [
            'PATH=/usr/sbin:/usr/bin:/sbin:/bin',
            'SHELL=/bin/bash',
            '* * * * * root echo minute >> '.$this->dir.'/ran',
            '@reboot root    echo first >> '.$this->dir.'/ran',
            '@reboot root    sleep 0; echo second >> '.$this->dir.'/ran',
            '',
        ]));
    }

    public function testRecoveryBootRunsOnlyRootCronRebootEntries(): void
    {
        $this->runBlock(false, true, 'starting');

        $this->assertSame(file_get_contents($this->dir.'/root.cron'), @file_get_contents($this->dir.'/cron.d/pmss'));
        $this->assertSame(['first', 'second'], $this->ranLines(2));
    }

    public function testUpdateStep2RunAfterBootDoesNotStartRebootJobs(): void
    {
        $this->runBlock(false, true, 'running');

        $this->assertSame(file_get_contents($this->dir.'/root.cron'), @file_get_contents($this->dir.'/cron.d/pmss'));
        $this->assertSame([], $this->ranLines(0));
    }

    public function testNormalBootWithCronFilePresentLeavesJobsToCron(): void
    {
        copy($this->dir.'/root.cron', $this->dir.'/cron.d/pmss');
        $this->runBlock(true, true, 'starting');

        $this->assertSame([], $this->ranLines(0));
    }

    public function testCronNotStartedYetReadsTheRestoredFileItself(): void
    {
        $this->runBlock(false, false, 'starting');

        $this->assertSame(file_get_contents($this->dir.'/root.cron'), @file_get_contents($this->dir.'/cron.d/pmss'));
        $this->assertSame([], $this->ranLines(0));
    }

    private function runBlock(bool $cronFilePresent, bool $cronActive, string $systemState): void
    {
        $this->assertSame($cronFilePresent, is_file($this->dir.'/cron.d/pmss'));
        $source = $this->pmssReadRepoFile('etc/seedbox/config/template.rc.local');
        $start = strpos($source, '# If an update run removed `/etc/cron.d/pmss` mid-flight');
        $end = strpos($source, '# Refresh MOTD at boot');
        $this->assertTrue($start !== false && $end !== false && $start < $end);
        $block = str_replace(
            ['/etc/seedbox/config/root.cron', '/etc/cron.d/pmss'],
            [$this->dir.'/root.cron', $this->dir.'/cron.d/pmss'],
            substr($source, $start, $end - $start)
        );

        $this->pmssRunShellHarness(implode("\n", [
            '#!/bin/bash',
            'set -u',
            'export PATH='.escapeshellarg($this->dir.'/bin').':"$PATH"',
            'export STUB_CRON_ACTIVE='.($cronActive ? '1' : '0'),
            'export STUB_SYSTEM_STATE='.escapeshellarg($systemState),
            $block,
            '',
        ]));
    }

    /** Jobs run in the background; wait for the expected count, then a little longer for strays. */
    private function ranLines(int $expected): array
    {
        $lines = [];
        for ($i = 0; $i < 50; $i++) {
            usleep(100000);
            $lines = is_file($this->dir.'/ran') ? file($this->dir.'/ran', FILE_IGNORE_NEW_LINES) : [];
            if ($expected > 0 && count($lines) >= $expected) {
                break;
            }
            if ($expected === 0 && $i >= 5) {
                break;
            }
        }
        sort($lines);
        return $lines;
    }
}
