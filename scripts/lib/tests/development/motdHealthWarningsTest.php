<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 3).'/motd/Generator.php';

class MotdHealthWarningsTest extends TestCase
{
    public function testMotdShowsStorageWarningsFromHealthLog(): void
    {
        $dir = $this->pmssMakeTempDir('pmss-motd-health-', 0700);
        $this->pmssWriteRelativeFile($dir, 'template.motd', "Host: %HOSTNAME%\n", 0700);
        $tpl = $dir.'/template.motd';
        $out = $dir.'/motd.txt';
        $log = $this->pmssMakeJsonLogPath('pmss-motd-health-log-', 'health.jsonl');

        // RAID degraded
        $this->pmssAppendFixtureLines($log, [
            ['timestamp'=>date('c'),'kind'=>'raid','array'=>'md0','level'=>'raid5','state'=>'active','detail'=>'[5/4] [UU_U]','severity'=>'fail','ok'=>false],
            ['timestamp'=>date('c'),'kind'=>'nvme','device'=>'/dev/nvme0n1','metrics'=>['critical_warnings'=>1]],
            ['timestamp'=>date('c'),'kind'=>'smart','device'=>'/dev/sda','flags'=>['udma_crc_increase']],
        ]);

        putenv('PMSS_MOTD_TEMPLATE_PATH='.$tpl);
        putenv('PMSS_MOTD_OUTPUT_PATH='.$out);
        putenv('PMSS_HEALTH_LOG_PATH='.$log);

        \Motd::motdGenerate();
        $content = (string) @file_get_contents($out);
        $this->assertStringContainsAllStrings(['Storage WARN:', 'RAID md0', 'NVMe critical warning', 'UDMA CRC increased'], $content);
    }

    public function testMotdHandlesMalformedHealthLogGracefully(): void
    {
        $dir = $this->pmssMakeTempDir('pmss-motd-health-bad-', 0700);
        $this->pmssWriteRelativeFile($dir, 'template.motd', "Hello %HOSTNAME%\n", 0700);
        $tpl = $dir.'/template.motd';
        $out = $dir.'/motd.txt';
        $log = $dir.'/health.jsonl';
        file_put_contents($log, "{this is not json}\n{\"kind\":\"nvme\",\"metrics\":{}}\nBROKEN\n");
        putenv('PMSS_MOTD_TEMPLATE_PATH='.$tpl);
        putenv('PMSS_MOTD_OUTPUT_PATH='.$out);
        putenv('PMSS_HEALTH_LOG_PATH='.$log);
        \Motd::motdGenerate();
        $this->assertTrue(file_exists($out), 'MOTD not generated');
    }

    public function testMotdWithoutHealthLogHasNoStorageWarn(): void
    {
        $dir = $this->pmssMakeTempDir('pmss-motd-nohealth-', 0700);
        $this->pmssWriteRelativeFile($dir, 'template.motd', "Hello %HOSTNAME%\n", 0700);
        $tpl = $dir.'/template.motd';
        $out = $dir.'/motd.txt';
        putenv('PMSS_MOTD_TEMPLATE_PATH='.$tpl);
        putenv('PMSS_MOTD_OUTPUT_PATH='.$out);
        putenv('PMSS_HEALTH_LOG_PATH='.$dir.'/missing.jsonl');
        \Motd::motdGenerate();
        $content = (string) @file_get_contents($out);
        $this->assertStringNotContainsString('Storage WARN:', $content, 'Storage WARN unexpectedly present');
    }
}
