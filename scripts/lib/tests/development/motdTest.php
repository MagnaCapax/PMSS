<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 3).'/motd/Generator.php';

class MotdTest extends TestCase
{
    public function testGenerateMotdWritesToOutputWithTemplate(): void
    {
        $dir = sys_get_temp_dir().'/pmss-motd-'.bin2hex(random_bytes(4));
        @mkdir($dir, 0700, true);
        $template = $dir.'/template.motd';
        $output   = $dir.'/motd.txt';
        $runtime  = $dir.'/run';
        @mkdir($runtime, 0700, true);

        file_put_contents($template, "Host: %HOSTNAME%\nVersion: %PMSS_VERSION%\n");

        putenv('PMSS_MOTD_TEMPLATE_PATH='.$template);
        putenv('PMSS_MOTD_OUTPUT_PATH='.$output);
        putenv('PMSS_RUNTIME_DIR='.$runtime);

        \Motd::motdGenerate();

        $this->assertTrue(file_exists($output), 'MOTD output file missing');
        $content = (string)file_get_contents($output);
        $this->assertTrue(strpos($content, '%HOSTNAME%') === false, 'HOSTNAME placeholder should be replaced');
        $this->assertTrue(strpos($content, '%PMSS_VERSION%') === false, 'PMSS_VERSION placeholder should be replaced');
    }

    public function testGenerateMotdHonorsColorOptOutEnv(): void
    {
        $dir = sys_get_temp_dir().'/pmss-motd-'.bin2hex(random_bytes(4));
        @mkdir($dir, 0700, true);
        $template = $dir.'/template.motd';
        $output   = $dir.'/motd.txt';
        $runtime  = $dir.'/run';
        @mkdir($runtime, 0700, true);

        file_put_contents($template, "Host: %HOSTNAME%\nKernel: %KERNEL_VERSION%\nNet: %NETWORK_SPEED%\n");

        putenv('PMSS_MOTD_TEMPLATE_PATH='.$template);
        putenv('PMSS_MOTD_OUTPUT_PATH='.$output);
        putenv('PMSS_RUNTIME_DIR='.$runtime);
        putenv('PMSS_MOTD_COLOR=0');

        \Motd::motdGenerate();

        $this->assertTrue(file_exists($output), 'MOTD output file missing');
        $content = (string) file_get_contents($output);
        $this->assertTrue(strpos($content, "\e[") === false, 'MOTD color opt-out should suppress ANSI escapes');
    }

    public function testRenderMotdTemplateDefaultsMissingModelValues(): void
    {
        $rendered = \Motd::renderMotdTemplate(
            'Host:%HOSTNAME% Kernel:%KERNEL_VERSION% WG:%WIREGUARD_STATUS% OVPN:%OPENVPN_STATUS%',
            ['host' => 123, 'kernel' => 456],
            false
        );

        $this->assertEquals('Host:123 Kernel:456 WG: OVPN:', $rendered);
    }

    public function testUpdateStep2CallsGenerateMotdNearEnd(): void
    {
        $path = dirname(__DIR__, 3).'/util/update-step2.php';
        $this->assertTrue(file_exists($path), 'update-step2.php missing');
        $lines = file($path, FILE_IGNORE_NEW_LINES);
        $this->assertTrue(is_array($lines) && count($lines) > 0, 'unable to read update-step2.php');
        $source = implode("\n", $lines);
        $lastIdx = -1;
        $updatedIdx = -1;
        $cronIdx = -1;
        foreach ($lines as $i => $line) {
            if (
                strpos($line, 'Motd::motdGenerate(') !== false
                || strpos($line, "pmssRunProfiledCallable('Refreshing MOTD'") !== false
            ) {
                $lastIdx = $i;
            }
            if (strpos($line, "/var/run/pmss/updated") !== false) {
                $updatedIdx = $i;
            }
            if (strpos($line, '/scripts/util/setupRootCron.php') !== false) {
                $cronIdx = $i;
            }
        }
        $this->assertTrue($lastIdx >= 0, 'MOTD refresh is not referenced in update-step2.php');
        $this->assertTrue($updatedIdx >= 0, 'update-step2.php should record /var/run/pmss/updated');
        $this->assertTrue($cronIdx >= 0, 'update-step2.php should restore root cron via setupRootCron.php');
        $this->assertTrue(strpos($source, "pmssWriteManagedPathFile('/var/run/pmss/updated'") !== false, 'update-step2.php should use shared managed-path writes for /var/run/pmss/updated');
        $this->assertTrue($updatedIdx < $lastIdx, '/var/run/pmss/updated should be written before MOTD refresh');
        $this->assertTrue($cronIdx < $lastIdx, 'Root cron should be restored before MOTD refresh');
        $total = count($lines);
        // Expect the last call to appear within the last 50 lines of the script.
        $this->assertTrue(($total - $lastIdx) <= 50, 'MOTD refresh should be near the end of update-step2.php');
    }

    public function testRcLocalRestoresCronAndGeneratesMotdAtBoot(): void
    {
        $path = $this->pmssRepoPath('etc/seedbox/config/template.rc.local');
        $this->assertTrue(file_exists($path), 'template.rc.local missing');
        $data = $this->pmssReadRepoFile('etc/seedbox/config/template.rc.local');
        $this->assertTrue(strpos($data, '/etc/seedbox/config/root.cron') !== false, 'rc.local should reference root.cron template');
        $this->assertTrue(strpos($data, '/etc/cron.d/pmss') !== false, 'rc.local should restore /etc/cron.d/pmss');
        $this->assertTrue(strpos($data, '/scripts/util/motdGenerate.php') !== false, 'rc.local should generate MOTD at boot');
    }
}
