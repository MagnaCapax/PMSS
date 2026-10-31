<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

class RootCronIoPriorityScopeTest extends TestCase
{
    /** Account-workload demotions; root's own copies of these must keep their class. */
    private const ACCOUNT_ONLY = ['php', 'rar', 'unrar', 'tar', 'mktorrent', 'sftp-server', 'cp', 'mono', 'ffmpeg', 'rclone'];

    /**
     * @return array<string, array<int, string>> process name => scheduling lines
     */
    private function schedulingLines(): array
    {
        $lines = [];
        foreach (explode("\n", $this->pmssReadRepoFile('etc/seedbox/config/root.cron')) as $line) {
            if (preg_match('/^\* \* \* \* \*\s+root ps -o \S+ -C (\S+?)[ |].*\b(ionice|renice)\b/', $line, $m) === 1) {
                $lines[$m[1]][] = $line;
            }
        }
        return $lines;
    }

    public function testAccountWorkloadDemotionsSkipRootAndSystemProcesses(): void
    {
        $lines = $this->schedulingLines();
        foreach (self::ACCOUNT_ONLY as $name) {
            $this->assertTrue(isset($lines[$name]), "root.cron should still schedule {$name}");
            foreach ($lines[$name] as $line) {
                // Shape only; what each filter lets through is checked by running it below.
                $this->assertStringContainsString("ps -o pid=,uid= -C {$name} | awk '", $line);
                $this->assertStringContainsString("|xargs -I'{1}' ", $line);
            }
        }
    }

    public function testUidFilterOnEveryDemotionLinePassesOnlyAccountPids(): void
    {
        if (!is_executable('/usr/bin/awk') && !is_executable('/bin/awk')) {
            throw new SkipTest('awk is not available');
        }
        // root, www-data, the last system uid, the first and a later account uid.
        $fixture = escapeshellarg("  101     0\n  102    33\n  103   999\n  104  1000\n  105 100999\n");
        $checked = 0;
        $lines = $this->schedulingLines();
        foreach (self::ACCOUNT_ONLY as $name) {
            foreach ($lines[$name] ?? [] as $line) {
                $this->assertSame(1, preg_match("/\\| awk '([^']+)' \\|/", $line, $m), "no awk stage in: {$line}");
                $this->assertSame("104\n105\n", $this->pmssRunShellCommand('printf '.$fixture.' | awk '.escapeshellarg($m[1]), [], ''), "filter in: {$line}");
                ++$checked;
            }
        }
        $this->assertSame(12, $checked);
    }

    public function testPromotionsAndInheritedOrContainmentRulesKeepTheirScope(): void
    {
        $lines = $this->schedulingLines();
        foreach (['rtorrent', 'deluged', 'qbittorrent-nox', 'proftpd', 'xmrig'] as $name) {
            $this->assertEquals(1, count($lines[$name] ?? []), "root.cron should keep one {$name} line");
            $this->assertStringContainsString("ps -o pid= -C {$name}|xargs -I'{1}' ", $lines[$name][0]);
        }
        $this->assertStringContainsString("ps -o pid=,uid= -C bash | awk '\$2==0{print \$1}' |", $lines['bash'][0]);
    }
}
