<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 3).'/motd/Generator.php';

class MotdTest extends TestCase
{
    public function testGeneratorLoadsVersionHelperForStandaloneUse(): void
    {
        $this->assertEquals('loaded', trim($this->pmssRunInlinePhpRequire(dirname(__DIR__, 3).'/motd/Generator.php', 'echo function_exists("getPmssVersion") ? "loaded" : "missing";', ['PMSS_TEST_MODE' => '1'])));
    }

    public function testGenerateMotdWritesToOutputWithTemplate(): void
    {
        $dir = $this->pmssMakeTempDir('pmss-motd-', 0700);
        $template = $dir.'/template.motd';
        $output   = $dir.'/motd.txt';
        $runtime  = $dir.'/run';
        @mkdir($runtime, 0700, true);

        file_put_contents($template, "Host: %HOSTNAME%\nVersion: %PMSS_VERSION%\n");

        $this->pmssWithEnv([
            'PMSS_MOTD_TEMPLATE_PATH' => $template,
            'PMSS_MOTD_OUTPUT_PATH' => $output,
            'PMSS_RUNTIME_DIR' => $runtime,
        ], function (): void {
            \Motd::motdGenerate();
        });

        $this->assertTrue(file_exists($output), 'MOTD output file missing');
        $content = (string)file_get_contents($output);
        $this->assertTrue(strpos($content, '%HOSTNAME%') === false, 'HOSTNAME placeholder should be replaced');
        $this->assertTrue(strpos($content, '%PMSS_VERSION%') === false, 'PMSS_VERSION placeholder should be replaced');
    }

    public function testGenerateMotdHonorsColorOptOutEnv(): void
    {
        $dir = $this->pmssMakeTempDir('pmss-motd-', 0700);
        $template = $dir.'/template.motd';
        $output   = $dir.'/motd.txt';
        $runtime  = $dir.'/run';
        @mkdir($runtime, 0700, true);

        file_put_contents($template, "Host: %HOSTNAME%\nKernel: %KERNEL_VERSION%\nNet: %NETWORK_SPEED%\n");

        $this->pmssWithEnv([
            'PMSS_MOTD_TEMPLATE_PATH' => $template,
            'PMSS_MOTD_OUTPUT_PATH' => $output,
            'PMSS_RUNTIME_DIR' => $runtime,
            'PMSS_MOTD_COLOR' => '0',
        ], function (): void {
            \Motd::motdGenerate();
        });

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
        $this->pmssAssertRepoFileContainsAllStrings('etc/seedbox/config/template.rc.local', [
            '/etc/seedbox/config/root.cron',
            '/etc/cron.d/pmss',
            '/scripts/util/motdGenerate.php',
        ]);
    }
}
