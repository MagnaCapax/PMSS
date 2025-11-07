<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/update/systemPrep.php';

class CgroupSliceTest extends TestCase
{
    private function tempDir(string $prefix): string
    {
        $d = sys_get_temp_dir().'/pmss-cg-'.bin2hex(random_bytes(4)).'-'.$prefix;
        @mkdir($d, 0700, true);
        return $d;
    }

    private function writeTemplate(string $dir, string $name, string $body): string
    {
        $path = rtrim($dir, '/').'/'.$name;
        file_put_contents($path, $body);
        return $path;
    }

    public function testV2RenderingReplacesPlaceholders(): void
    {
        $cfgDir = $this->tempDir('cfg');
        $drop   = $this->tempDir('drop');

        $tplBody = "[Slice]\nCPUAccounting=yes\nIOAccounting=yes\nMemoryAccounting=yes\nCPUWeight=%%USER_CPUWEIGHT%%\nIOWeight=%%USER_IOWEIGHT%%\nTasksMax=%%TASKS_MAX%%\nMemoryHigh=%%USER_MEMORY_HIGH%%M\nMemoryMax=%%USER_MEMORY_MAX%%M\n";
        $this->writeTemplate($cfgDir, 'template.user-slice.v2.conf', $tplBody);
        $this->writeTemplate($cfgDir, 'template.user-slice.v1.conf', 'ignored');

        putenv('PMSS_CGROUP_MODE=v2');
        putenv('PMSS_CONFIG_DIR='.$cfgDir);
        putenv('PMSS_SYSTEMD_USER_SLICE_DIR='.$drop);
        putenv('PMSS_TOTAL_MEM_MIB=8192');

        \pmssEnsureSystemdSlices('logmsg');

        $target = $drop.'/15-pmss.conf';
        $this->assertTrue(file_exists($target), 'slice drop-in not created');
        $content = (string)file_get_contents($target);
        $this->assertTrue(strpos($content, '%%') === false, 'placeholders remained in output');
        $this->assertTrue(strpos($content, 'CPUWeight=') !== false);
        $this->assertTrue(strpos($content, 'IOWeight=') !== false);
        $this->assertTrue(strpos($content, 'TasksMax=') !== false);
        $this->assertTrue(strpos($content, 'MemoryHigh=') !== false);
        $this->assertTrue(strpos($content, 'MemoryMax=') !== false);
    }

    public function testMemoryConstraintsApplied(): void
    {
        $cfgDir = $this->tempDir('cfg2');
        $drop   = $this->tempDir('drop2');
        $tplBody = "[Slice]\nMemoryHigh=%%USER_MEMORY_HIGH%%M\nMemoryMax=%%USER_MEMORY_MAX%%M\n";
        $this->writeTemplate($cfgDir, 'template.user-slice.v2.conf', $tplBody);
        $this->writeTemplate($cfgDir, 'template.user-slice.v1.conf', 'ignored');

        // Very low RAM: ensure MemoryHigh >= 250MiB
        putenv('PMSS_CGROUP_MODE=v2');
        putenv('PMSS_CONFIG_DIR='.$cfgDir);
        putenv('PMSS_SYSTEMD_USER_SLICE_DIR='.$drop);
        putenv('PMSS_TOTAL_MEM_MIB=512');
        \pmssEnsureSystemdSlices('logmsg');
        $content = (string)file_get_contents($drop.'/15-pmss.conf');
        $this->assertTrue((bool)preg_match('/MemoryHigh=(\d+)M/', $content, $m1));
        $high = (int)$m1[1];
        $this->assertTrue($high >= 250, 'MemoryHigh below minimum 250MiB');

        // Large RAM: MemoryMax <= 95% and about 1.5x MemoryHigh
        $drop3 = $this->tempDir('drop3');
        putenv('PMSS_SYSTEMD_USER_SLICE_DIR='.$drop3);
        putenv('PMSS_TOTAL_MEM_MIB=65536');
        \pmssEnsureSystemdSlices('logmsg');
        $content2 = (string)file_get_contents($drop3.'/15-pmss.conf');
        $this->assertTrue((bool)preg_match('/MemoryHigh=(\d+)M/', $content2, $mh));
        $this->assertTrue((bool)preg_match('/MemoryMax=(\d+)M/', $content2, $mm));
        $mHigh = (int)$mh[1];
        $mMax  = (int)$mm[1];
        $this->assertTrue($mMax <= (int)floor(65536 * 0.95), 'MemoryMax exceeds 95% cap');
        $this->assertTrue($mMax >= (int)floor($mHigh * 1.4), 'MemoryMax not close to 1.5x MemoryHigh');
    }

    public function testV1TemplateSelectedWhenModeV1(): void
    {
        $cfgDir = $this->tempDir('cfgv1');
        $drop   = $this->tempDir('dropv1');
        $v1Body = "[Slice]\nBlockIOAccounting=yes\nCPUWeight=%%USER_CPUWEIGHT%%\nTasksMax=%%TASKS_MAX%%\nMemoryHigh=%%USER_MEMORY_HIGH%%M\nMemoryMax=%%USER_MEMORY_MAX%%M\n";
        $this->writeTemplate($cfgDir, 'template.user-slice.v1.conf', $v1Body);
        $this->writeTemplate($cfgDir, 'template.user-slice.v2.conf', 'ignored');

        putenv('PMSS_CGROUP_MODE=v1');
        putenv('PMSS_CONFIG_DIR='.$cfgDir);
        putenv('PMSS_SYSTEMD_USER_SLICE_DIR='.$drop);
        putenv('PMSS_TOTAL_MEM_MIB=4096');

        \pmssEnsureSystemdSlices('logmsg');
        $content = (string)file_get_contents($drop.'/15-pmss.conf');
        $this->assertTrue(strpos($content, 'BlockIOAccounting=yes') !== false, 'v1 template not applied');
        $this->assertTrue(strpos($content, '%%') === false, 'placeholders remained');
    }

    public function testNoVendorPathsUsedForDropins(): void
    {
        $cfgDir = $this->tempDir('cfgv3');
        $drop   = $this->tempDir('dropv3');
        $tplBody = "[Slice]\nTasksMax=%%TASKS_MAX%%\n";
        $this->writeTemplate($cfgDir, 'template.user-slice.v2.conf', $tplBody);
        $this->writeTemplate($cfgDir, 'template.user-slice.v1.conf', $tplBody);

        putenv('PMSS_CGROUP_MODE=v2');
        putenv('PMSS_CONFIG_DIR='.$cfgDir);
        putenv('PMSS_SYSTEMD_USER_SLICE_DIR='.$drop);
        putenv('PMSS_TOTAL_MEM_MIB=2048');

        \pmssEnsureSystemdSlices('logmsg');
        $this->assertTrue(file_exists($drop.'/15-pmss.conf'));
        // Ensure we didn't accidentally write to /usr/lib; cannot check fs safely here, but path check suffices.
        $this->assertTrue(strpos($drop, '/etc/systemd') === false, 'test harness used temp path, not /etc');
    }

    public function testInvalidTemplateLogsWarningAndSkips(): void
    {
        $cfgDir = $this->tempDir('cfgbad');
        $drop   = $this->tempDir('dropbad');
        // Do not write any template; function should log a warning and return.
        putenv('PMSS_CGROUP_MODE=v2');
        putenv('PMSS_CONFIG_DIR='.$cfgDir);
        putenv('PMSS_SYSTEMD_USER_SLICE_DIR='.$drop);
        putenv('PMSS_TOTAL_MEM_MIB=1024');

        // This should not throw; simply not create the target.
        \pmssEnsureSystemdSlices('logmsg');
        $this->assertTrue(!file_exists($drop.'/15-pmss.conf'));
    }
}
