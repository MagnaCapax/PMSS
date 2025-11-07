<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 3).'/motd/Generator.php';

class MotdHealthWarningsTest extends TestCase
{
    private function writeTemp(string $dir, string $name, string $content): string
    {
        $p = $dir.'/'.$name; @file_put_contents($p, $content); return $p;
    }

    public function testMotdShowsStorageWarningsFromHealthLog(): void
    {
        $dir = sys_get_temp_dir().'/pmss-motd-health-'.bin2hex(random_bytes(3));
        @mkdir($dir, 0700, true);
        $tpl = $this->writeTemp($dir, 'template.motd', "Host: %HOSTNAME%\n");
        $out = $dir.'/motd.txt';
        $log = $dir.'/health.jsonl';

        // RAID degraded
        file_put_contents($log, json_encode(['timestamp'=>date('c'),'kind'=>'raid','array'=>'md0','level'=>'raid5','state'=>'active','detail'=>'[5/4] [UU_U]','severity'=>'fail','ok'=>false])."\n", FILE_APPEND);
        // NVMe critical warning
        file_put_contents($log, json_encode(['timestamp'=>date('c'),'kind'=>'nvme','device'=>'/dev/nvme0n1','metrics'=>['critical_warnings'=>1]])."\n", FILE_APPEND);
        // SMART UDMA CRC increased
        file_put_contents($log, json_encode(['timestamp'=>date('c'),'kind'=>'smart','device'=>'/dev/sda','flags'=>['udma_crc_increase']])."\n", FILE_APPEND);

        putenv('PMSS_MOTD_TEMPLATE_PATH='.$tpl);
        putenv('PMSS_MOTD_OUTPUT_PATH='.$out);
        putenv('PMSS_HEALTH_LOG_PATH='.$log);

        \Motd::motdGenerate();
        $content = (string) @file_get_contents($out);
        $this->assertTrue(strpos($content, 'Storage WARN:') !== false, 'Storage WARN not shown');
        $this->assertTrue(strpos($content, 'RAID md0') !== false, 'RAID warn not shown');
        $this->assertTrue(strpos($content, 'NVMe critical warning') !== false, 'NVMe critical not shown');
        $this->assertTrue(strpos($content, 'UDMA CRC increased') !== false, 'UDMA CRC not shown');
    }

    public function testMotdHandlesMalformedHealthLogGracefully(): void
    {
        $dir = sys_get_temp_dir().'/pmss-motd-health-bad-'.bin2hex(random_bytes(3));
        @mkdir($dir, 0700, true);
        $tpl = $this->writeTemp($dir, 'template.motd', "Hello %HOSTNAME%\n");
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
        $dir = sys_get_temp_dir().'/pmss-motd-nohealth-'.bin2hex(random_bytes(3));
        @mkdir($dir, 0700, true);
        $tpl = $this->writeTemp($dir, 'template.motd', "Hello %HOSTNAME%\n");
        $out = $dir.'/motd.txt';
        putenv('PMSS_MOTD_TEMPLATE_PATH='.$tpl);
        putenv('PMSS_MOTD_OUTPUT_PATH='.$out);
        putenv('PMSS_HEALTH_LOG_PATH='.$dir.'/missing.jsonl');
        \Motd::motdGenerate();
        $content = (string) @file_get_contents($out);
        $this->assertTrue(strpos($content, 'Storage WARN:') === false, 'Storage WARN unexpectedly present');
    }
}

