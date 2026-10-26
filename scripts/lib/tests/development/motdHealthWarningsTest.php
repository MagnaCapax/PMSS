<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 3).'/motd/Generator.php';

class MotdHealthWarningsTest extends TestCase
{
    public function testMalformedNestedHealthRecordsDoNotInterruptAcceptedWarnings(): void
    {
        $log = $this->pmssMakeJsonLogPath('pmss-motd-health-shape-', 'health.jsonl');
        $malformed = [
            ['kind' => 'smart', 'device' => []],
            ['kind' => 'smart', 'device' => ['name' => '/dev/sda']],
            ['kind' => 'nvme', 'device' => [], 'metrics' => ['critical_warnings' => 1]],
            ['kind' => 'nvme', 'device' => ['name' => '/dev/nvme0n1'], 'metrics' => ['critical_warnings' => 1]],
            ['kind' => 'nvme', 'metrics' => 'invalid'],
            ['kind' => 'nvme', 'metrics' => true],
            ['kind' => 'nvme', 'metrics' => ['critical_warnings' => [1]]],
            ['kind' => 'nvme', 'metrics' => ['critical_warnings' => ['value' => 1]]],
            ['kind' => 'raid', 'severity' => 'fail', 'array' => []],
            ['kind' => 'raid', 'severity' => 'fail', 'state' => []],
            ['kind' => 'raid', 'severity' => []],
            ['kind' => 'raid', 'severity' => 'fail', 'flags' => [['degraded']]],
            ['kind' => 'raid', 'severity' => 'fail', 'flags' => ['value' => ['degraded']]],
        ];
        foreach ($malformed as $entry) {
            file_put_contents($log, '');
            $this->pmssAppendFixtureLines($log, [
                ['kind' => 'raid', 'array' => 'md0', 'severity' => 'fail', 'flags' => ['degraded']],
                ['kind' => 'nvme', 'device' => '/dev/nvme0n1', 'metrics' => ['critical_warnings' => 1]],
                $entry,
                ['kind' => 'smart', 'device' => '/dev/sda', 'flags' => ['udma_crc_increase']],
            ]);
            // Also fail on warnings: malformed labels must never leak "Array" into the MOTD.
            set_error_handler(static function ($severity, $message, $file, $line): void {
                throw new \ErrorException($message, 0, $severity, $file, $line);
            });
            try {
                $this->pmssWithEnv(['PMSS_HEALTH_LOG_PATH' => $log], function (): void {
                    $this->assertSame('RAID md0: degraded | NVMe critical warning: /dev/nvme0n1 | SATA UDMA CRC increased: /dev/sda',
                        \pmssMotdStorageWarnings());
                });
            } finally {
                restore_error_handler();
            }
        }
    }

    public function testHealthProjectionRetainsOrderingDefaultsAndLatestSmartState(): void
    {
        $log = $this->pmssMakeJsonLogPath('pmss-motd-health-policy-', 'health.jsonl');
        $this->pmssAppendFixtureLines($log, [
            ['kind' => 'raid', 'array' => 'md0', 'severity' => 'fail', 'flags' => ['degraded']],
            ['kind' => 'raid', 'array' => 'md1', 'flags' => ['rebuild_in_progress']],
            ['kind' => 'raid', 'array' => 'md2', 'severity' => 'warn', 'state' => 'recovering'],
            ['kind' => 'nvme', 'device' => '/dev/nvme0n1', 'metrics' => ['critical_warnings' => '1']],
            ['kind' => 'nvme', 'device' => '/dev/nvme0n1', 'metrics' => ['critical_warnings' => 1]],
            ['kind' => 'nvme', 'metrics' => ['critical_warnings' => 1]],
            ['kind' => 'nvme', 'metrics' => null],
            ['kind' => 'nvme', 'metrics' => ['critical_warnings' => null]],
            ['kind' => 'smart', 'device' => '/dev/sda', 'flags' => ['udma_crc_increase']],
            ['kind' => 'smart', 'device' => '/dev/sdb', 'flags' => 'udma_crc_increase'],
            ['kind' => 'smart', 'device' => '/dev/sda', 'flags' => []],
        ]);
        $this->pmssWithEnv(['PMSS_HEALTH_LOG_PATH' => $log], function (): void {
            $this->assertSame('RAID md2: recovering | Performance limited: RAID md1 resync in progress | NVMe critical warning: /dev/nvme0n1, nvme | SATA UDMA CRC increased: /dev/sdb',
                \pmssMotdStorageWarnings());
        });
    }

    public function testMotdShowsStorageWarningsFromHealthLog(): void
    {
        $dir = $this->pmssMakeTempDir('pmss-motd-health-', 0700);
        $this->pmssWriteRelativeFile($dir, 'template.motd', "Host: %HOSTNAME%\n", 0700);
        $tpl = $dir.'/template.motd';
        $out = $dir.'/motd.txt';
        $log = $this->pmssMakeJsonLogPath('pmss-motd-health-log-', 'health.jsonl');

        // RAID degraded
        $this->pmssAppendFixtureLines($log, [
            ['timestamp'=>date('c'),'kind'=>'raid','array'=>'md0','level'=>'raid5','state'=>'active','detail'=>'[5/4] [UU_U]','severity'=>'fail','flags'=>['degraded'],'ok'=>false],
            ['timestamp'=>date('c'),'kind'=>'nvme','device'=>'/dev/nvme0n1','metrics'=>['critical_warnings'=>1]],
            ['timestamp'=>date('c'),'kind'=>'smart','device'=>'/dev/sda','flags'=>['udma_crc_increase']],
        ]);

        $this->pmssWithEnv([
            'PMSS_MOTD_TEMPLATE_PATH' => $tpl,
            'PMSS_MOTD_OUTPUT_PATH' => $out,
            'PMSS_HEALTH_LOG_PATH' => $log,
        ], function (): void {
            \Motd::motdGenerate();
        });
        $this->pmssAssertFileContainsAllStrings($out, [
            "\e[33mStorage WARN:\e[0m RAID md0: degraded | NVMe critical warning: /dev/nvme0n1 | SATA UDMA CRC increased: /dev/sda\n",
        ]);
    }

    public function testMotdHandlesMalformedHealthLogGracefully(): void
    {
        $dir = $this->pmssMakeTempDir('pmss-motd-health-bad-', 0700);
        $this->pmssWriteRelativeFile($dir, 'template.motd', "Hello %HOSTNAME%\n", 0700);
        $tpl = $dir.'/template.motd';
        $out = $dir.'/motd.txt';
        $log = $dir.'/health.jsonl';
        file_put_contents($log, "{this is not json}\n{\"kind\":\"nvme\",\"metrics\":{}}\nBROKEN\n");
        $this->pmssWithEnv([
            'PMSS_MOTD_TEMPLATE_PATH' => $tpl,
            'PMSS_MOTD_OUTPUT_PATH' => $out,
            'PMSS_HEALTH_LOG_PATH' => $log,
        ], function (): void {
            \Motd::motdGenerate();
        });
        $this->assertTrue(file_exists($out), 'MOTD not generated');
    }

    public function testMotdWithoutHealthLogHasNoStorageWarn(): void
    {
        $dir = $this->pmssMakeTempDir('pmss-motd-nohealth-', 0700);
        $this->pmssWriteRelativeFile($dir, 'template.motd', "Hello %HOSTNAME%\n", 0700);
        $tpl = $dir.'/template.motd';
        $out = $dir.'/motd.txt';
        $this->pmssWithEnv([
            'PMSS_MOTD_TEMPLATE_PATH' => $tpl,
            'PMSS_MOTD_OUTPUT_PATH' => $out,
            'PMSS_HEALTH_LOG_PATH' => $dir.'/missing.jsonl',
        ], function (): void {
            \Motd::motdGenerate();
        });
        $content = (string) @file_get_contents($out);
        $this->assertStringNotContainsString('Storage WARN:', $content, 'Storage WARN unexpectedly present');
    }
}
